<?php
declare(strict_types=1);

namespace SwiftLift;

/**
 * <summary>
 * Tiny rolling-window rate limiter backed by the auth_attempts table.
 * Used by login, register and forgot-password to slow down
 * brute-force attempts.
 * </summary>
 * <remarks>
 * Typical usage:
 *
 *   RateLimit::guard('login', $maxPerWindow = 10, $windowSec = 600);
 *   // ... do the auth check ...
 *   RateLimit::record('login');         // count this attempt
 *
 * Both IP-keyed and arbitrary-subject-keyed flavours are available.
 * The subject-keyed variant lets us throttle attacks that rotate IPs
 * while spraying passwords at a single account.
 * </remarks>
 */
final class RateLimit
{
    /**
     * <summary>
     * Return the client's IP address from REMOTE_ADDR.
     * </summary>
     * <returns>An IP string. Defaults to "0.0.0.0" when nothing is set.</returns>
     * <remarks>
     * X-Forwarded-* headers are intentionally ignored unless we are
     * known to be behind a proxy we control. On IONOS shared hosting,
     * REMOTE_ADDR is the right thing.
     * </remarks>
     */
    public static function clientIp(): string
    {
        // We don't trust X-Forwarded-* unless we know we're behind a proxy
        // we control. On IONOS shared hosting, REMOTE_ADDR is the right thing.
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    /**
     * <summary>
     * Count recent attempts for the caller's IP for the given action
     * and abort the request with a 429 JSON response when the limit
     * is hit.
     * </summary>
     * <param name="action">An action label, e.g. "login" or "register".</param>
     * <param name="maxPerWindow">Maximum attempts allowed in the window.</param>
     * <param name="windowSec">Window length in seconds.</param>
     */
    public static function guard(string $action, int $maxPerWindow, int $windowSec): void
    {
        self::ensureTable();
        $ip   = self::clientIp();
        $stmt = Db::pdo()->prepare(
            "SELECT COUNT(*) FROM auth_attempts
             WHERE ip = ? AND action = ?
               AND attempted_at >= NOW() - INTERVAL ? SECOND"
        );
        $stmt->execute([$ip, $action, $windowSec]);
        $n = (int) $stmt->fetchColumn();
        if ($n >= $maxPerWindow) {
            http_response_code(429);
            header('Content-Type: application/json; charset=utf-8');
            header('Retry-After: ' . $windowSec);
            echo json_encode([
                'error' => 'Too many attempts. Try again in a few minutes.',
                'retry_after_seconds' => $windowSec,
            ]);
            exit;
        }
    }

    /**
     * <summary>
     * Record one attempt for the given action against the caller's IP,
     * regardless of whether the attempt was successful.
     * </summary>
     * <param name="action">An action label matching the one passed to guard().</param>
     */
    public static function record(string $action): void
    {
        self::ensureTable();
        $ip = self::clientIp();
        Db::pdo()
            ->prepare("INSERT INTO auth_attempts (ip, action) VALUES (?, ?)")
            ->execute([$ip, $action]);
    }

    /**
     * <summary>
     * Per-subject guard. Like guard(), but the bucket is keyed by an
     * arbitrary identifier (typically a hash of an email address)
     * instead of the caller's IP.
     * </summary>
     * <param name="action">Action label. Keep this distinct from the IP variant (e.g. "login_account" vs "login") so the two do not collide.</param>
     * <param name="key">Opaque identifier for the bucket, e.g. a hashed email.</param>
     * <param name="maxPerWindow">Maximum attempts allowed in the window.</param>
     * <param name="windowSec">Window length in seconds.</param>
     * <remarks>
     * Used to rate-limit by something other than IP so an attacker who
     * rotates IPs while spraying passwords at one account still hits
     * a wall. The auth_attempts.ip column is reused for the subject;
     * we do not promise it is actually an IP, just an opaque
     * identifier per (action, subject) bucket.
     * </remarks>
     */
    public static function guardKey(string $action, string $key, int $maxPerWindow, int $windowSec): void
    {
        self::ensureTable();
        $stmt = Db::pdo()->prepare(
            "SELECT COUNT(*) FROM auth_attempts
             WHERE ip = ? AND action = ?
               AND attempted_at >= NOW() - INTERVAL ? SECOND"
        );
        $stmt->execute([$key, $action, $windowSec]);
        $n = (int) $stmt->fetchColumn();
        if ($n >= $maxPerWindow) {
            http_response_code(429);
            header('Content-Type: application/json; charset=utf-8');
            header('Retry-After: ' . $windowSec);
            echo json_encode([
                'error' => 'Too many attempts on this account. Try again in a few minutes.',
                'retry_after_seconds' => $windowSec,
            ]);
            exit;
        }
    }

    /**
     * <summary>
     * Record one attempt for an action against an arbitrary subject
     * key. The companion to guardKey().
     * </summary>
     * <param name="action">Action label matching the one passed to guardKey().</param>
     * <param name="key">Opaque identifier for the bucket.</param>
     */
    public static function recordKey(string $action, string $key): void
    {
        self::ensureTable();
        Db::pdo()
            ->prepare("INSERT INTO auth_attempts (ip, action) VALUES (?, ?)")
            ->execute([$key, $action]);
    }

    /**
     * <summary>
     * On roughly 1% of requests, delete auth_attempts rows older than
     * 24 hours so the table cannot grow unbounded.
     * </summary>
     * <remarks>
     * Best-effort cleanup so we avoid needing a separate cron job.
     * Failures are swallowed.
     * </remarks>
     */
    public static function maybePrune(): void
    {
        if (random_int(0, 99) !== 0) return;
        try {
            Db::pdo()->exec("DELETE FROM auth_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY");
        } catch (\Throwable $e) { /* swallow */ }
    }

    /**
     * <summary>
     * Idempotently ensure the auth_attempts table exists. Runs once
     * per process and swallows any DB error (the table may already
     * exist or DDL may be restricted).
     * </summary>
     */
    private static function ensureTable(): void
    {
        static $checked = false;
        if ($checked) return;
        $checked = true;
        try {
            Db::pdo()->exec(
                "CREATE TABLE IF NOT EXISTS auth_attempts (
                    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    ip           VARCHAR(45) NOT NULL,
                    action       VARCHAR(40) NOT NULL,
                    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_ip_action_time (ip, action, attempted_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (\Throwable $e) { /* swallow, table may exist */ }
    }
}
