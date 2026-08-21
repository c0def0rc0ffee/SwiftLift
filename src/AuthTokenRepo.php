<?php
declare(strict_types=1);

namespace SwiftLift;

/**
 * <summary>
 * Single-use auth tokens for verify-email and password-reset flows. The
 * raw token is returned only at issue time. Only its SHA-256 hash is
 * persisted, so a database leak does not surrender usable links.
 * </summary>
 * <remarks>
 * Tokens have a configurable TTL and are scoped by purpose. Issuing a
 * new token for the same user and purpose invalidates the outstanding
 * one so the newest emailed link is the only one that works. Banned and
 * tombstoned users are also blocked at consume time, because the verify
 * route calls Auth::login() directly afterwards and the front controller
 * skips its banned-session check on /api/auth/... routes.
 * </remarks>
 */
final class AuthTokenRepo
{
    /**
     * <summary>
     * Mint a single-use token for the given purpose, store its SHA-256
     * hash with an expiry, and return the raw token for emailing.
     * </summary>
     * <param name="userId">The user the token authenticates.</param>
     * <param name="purpose">Either "verify_email" or "password_reset".</param>
     * <param name="ttlMinutes">How many minutes the token remains valid.</param>
     * <returns>The raw 48-character hex token. Only exposed here and in the email link.</returns>
     * <exception cref="\RuntimeException">If the purpose isn't one of the allowed values.</exception>
     * <remarks>
     * Any outstanding unused tokens for the same user and purpose are
     * marked used first so only the newest link works. The caller is
     * responsible for emailing the raw token.
     * </remarks>
     */
    public static function issue(int $userId, string $purpose, int $ttlMinutes): string
    {
        if (!in_array($purpose, ['verify_email', 'password_reset'], true)) {
            throw new \RuntimeException('Invalid purpose');
        }
        // Invalidate any outstanding tokens for the same purpose so the
        // newest link is the one that works.
        $del = Db::pdo()->prepare(
            "UPDATE auth_tokens SET used_at = CURRENT_TIMESTAMP
             WHERE user_id = ? AND purpose = ? AND used_at IS NULL"
        );
        $del->execute([$userId, $purpose]);

        $raw  = bin2hex(random_bytes(24)); // 48 chars
        $hash = hash('sha256', $raw);
        $exp  = (new \DateTimeImmutable("+{$ttlMinutes} minutes"))->format('Y-m-d H:i:s');

        $ins = Db::pdo()->prepare(
            "INSERT INTO auth_tokens (user_id, purpose, token_hash, expires_at) VALUES (?, ?, ?, ?)"
        );
        $ins->execute([$userId, $purpose, $hash, $exp]);
        return $raw;
    }

    /**
     * <summary>
     * Validate and mark a token as used, returning the user id it
     * authenticates.
     * </summary>
     * <param name="raw">The raw token string from the user's email link.</param>
     * <param name="purpose">Either "verify_email" or "password_reset".</param>
     * <returns>The user id the token belongs to.</returns>
     * <exception cref="\RuntimeException">If the token is unknown, already used, expired, or the target user is banned or deleted. Disabled accounts may still consume a "verify_email" token (that is the whole point of disable) but not any other purpose.</exception>
     * <remarks>
     * Stamping used_at is what prevents replay. Banned and tombstoned
     * users are blocked here because the verify route calls Auth::login()
     * directly after consume(). Without these guards, a banned user with
     * a still-unexpired verify-email token could re-authenticate
     * themselves, bypassing the front controller's banned-session check
     * (which is skipped for /api/auth/... routes).
     * </remarks>
     */
    public static function consume(string $raw, string $purpose): int
    {
        $hash = hash('sha256', $raw);
        $pdo  = Db::pdo();
        $stmt = $pdo->prepare(
            "SELECT t.id, t.user_id, t.expires_at, t.used_at,
                    u.banned_at, u.disabled_at, u.deleted_at
             FROM auth_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = ? AND t.purpose = ?"
        );
        $stmt->execute([$hash, $purpose]);
        $row = $stmt->fetch();
        if (!$row)                                           throw new \RuntimeException('Invalid token');
        if ($row['used_at'] !== null)                        throw new \RuntimeException('Token already used');
        if (strtotime((string) $row['expires_at']) < time()) throw new \RuntimeException('Token expired');
        if (!empty($row['deleted_at']))                      throw new \RuntimeException('Invalid token');
        if (!empty($row['banned_at']))                       throw new \RuntimeException('This account has been suspended. Contact hello@swiftlift.gg');
        // Disabled tokens are deliberately consumable for the
        // 'verify_email' purpose only, that's the entire point of the
        // disable state, the user is being asked to verify. For any
        // other purpose (password_reset) a disabled account must
        // reactivate via the operator first.
        if (!empty($row['disabled_at']) && $purpose !== 'verify_email') {
            throw new \RuntimeException('This account is disabled. Contact hello@swiftlift.gg to reactivate.');
        }

        $pdo->prepare("UPDATE auth_tokens SET used_at = CURRENT_TIMESTAMP WHERE id = ?")
            ->execute([$row['id']]);
        return (int) $row['user_id'];
    }
}
