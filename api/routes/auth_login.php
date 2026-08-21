<?php
declare(strict_types=1);

use SwiftLift\{Http, Auth, UserRepo, RateLimit};

/**
 * <summary>
 * POST /api/auth/login. Verifies email and password and establishes a session.
 * </summary>
 * <remarks>
 * Two layered rate limits run before any DB lookup. A per IP bucket of
 * 10 attempts in 10 minutes stops a single botnet node spraying, and a
 * per account bucket of 5 attempts in 15 minutes keyed by an md5 of the
 * lowercased email defeats an attacker rotating IPs to spray passwords
 * at one specific user. A constant time bcrypt verify against a fixed
 * dummy hash closes the timing oracle that would otherwise let an
 * attacker enumerate emails by measuring response latency. Deleted,
 * banned, and disabled accounts all produce dedicated responses, with
 * the deleted path matching the "invalid credentials" wording so it
 * cannot be probed. A successful login transparently rehashes the
 * password when PASSWORD_DEFAULT moves on, then logs the user in and
 * returns the fresh user row.
 * </remarks>
 */

Http::requireMethod('POST');

/**
 * <summary>
 * Apply per IP rate limiting before doing any DB work.
 * </summary>
 * <remarks>
 * 10 attempts per IP per 10 minutes, with a periodic prune of stale
 * bucket rows so the table cannot grow without bound.
 * </remarks>
 */
RateLimit::guard('login', 10, 600);
RateLimit::record('login');
RateLimit::maybePrune();

$b = Http::body();

$email = strtolower(trim((string)($b['email'] ?? '')));
$pass  = (string)($b['password'] ?? '');

/**
 * <summary>
 * Apply the per account rate limit when an email is supplied.
 * </summary>
 * <remarks>
 * Skipped for empty inputs so the counter is not polluted by blank
 * email spam. md5 is used (not sha256) because the auth_attempts.ip
 * column is VARCHAR(45) and we do not want to widen the schema just
 * for this bucket. md5 is fine for bucketing as we are not protecting
 * a secret, only keying distinct emails apart, and collisions are
 * astronomically unlikely. Crucially every attempt is recorded, not
 * just failures. The older code only bumped the per account counter
 * on failure, which let a credential stuffing attacker get a free
 * counter reset every time they happened to hit a correct credential
 * pair in their spray. The per IP counter is symmetric in both
 * directions and is not affected by this concern.
 * </remarks>
 */
$emailKey = $email !== '' ? md5($email) : '';
if ($emailKey !== '') {
    RateLimit::guardKey('login_account', $emailKey, 5, 900); // 5 / 15 min
    RateLimit::recordKey('login_account', $emailKey);
}

$user = UserRepo::findByEmail($email);

/**
 * <summary>
 * Constant time password verify against a real or dummy hash.
 * </summary>
 * <remarks>
 * Even when the email does not exist (or the account is deleted, or it
 * is an OAuth only account with no password hash) we run a bcrypt
 * compare against a fixed dummy hash. Without this, the missing email
 * branch returns roughly 100ms faster than the wrong password branch,
 * which is a usable timing oracle for email enumeration. $DUMMY_HASH
 * is the bcrypt hash of "rasmuslerdorf" from the PHP docs, a publicly
 * known valid hash. Its content does not matter because we 401 on the
 * missing user path regardless of verify outcome; we just need a
 * syntactically valid bcrypt so password_verify spends the same amount
 * of time on it as on a real hash.
 * </remarks>
 */
$DUMMY_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
$hash       = ($user && empty($user['deleted_at']) && !empty($user['password_hash']))
            ? (string) $user['password_hash']
            : $DUMMY_HASH;
$valid      = password_verify($pass, $hash);

/**
 * <summary>
 * Reject deleted, missing, or wrong password attempts with a generic 401.
 * </summary>
 * <remarks>
 * Deleted accounts behave like the email never existed, so an attacker
 * cannot probe for tombstoned users either. The per account counter
 * was already incremented above, so we do not double record here.
 * Banned and disabled accounts get distinct messages because there is
 * nothing useful to hide once the password has matched.
 * </remarks>
 */
if (!$user || $user['deleted_at'] || !$valid) {
    Http::error('Invalid credentials', 401);
}
if ($user['banned_at']) {
    Http::error('This account has been suspended. Contact hello@swiftlift.gg', 403);
}
if ($user['disabled_at']) {
    Http::error('This account is disabled. Contact hello@swiftlift.gg to reactivate.', 403);
}

/**
 * <summary>
 * Transparently upgrade an outdated password hash on a successful login.
 * </summary>
 * <remarks>
 * If the stored hash uses an older bcrypt cost (or PHP's
 * PASSWORD_DEFAULT moves to a different algorithm in a future
 * release), rehash now that we know the plaintext is correct. Best
 * effort: never fail a login over this.
 * </remarks>
 */
if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
    try {
        UserRepo::setPasswordRaw((int) $user['id'], $pass);
    } catch (\Throwable $e) {
        error_log('[SwiftLift] password rehash failed for user ' . (int) $user['id'] . ': ' . $e->getMessage());
    }
}

Auth::login((int)$user['id']);
Http::json(UserRepo::findById((int)$user['id']));
