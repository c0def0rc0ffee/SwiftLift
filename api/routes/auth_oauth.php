<?php
declare(strict_types=1);

use SwiftLift\{Auth, AuthMessageException, Http, UserRepo};
use SwiftLift\OAuth\{FacebookProvider, GoogleProvider};

/**
 * <summary>
 * /api/auth/oauth/* router. Lists configured providers and handles the start
 * and callback legs of the Google and Facebook OAuth flows.
 * </summary>
 * <remarks>
 * Each provider has a tiny state machine. The "start" leg generates a
 * random state nonce, stores it in the session, and 302s to the
 * provider's authorisation URL. The "callback" leg validates the state
 * with a constant time compare, swaps the authorisation code for an
 * access token, fetches the user profile, and either signs in an
 * existing linked account or creates a new one. Any failure bounces
 * the user back to the SPA's homepage with an ?oauth_error query
 * parameter; success bounces with ?oauth_ok=1 so the UI can flash a
 * confirmation toast.
 * </remarks>
 */

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$tail   = $_GET['_tail'] ?? '';

/**
 * <summary>
 * Build the absolute redirect URI for the given provider's callback.
 * </summary>
 * <param name="provider">The provider key, "google" or "facebook".</param>
 * <returns>The fully qualified callback URL that the provider will be told to redirect to.</returns>
 * <remarks>
 * The query string form of the route is used so the URI is identical
 * whether or not .htaccess rewrites are functioning, which simplifies
 * provider configuration across staging and production.
 * </remarks>
 */
function oauthRedirectUri(string $provider): string {
    return Http::appBaseUrl() . '/api/index.php?p=auth/oauth/' . $provider . '/callback';
}

/**
 * <summary>
 * GET /api/auth/oauth/providers. Reports which OAuth buttons should be visible.
 * </summary>
 * <remarks>
 * Returns booleans rather than secrets so the SPA can decide whether
 * to render the Google and Facebook sign in buttons without ever
 * seeing the configuration values themselves.
 * </remarks>
 */
if ($method === 'GET' && $tail === 'providers') {
    Http::json([
        'facebook' => FacebookProvider::isConfigured(),
        'google'   => GoogleProvider::isConfigured(),
    ]);
}

/**
 * <summary>
 * GET /api/auth/oauth/google/start. Kicks off the Google sign in flow.
 * </summary>
 * <remarks>
 * Generates a 16 byte hex state nonce, stores it in the session, and
 * redirects to Google's authorisation URL. Refuses with a 503 if the
 * provider has not been configured.
 * </remarks>
 */
if ($method === 'GET' && $tail === 'google/start') {
    if (!GoogleProvider::isConfigured()) {
        Http::error('Google login is not configured on this server', 503);
    }
    Auth::start();
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;

    header('Location: ' . GoogleProvider::authorizeUrl($state, oauthRedirectUri('google')));
    exit;
}

/**
 * <summary>
 * GET /api/auth/oauth/google/callback. Completes the Google sign in flow.
 * </summary>
 * <remarks>
 * Validates the state nonce with hash_equals, exchanges the
 * authorisation code for a token, pulls the user profile, and either
 * links to an existing account or creates a new one. Google verifies
 * email addresses for most accounts; an unverified profile email is
 * treated as if none had been returned so we never trust an address
 * the user could not actually receive mail at. Errors are reported by
 * redirecting back to the SPA with ?oauth_error, never as a raw error
 * page, so the user always lands somewhere usable.
 * </remarks>
 */
if ($method === 'GET' && $tail === 'google/callback') {
    Auth::start();
    $state         = (string) ($_GET['state'] ?? '');
    $expectedState = (string) ($_SESSION['oauth_state'] ?? '');
    unset($_SESSION['oauth_state']);

    $base = Http::appBaseUrl();
    /**
     * <summary>
     * Bounce the user back to the homepage with a single query parameter.
     * </summary>
     * <param name="param">The query parameter name, typically "oauth_error".</param>
     * <param name="value">The value to URL encode and send along.</param>
     * <returns>Never returns: emits Location and exits.</returns>
     * <remarks>
     * Used to short circuit all error paths in the callback. Marked
     * never so the type checker understands control does not flow on.
     * </remarks>
     */
    $bounce = static function (string $param, string $value) use ($base): never {
        header('Location: ' . $base . '/?' . $param . '=' . urlencode($value));
        exit;
    };

    if ($state === '' || !hash_equals($expectedState, $state)) $bounce('oauth_error', 'Invalid state. Please try again.');
    // The provider's error/error_description fields are attacker-influenceable
    // (anyone can craft a callback URL), so don't reflect them into the SPA
    // URL where they'd be shown to the user, that's a phishing vector. Log
    // the detail server-side and bounce with a fixed, safe message.
    if (isset($_GET['error'])) {
        error_log('[SwiftLift] Google OAuth provider error: ' . (string) ($_GET['error_description'] ?? $_GET['error']));
        $bounce('oauth_error', 'Sign in was cancelled or failed. Please try again.');
    }

    $code = (string) ($_GET['code'] ?? '');
    if ($code === '') $bounce('oauth_error', 'Missing authorization code');

    try {
        $token   = GoogleProvider::exchangeCode($code, oauthRedirectUri('google'));
        $profile = GoogleProvider::profile($token);
        $email   = (!empty($profile['email_verified']) && !empty($profile['email']))
                 ? (string) $profile['email']
                 : null;
        $userId  = UserRepo::findOrCreateOauthUser(
            'google',
            (string) $profile['id'],
            $email,
            (string) ($profile['name'] ?? 'New passenger'),
            // $email is non-null only when Google asserted email_verified
            // just above, so a non-null email here is a verified one.
            $email !== null
        );
        Auth::login($userId);
    } catch (AuthMessageException $e) {
        // Deliberately user-facing (account suspended, email already in
        // use). These are written to be read by the person signing in.
        $bounce('oauth_error', $e->getMessage());
    } catch (\Throwable $e) {
        // Log the real cause; bounce with a generic message so internal
        // exception text never lands in the user-visible URL.
        error_log('[SwiftLift] Google OAuth failed: ' . $e->getMessage());
        $bounce('oauth_error', 'Sign in failed. Please try again.');
    }

    header('Location: ' . $base . '/?oauth_ok=1');
    exit;
}

