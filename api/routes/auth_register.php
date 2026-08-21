<?php
declare(strict_types=1);

use SwiftLift\{Http, Auth, AuthTokenRepo, Env, Mailer, PasswordSecurity, RateLimit, UserRepo};

/**
 * <summary>
 * POST /api/auth/register. Creates an account or, if the email is already in
 * use, silently emails the legitimate owner a password reset link and returns
 * the same shape so the caller cannot tell which path was taken.
 * </summary>
 * <remarks>
 * Throttled to 5 registrations per IP per hour. Validates email, name,
 * adult confirmation, and password strength before any DB work. The
 * 18+ confirmation is the legal cover bit. The wider site is built for
 * adults (every age filter input is min=18 and the matching SQL hides
 * under 18 or undisclosed age users from anyone with a filter set), so
 * anyone ticking the box and not being 18+ has made a misrepresentation
 * under the terms of service. The role field is no longer collected;
 * direction is set per journey instead, and any role posted by older
 * clients is ignored. The email enumeration defence treats brand new
 * and already in use emails identically at the API surface: the only
 * party that learns the truth is the legitimate owner of an existing
 * address, who receives an "attempted sign up" email.
 * </remarks>
 */

Http::requireMethod('POST');

/**
 * <summary>
 * Throttle registrations to 5 per IP per hour.
 * </summary>
 * <remarks>
 * Generous enough for a real family signing up from one network, tight
 * enough that a bot operator cannot enumerate or spam through this route.
 * </remarks>
 */
RateLimit::guard('register', 5, 3600);
RateLimit::record('register');

$b = Http::body();

$email = strtolower(trim((string)($b['email'] ?? '')));
$pass  = (string)($b['password'] ?? '');
$name  = trim((string)($b['display_name'] ?? ''));
$confirmAdult = filter_var($b['confirm_adult'] ?? false, FILTER_VALIDATE_BOOLEAN);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) Http::error('Invalid email');
if ($name === '')                                Http::error('Display name required');
if (!$confirmAdult) {
    Http::error('You need to confirm you are 18 or over to use SwiftLift.');
}
$pwErr = PasswordSecurity::validate($pass);
if ($pwErr !== null)                             Http::error($pwErr);

/**
 * <summary>
 * Compute the application's base URL once for use in outgoing emails.
 * </summary>
 * <remarks>
 * Refuses to fall back to $_SERVER['HTTP_HOST']. The Host header is
 * attacker controlled on a POST and would otherwise let an attacker
 * target a real user with a password reset email whose link points
 * at evil.com. Http::appBaseUrl reads the canonical URL from .env.
 * </remarks>
 */
$base = Http::appBaseUrl();

/**
 * <summary>
 * Email enumeration defence. If the address is already in use we silently
 * email the real owner with a reset link and return the same shape as the
 * success path so the caller cannot probe for which emails exist.
 * </summary>
 * <remarks>
 * The would be registrant sees the standard "check your email" reply
 * regardless of whether their address was new or taken. Only the
 * legitimate owner of an existing address learns anything happened.
 * The mail send is best effort; a failure is logged but does not
 * leak through the API response.
 * </remarks>
 */
$existing = UserRepo::findByEmail($email);
if ($existing && empty($existing['deleted_at'])) {
    try {
        $token = AuthTokenRepo::issue((int) $existing['id'], 'password_reset', 60); // 60 min
        $link  = $base . '/?reset=' . urlencode($token);
        $body  = "Someone just tried to register a SwiftLift account using this email address.\n\n" .
                 "Your account already exists. If that was you and you've forgotten your password,\n" .
                 "use this link within the next hour to set a new one:\n\n" .
                 $link . "\n\n" .
                 "If it wasn't you, you can safely ignore this email. No changes have been made.\n\n" .
                 "Thanks,\nSwiftLift\n";
        Mailer::send((string) $existing['email'], 'A SwiftLift sign-up attempt for your email', $body);
    } catch (\Throwable $e) {
        error_log('[SwiftLift] enum-defence email failed for existing user ' . (int) $existing['id'] . ': ' . $e->getMessage());
    }
    Http::json(['ok' => true, 'check_email' => true]);
}

/**
 * <summary>
 * Brand new account path. Create the user, send the verification email, and
 * return the same check_email shape as the enumeration defence above.
 * </summary>
 * <remarks>
 * No auto login. The user clicks the link in their inbox, the verify
 * endpoint logs them in, and they land in the app already verified.
 * This keeps the new and existing email register attempts
 * indistinguishable at the API surface. Mail send failure is logged
 * but never blocks the response.
 * </remarks>
 */
$id = UserRepo::create($email, $pass, $name);

try {
    $token = AuthTokenRepo::issue($id, 'verify_email', 60 * 24);
    $link  = $base . '/?verify=' . urlencode($token);
    $body  = "Welcome to SwiftLift, $name!\n\n" .
             "Confirm your email by opening this link in your browser:\n\n" . $link . "\n\n" .
             "It expires in 24 hours.\n\nThanks,\nSwiftLift\n";
    Mailer::send($email, 'Confirm your SwiftLift email', $body);
} catch (\Throwable $e) {
    error_log('[SwiftLift] verify-email send failed for new user ' . $id . ': ' . $e->getMessage());
}

Http::json(['ok' => true, 'check_email' => true]);
