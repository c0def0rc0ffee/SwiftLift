<?php
declare(strict_types=1);

namespace SwiftLift;

/**
 * <summary>
 * Session-backed authentication helpers. Centralises session start,
 * current-user lookup, login, logout, and the auth_epoch bookkeeping
 * used to invalidate stale sessions after a password change.
 * </summary>
 * <remarks>
 * Sessions are started lazily so lightweight endpoints (e.g. health
 * checks) can avoid touching the session store entirely. The class
 * also stamps last_login_at on the user row for the admin dashboard
 * and snapshots the current auth_epoch into the session so the front
 * controller can evict the session when a credential change bumps the
 * epoch.
 * </remarks>
 */
final class Auth
{
    /**
     * <summary>
     * Idempotently start the PHP session with the cookie parameters
     * appropriate for SwiftLift (30-day lifetime, HttpOnly, SameSite=Lax,
     * Secure when behind HTTPS).
     * </summary>
     * <remarks>
     * The session name is overridable via the SESSION_NAME env var.
     * Safe to call multiple times within a single request.
     * </remarks>
     */
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $name = Env::get('SESSION_NAME', 'swiftlift_sid');
        session_name($name);
        session_set_cookie_params([
            'lifetime' => 60 * 60 * 24 * 30,
            'path'     => '/',
            'secure'   => self::isHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /**
     * <summary>
     * Decide whether the current request is served over HTTPS, for the
     * purpose of setting the session cookie's Secure flag.
     * </summary>
     * <returns>True if the connection should be treated as HTTPS.</returns>
     * <remarks>
     * The primary signal is the configured APP_BASE_URL: it comes from
     * .env (a trusted value an attacker cannot influence) and on the
     * production host is https://swiftlift.gg, so the Secure flag is set
     * unconditionally there. Relying on $_SERVER['HTTPS'] alone is unsafe
     * on IONOS shared hosting, where TLS is terminated by an upstream
     * proxy and the variable may be empty even on a genuine HTTPS request,
     * which would issue the session cookie without Secure and expose it
     * to a plaintext downgrade. The runtime signals are kept only as a
     * fallback for when APP_BASE_URL isn't set (e.g. local dev over HTTP,
     * where Secure must stay off so the cookie works at all).
     * </remarks>
     */
    private static function isHttps(): bool
    {
        $base = (string) (Env::get('APP_BASE_URL', '') ?? '');
        if (str_starts_with($base, 'https://')) return true;
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') return true;
        if (strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') return true;
        return false;
    }

    /**
     * <summary>
     * Return the currently logged-in user id from the session, or null if
     * nobody is signed in.
     * </summary>
     * <returns>The logged-in user id, or null when there is no session user.</returns>
     */
    public static function userId(): ?int
    {
        self::start();
        return isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : null;
    }

    /**
     * <summary>
     * Return the currently logged-in user id, or terminate the request with
     * a 401 JSON error if nobody is signed in.
     * </summary>
     * <returns>The authenticated user id (never null).</returns>
     * <remarks>
     * Used as a one-liner guard at the top of authenticated API routes.
     * </remarks>
     */
    public static function require(): int
    {
        $uid = self::userId();
        if ($uid === null) Http::error('Not authenticated', 401);
        return $uid;
    }

    /**
     * <summary>
     * Mark the given user as signed in for this session, regenerate the
     * session id, snapshot their auth_epoch, and stamp last_login_at.
     * </summary>
     * <param name="userId">The user id to associate with this session.</param>
     * <remarks>
     * The auth_epoch snapshot lets the front controller evict the session
     * later if a password change bumps the user's epoch. The
     * last_login_at write is best effort and is swallowed on failure so a
     * missing column (e.g. pre-migration) doesn't break login. Likewise
     * the epoch snapshot defaults to 0 if the column isn't yet present.
     * </remarks>
     */
    public static function login(int $userId): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION['uid'] = $userId;

        // Snapshot the user's current auth_epoch into the session so the
        // front controller can evict this session if a future password
        // change bumps it. COALESCE-via-PHP in case the column hasn't
        // been migrated yet.
        try {
            $_SESSION['auth_epoch'] = UserRepo::authEpoch($userId);
        } catch (\Throwable $e) {
            $_SESSION['auth_epoch'] = 0;
        }

        // Stamp last_login_at so the admin dashboard can see who's active.
        // Best effort: never fail a login because the bookkeeping write
        // tripped (e.g. if the column hasn't been migrated yet).
        try {
            Db::pdo()
                ->prepare("UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([$userId]);
        } catch (\Throwable $e) { /* swallow */ }
    }

    /**
     * <summary>
     * Refresh the in-session auth_epoch snapshot to the latest DB value
     * for the current user.
     * </summary>
     * <param name="userId">The user whose epoch to re-snapshot. Must match the active session.</param>
     * <remarks>
     * Call this after the current user's password is changed via
     * /api/profile/password. Without the refresh, the very next request
     * from this same session would be evicted by the front controller's
     * epoch mismatch check. No-op if the session belongs to someone else.
     * </remarks>
     */
    public static function refreshAuthEpoch(int $userId): void
    {
        self::start();
        if (($_SESSION['uid'] ?? null) !== $userId) return;
        try {
            $_SESSION['auth_epoch'] = UserRepo::authEpoch($userId);
        } catch (\Throwable $e) { /* swallow */ }
    }

    /**
     * <summary>
     * Clear the session, expire the session cookie, and destroy the
     * session store entry so the user is signed out.
     * </summary>
     */
    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }
}
