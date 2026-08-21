<?php
declare(strict_types=1);

namespace SwiftLift\OAuth;

use SwiftLift\Config;

/**
 * <summary>
 * Google Sign-In (OAuth 2.0 / OpenID Connect) server-side flow
 * helpers. Mirrors the FacebookProvider shape so the route handler
 * can treat them interchangeably.
 * </summary>
 * <remarks>
 * Usage:
 *
 *   1. isConfigured() returns true once both client_id and
 *      client_secret are configured (env vars GOOGLE_CLIENT_ID /
 *      GOOGLE_CLIENT_SECRET or config/oauth.php). The login button
 *      hides on unconfigured installs.
 *   2. authorizeUrl() builds the URL to redirect the user to.
 *   3. exchangeCode() swaps the ?code=... callback for an access
 *      token (Google uses POST with a form-encoded body, unlike
 *      Facebook's GET).
 *   4. profile() fetches sub, name and email from the userinfo
 *      endpoint and re-keys "sub" as "id" so the caller can treat
 *      every provider identically.
 *
 * Setup (Google Cloud Console, APIs and Services, Credentials):
 *
 *   * Create an "OAuth 2.0 Client ID" of type "Web application".
 *   * Authorised redirect URI:
 *       https://swiftlift.gg/api/index.php?p=auth/oauth/google/callback
 *   * Copy the client id and secret into config/oauth.php or env.
 * </remarks>
 */
final class GoogleProvider
{
    /**
     * <summary>
     * True when both Google client id and secret are configured (via
     * env or config/oauth.php).
     * </summary>
     */
    public static function isConfigured(): bool
    {
        return self::clientId() !== null && self::clientSecret() !== null;
    }

    /**
     * <summary>
     * Read the configured Google client id, or null when unset.
     * </summary>
     */
    private static function clientId(): ?string
    {
        $v = Config::get('oauth', 'google.client_id');
        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * <summary>
     * Read the configured Google client secret, or null when unset.
     * </summary>
     */
    private static function clientSecret(): ?string
    {
        $v = Config::get('oauth', 'google.client_secret');
        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * <summary>
     * Build the Google authorisation URL to redirect the user's
     * browser to, requesting openid, email and profile scopes plus
     * the account-picker prompt.
     * </summary>
     * <param name="state">CSRF / round-trip nonce. The caller is responsible for storing it in the session.</param>
     * <param name="redirectUri">The full URL Google should send the user back to.</param>
     * <returns>An absolute URL to redirect the user's browser to.</returns>
     */
    public static function authorizeUrl(string $state, string $redirectUri): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id'     => self::clientId(),
            'redirect_uri'  => $redirectUri,
            'state'         => $state,
            // openid + email + profile = the standard "sign in with Google" set.
            // OpenID gives us the stable `sub` user id; email/profile fill in
            // address + display name.
            'scope'         => 'openid email profile',
            'response_type' => 'code',
            'access_type'   => 'online',
            'prompt'        => 'select_account',
        ]);
    }

    /**
     * <summary>
     * Exchange an authorisation code for a Google access token via the
     * OAuth 2 token endpoint.
     * </summary>
     * <param name="code">The code returned by Google on the redirect.</param>
     * <param name="redirectUri">Must exactly match the redirect URI used in authorizeUrl().</param>
     * <returns>The access token string.</returns>
     * <exception cref="\RuntimeException">If Google responds without an access token.</exception>
     */
    public static function exchangeCode(string $code, string $redirectUri): string
    {
        $body = self::httpPost('https://oauth2.googleapis.com/token', [
            'code'          => $code,
            'client_id'     => self::clientId(),
            'client_secret' => self::clientSecret(),
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
        ]);
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new \RuntimeException('Google did not return an access token');
        }
        return (string) $data['access_token'];
    }

    /**
     * <summary>
     * Fetch the Google profile (id, optional name, optional email,
     * email_verified) for a given access token from the OpenID
     * userinfo endpoint.
     * </summary>
     * <param name="accessToken">The token returned by exchangeCode().</param>
     * <returns>An associative array with at least an "id" key, derived from Google's "sub".</returns>
     * <exception cref="\RuntimeException">If the userinfo endpoint does not return a usable profile.</exception>
     * <remarks>
     * Google's userinfo response uses "sub" for the stable id; we
     * re-key it as "id" so the route handler can treat all providers
     * identically.
     * </remarks>
     * @return array{id:string, name?:string, email?:string, email_verified?:bool}
     */
    public static function profile(string $accessToken): array
    {
        $body = self::httpGet(
            'https://openidconnect.googleapis.com/v1/userinfo',
            ['Authorization: Bearer ' . $accessToken]
        );
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['sub'])) {
            throw new \RuntimeException('Could not fetch Google profile');
        }
        return [
            'id'             => (string) $data['sub'],
            'name'           => isset($data['name'])  ? (string) $data['name']  : null,
            'email'          => isset($data['email']) ? (string) $data['email'] : null,
            'email_verified' => !empty($data['email_verified']),
        ];
    }

    /**
     * <summary>
     * Issue an HTTP GET with optional headers, preferring cURL when
     * available and falling back to file_get_contents with a stream
     * context otherwise.
     * </summary>
     * <param name="url">The fully-built URL to fetch.</param>
     * <param name="headers">Optional list of raw header lines.</param>
     * <returns>The response body as a string.</returns>
     * <exception cref="\RuntimeException">On any HTTP failure or non-2xx status.</exception>
     */
    private static function httpGet(string $url, array $headers = []): string
    {
        if (!function_exists('curl_init')) {
            $ctx = stream_context_create(['http' => [
                'method'        => 'GET',
                'header'        => implode("\r\n", $headers),
                'timeout'       => 10,
                'user_agent'    => 'SwiftLift/1.0',
                'ignore_errors' => true,
            ]]);
            $body = @file_get_contents($url, false, $ctx);
            if ($body === false) throw new \RuntimeException('HTTP request failed');
            return $body;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'SwiftLift/1.0',
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false)             throw new \RuntimeException("HTTP request failed: $err");
        if ($code < 200 || $code >= 300) throw new \RuntimeException("HTTP $code from Google: " . substr((string) $body, 0, 200));
        return (string) $body;
    }

    /**
     * <summary>
     * Issue an HTTP POST with a form-encoded body, preferring cURL
     * when available and falling back to file_get_contents with a
     * stream context otherwise.
     * </summary>
     * <param name="url">The endpoint to POST to.</param>
     * <param name="form">Associative array of form fields to send.</param>
     * <returns>The response body as a string.</returns>
     * <exception cref="\RuntimeException">On any HTTP failure or non-2xx status.</exception>
     */
    private static function httpPost(string $url, array $form): string
    {
        $payload = http_build_query($form);
        if (!function_exists('curl_init')) {
            $ctx = stream_context_create(['http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: SwiftLift/1.0",
                'content'       => $payload,
                'timeout'       => 10,
                'ignore_errors' => true,
            ]]);
            $body = @file_get_contents($url, false, $ctx);
            if ($body === false) throw new \RuntimeException('HTTP request failed');
            return $body;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'SwiftLift/1.0',
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false)             throw new \RuntimeException("HTTP request failed: $err");
        if ($code < 200 || $code >= 300) throw new \RuntimeException("HTTP $code from Google: " . substr((string) $body, 0, 200));
        return (string) $body;
    }
}