/**
 * <summary>
 * GET /api/auth/oauth/facebook/start. Kicks off the Facebook sign in flow.
 * </summary>
 * <remarks>
 * Mirror of the Google start leg: generates a state nonce, stores it
 * in the session, and redirects to Facebook's authorisation URL.
 * Refuses with a 503 if the provider is not configured.
 * </remarks>
 */
if ($method === 'GET' && $tail === 'facebook/start') {
    if (!FacebookProvider::isConfigured()) {
        Http::error('Facebook login is not configured on this server', 503);
    }
    Auth::start();
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;

    header('Location: ' . FacebookProvider::authorizeUrl($state, oauthRedirectUri('facebook')));
    exit;
}

/**
 * <summary>
 * GET /api/auth/oauth/facebook/callback. Completes the Facebook sign in flow.
 * </summary>
 * <remarks>
 * Same shape as the Google callback. Facebook does not expose a
 * separate email_verified flag, so the address is trusted when
 * present. Errors bounce back to the SPA with ?oauth_error; success
 * lands on the homepage with ?oauth_ok=1 so the UI can flash a toast.
 * </remarks>
 */
if ($method === 'GET' && $tail === 'facebook/callback') {
    Auth::start();
    $state         = (string) ($_GET['state'] ?? '');
    $expectedState = (string) ($_SESSION['oauth_state'] ?? '');
    unset($_SESSION['oauth_state']);

    $base = Http::appBaseUrl();
    /**
     * <summary>
     * Bounce the user back to the homepage with a single query parameter.
     * </summary>
     * <param name="param">The query parameter name, typically "oauth_error".</param>
     * <param name="value">The value to URL encode and send along.</param>
     * <returns>Never returns: emits Location and exits.</returns>
     * <remarks>
     * Same shape as in the Google callback above.
     * </remarks>
     */
    $bounce = static function (string $param, string $value) use ($base): never {
        header('Location: ' . $base . '/?' . $param . '=' . urlencode($value));
        exit;
    };

    if ($state === '' || !hash_equals($expectedState, $state)) $bounce('oauth_error', 'Invalid state. Please try again.');
    // See the Google leg above: the provider error fields are attacker-
    // influenceable, so log them and bounce with a fixed safe message
    // rather than reflecting them into the user-visible SPA URL.
    if (isset($_GET['error'])) {
        error_log('[SwiftLift] Facebook OAuth provider error: ' . (string) ($_GET['error_description'] ?? $_GET['error']));
        $bounce('oauth_error', 'Sign in was cancelled or failed. Please try again.');
    }

    $code = (string) ($_GET['code'] ?? '');
    if ($code === '') $bounce('oauth_error', 'Missing authorization code');

    try {
        $token   = FacebookProvider::exchangeCode($code, oauthRedirectUri('facebook'));
        $profile = FacebookProvider::profile($token);
        $userId  = UserRepo::findOrCreateOauthUser(
            'facebook',
            (string) $profile['id'],
            $profile['email'] ?? null,
            (string) ($profile['name'] ?? 'New passenger'),
            // The Graph /me edge exposes no email_verified equivalent, so
            // we cannot assert this address belongs to the person signing
            // in. Passing false means it is stored and used for contact,
            // but never links into an existing account and never counts as
            // verified, SwiftLift's own verification email settles that.
            false
        );
        Auth::login($userId);
    } catch (AuthMessageException $e) {
        // Deliberately user-facing (account suspended, email already in
        // use). These are written to be read by the person signing in.
        $bounce('oauth_error', $e->getMessage());
    } catch (\Throwable $e) {
        // Log the real cause; bounce with a generic message so internal
        // exception text never lands in the user-visible URL.
        error_log('[SwiftLift] Facebook OAuth failed: ' . $e->getMessage());
        $bounce('oauth_error', 'Sign in failed. Please try again.');
    }

    header('Location: ' . $base . '/?oauth_ok=1');
    exit;
}

Http::error('Not found', 404);
