<?php
declare(strict_types=1);

use SwiftLift\{Http, Auth, AuthTokenRepo, Env, Mailer, PasswordSecurity, RateLimit, UserRepo};

/**
 * <summary>
 * Combined router for email verification, forgot password, and reset password.
 * Accepts POSTs at /api/auth/verify/send, /api/auth/verify/confirm,
 * /api/auth/forgot, and /api/auth/reset.
 * </summary>
 * <remarks>
 * The three flows share a single file because they all sit on top of
 * AuthTokenRepo's issue/consume primitive. Verification tokens last
 * 24 hours, password reset tokens 60 minutes, and every consume is
 * one shot so a leaked token cannot be replayed. Forgot password
 * always returns ok even when no matching account exists, denying an
 * attacker the ability to enumerate the user table through the form.
 * </remarks>
 */

$method = $_SERVER['REQUEST_METHOD'];
$tail   = $_GET['_tail'] ?? '';

/**
 * <summary>
 * POST /api/auth/verify/send. Sends or re-sends the verification email.
 * </summary>
 * <remarks>
 * Requires an authenticated session because only the account holder
 * can ask for their own verification link. Returns already=true (still
 * 200) if the address is already verified so the SPA can flatten the
 * UI without an extra round trip.
 * </remarks>
 */
if ($method === 'POST' && $tail === 'send') {
    $uid = Auth::require();
    $u   = UserRepo::findById($uid);
    if (!$u) Http::error('User not found', 404);
    if (!empty($u['email_verified_at'])) Http::json(['ok' => true, 'already' => true]);

    $token = AuthTokenRepo::issue($uid, 'verify_email', 60 * 24); // 24 h
    $base  = Http::appBaseUrl();
    $link  = $base . '/?verify=' . urlencode($token);

    $body = "Hi " . ($u['display_name'] ?: 'there') . ",\n\n" .
            "Confirm your email for SwiftLift by opening this link in your browser:\n\n" .
            $link . "\n\n" .
            "It expires in 24 hours. If you didn't sign up, ignore this email.\n\n" .
            "Thanks,\nSwiftLift\n";
    Mailer::send($u['email'], 'Confirm your SwiftLift email', $body);
    Http::json(['ok' => true]);
}

/**
 * <summary>
 * POST /api/auth/verify/confirm. Consumes a verify email token and signs the user in.
 * </summary>
 * <remarks>
 * The token consume is one shot. On success we mark email_verified_at,
 * log the user in (in case they were not already), and return the
 * fresh user row so the SPA can switch to the signed in shell without
 * a follow up call to /auth/me.
 * </remarks>
 */
if ($method === 'POST' && $tail === 'confirm') {
    $b     = Http::body();
    $token = (string) ($b['token'] ?? '');
    if ($token === '') Http::error('Token is required');
    try {
        $uid = AuthTokenRepo::consume($token, 'verify_email');
    } catch (\Throwable $e) {
        Http::error($e->getMessage());
    }
    UserRepo::markEmailVerified($uid);
    Auth::login($uid); // log them in if they weren't already
    Http::json(UserRepo::findById($uid));
}

/**
 * <summary>
 * POST /api/auth/forgot. Sends a password reset email if the address matches an account.
 * </summary>
 * <remarks>
 * Throttled to 5 requests per IP per hour to stop someone enumerating
 * the user table and spamming inboxes. Always returns ok regardless of
 * whether an account was found, so the form cannot be used as an
 * email enumeration oracle.
 * </remarks>
 */
if ($method === 'POST' && $tail === 'forgot') {
    RateLimit::guard('forgot', 5, 3600);
    RateLimit::record('forgot');

    $b     = Http::body();
    $email = strtolower(trim((string) ($b['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) Http::error('Invalid email');

    $u = UserRepo::findByEmail($email);
    if ($u) {
        $token = AuthTokenRepo::issue((int) $u['id'], 'password_reset', 60); // 60 min
        $base  = Http::appBaseUrl();
        $link  = $base . '/?reset=' . urlencode($token);

        $body = "A password reset was requested for your SwiftLift account.\n\n" .
                "Open this link in your browser within the next hour:\n\n" .
                $link . "\n\n" .
                "If you didn't request this, ignore this email. Your password stays the same.\n\n" .
                "Thanks,\nSwiftLift\n";
        Mailer::send($u['email'], 'Reset your SwiftLift password', $body);
    }
    Http::json(['ok' => true]);
}

/**
 * <summary>
 * POST /api/auth/reset. Consumes a password reset token and sets a new password.
 * </summary>
 * <remarks>
 * Throttled to 10 attempts per IP per 10 minutes to slow a brute force
 * search of the token space, though the tokens themselves are 32 hex
 * characters and would not realistically be guessable inside the 60
 * minute window. setPassword bumps auth_epoch so any other devices
 * holding a session under the previous password are evicted on their
 * next request.
 * </remarks>
 */
if ($method === 'POST' && $tail === 'reset') {
    RateLimit::guard('reset', 10, 600);
    RateLimit::record('reset');

    $b     = Http::body();
    $token = (string) ($b['token'] ?? '');
    $new   = (string) ($b['new_password'] ?? '');
    $pwErr = PasswordSecurity::validate($new);
    if ($pwErr !== null) Http::error($pwErr);
    try {
        $uid = AuthTokenRepo::consume($token, 'password_reset');
    } catch (\Throwable $e) {
        Http::error($e->getMessage());
    }
    UserRepo::setPassword($uid, $new);
    Http::json(['ok' => true]);
}

Http::error('Not found', 404);
