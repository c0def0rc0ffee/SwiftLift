<?php
declare(strict_types=1);

namespace SwiftLift;

/**
 * <summary>
 * Repository for the users table: lookups, profile mutations,
 * password and email change flows, ban / disable state, soft-delete
 * tombstoning, and the OAuth find-or-create path.
 * </summary>
 * <remarks>
 * Most reads pull a curated PROFILE_COLUMNS list so internal-only
 * columns (password_hash, banned_at, etc.) only leak through methods
 * that explicitly opt in. Soft-deleted accounts have their identifying
 * fields scrubbed but the row stays in place so old conversations
 * still resolve to a "(deleted user)" placeholder rather than
 * crashing. Banned and disabled flags are separate concerns: bans are
 * admin-driven for misconduct, disables are typically driven by the
 * email-verification timeline (REMIND_DAYS, FORCE_DAYS).
 * </remarks>
 */
final class UserRepo
{
    /**
     * <summary>
     * Days after signup at which the verify-email banner appears.
     * After this, the front controller switches to a full-screen gate
     * until VERIFY_FORCE_DAYS.
     * </summary>
     * <remarks>
     * Timeline:
     *
     *   * Days 0 .. REMIND_DAYS: show a banner ("verify by date").
     *   * Days REMIND_DAYS .. FORCE_DAYS: full-screen gate; only auth
     *     and profile routes work. The user can resend, log out, or
     *     correct a typo'd email, but nothing else.
     *   * Days at or above FORCE_DAYS: the front controller stamps
     *     banned_at on the next request and the user falls into the
     *     standard banned flow (data kept, account inert). An admin
     *     can recover via Unban.
     *
     * Tweak these numbers without touching call sites.
     * </remarks>
     */
    public const VERIFY_REMIND_DAYS = 5;
    /**
     * <summary>
     * Days after signup at which an unverified account is auto-disabled.
     * </summary>
     */
    public const VERIFY_FORCE_DAYS  = 15;

    /**
     * <summary>
     * Curated SELECT list used by profile reads. Excludes internal-only
     * columns like password_hash. The legacy "role" column is in the
     * schema but no longer surfaced to clients (driver vs passenger is
     * decided per journey via journeys.direction).
     * </summary>
     */
    // Note: the legacy `role` column is still in the schema but no longer
    // surfaced to clients, driver-vs-passenger is decided per journey
    // (journeys.direction), so an account-level role is redundant. New
    // rows fall back to the column's DEFAULT 'both'.
    private const PROFILE_COLUMNS =
        'id, email, display_name, avatar_url, bio, ' .
        'age, age_min, age_max, sex, pref_sex, is_away, theme, ' .
        'default_radius_m, default_window_min, ' .
        'car_make, car_colour, car_seats, pref_smoking, pref_pets, ' .
        'pref_music, detour_m, notify_matches, ' .
        'email_verified_at, created_at, updated_at';

    /**
     * <summary>
     * Look up a user by email, returning a small set of columns
     * (including password_hash) suitable for the login flow.
     * </summary>
     * <param name="email">The email address to look up. No case-folding here; the caller is expected to normalise.</param>
     * <returns>The raw row or null when no user matches.</returns>
     * <remarks>
     * banned_at, disabled_at and deleted_at are exposed so the login
     * flow can tell the user why they cannot sign in (banned for
     * misconduct, disabled for unverified email) or treat the email
     * as unused (deleted).
     * </remarks>
     */
    public static function findByEmail(string $email): ?array
    {
        // banned_at / disabled_at / deleted_at exposed so the login flow
        // can tell the user why they can't sign in (banned for misconduct,
        // disabled for unverified email) or treat the email as unused
        // (deleted).
        $stmt = Db::pdo()->prepare(
            "SELECT id, email, password_hash, display_name, banned_at, disabled_at, deleted_at " .
            "FROM users WHERE email = ?"
        );
        $stmt->execute([$email]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * <summary>
     * Look up a user by id, returning the hydrated profile row, or
     * null when no live user matches (soft-deleted rows are treated
     * as gone).
     * </summary>
     * <param name="id">The user id.</param>
     * <returns>The hydrated profile array or null.</returns>
     */
    public static function findById(int $id): ?array
    {
        $stmt = Db::pdo()->prepare("SELECT " . self::PROFILE_COLUMNS . ", banned_at, disabled_at, deleted_at FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row)                return null;
        if ($row['deleted_at'])   return null;   // soft-deleted = gone
        return self::hydrate($row);
    }

    /**
     * <summary>
     * Lookup by id that returns the user row even when it is
     * tombstoned, banned or disabled.
     * </summary>
     * <param name="id">The user id.</param>
     * <returns>The hydrated profile array (including soft-deleted rows), or null when no row exists at all.</returns>
     * <remarks>
     * Used so the UI can render a "(deleted user)" placeholder for
     * old conversations rather than rendering nothing.
     * </remarks>
     */
    public static function findByIdIncludingTombstones(int $id): ?array
    {
        $stmt = Db::pdo()->prepare("SELECT " . self::PROFILE_COLUMNS . ", banned_at, disabled_at, deleted_at FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? self::hydrate($row) : null;
    }

    /**
     * <summary>
     * Set or clear the disabled_at flag on a user. Admin-driven and
     * reversible.
     * </summary>
     * <param name="userId">The user to flip.</param>
     * <param name="disabled">True to stamp disabled_at, false to clear it.</param>
     */
    public static function setDisabled(int $userId, bool $disabled): void
    {
        Db::pdo()
            ->prepare("UPDATE users SET disabled_at = " . ($disabled ? "CURRENT_TIMESTAMP" : "NULL") . " WHERE id = ?")
            ->execute([$userId]);
    }

    /**
     * <summary>
     * Create a new user with the given email, password and display
     * name. The DB column role defaults to "both"; we no longer ask
     * for it.
     * </summary>
     * <param name="email">The user's email address. Caller normalises and validates.</param>
     * <param name="password">Plain-text password. Hashed here with PASSWORD_DEFAULT.</param>
     * <param name="displayName">Display name shown across the app.</param>
     * <returns>The new user id.</returns>
     */
    public static function create(string $email, string $password, string $displayName): int
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo  = Db::pdo();
        // The DB column `role` defaults to 'both'; we no longer ask for it.
        $stmt = $pdo->prepare("INSERT INTO users (email, password_hash, display_name) VALUES (?, ?, ?)");
        $stmt->execute([$email, $hash, $displayName]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * <summary>
     * Update any subset of the user's mutable profile fields. Unknown
     * keys are silently ignored. Returns the updated profile row.
     * </summary>
     * <param name="userId">The user to update.</param>
     * <param name="in">Sparse map of field names to new values. Each value is normalised by a per-field closure.</param>
     * <returns>The freshly hydrated profile row.</returns>
     * <remarks>
     * Email is intentionally absent from the mass-assignment map.
     * Changes go through changeEmail() which requires a
     * current-password re-prompt and clears email_verified_at.
     * Allowing email through the mass-assignment map used to let a
     * session-hijacker silently flip the address and trigger password
     * reset to lock the real owner out. Avatar changes also stamp
     * avatar_updated_at when the value actually changes so the admin
     * dashboard can show "recently updated avatars".
     * </remarks>
     */
    public static function updateProfile(int $userId, array $in): array
    {
        $fields = [];
        $params = [];

        // Minimum age is 18: legal adulthood, and you'd typically want at
        // least a year of driving under your belt before sharing lifts
        // anyway. Younger ages don't make sense as either a self-age or
        // a filter target. Anything out of range is normalised to NULL
        // (= "not set").
        $clampAge = static function ($v): ?int {
            if ($v === null || $v === '' || $v === false) return null;
            $n = (int) $v;
            if ($n < 18 || $n > 120) return null;
            return $n;
        };

        $optionalEnum = static function (array $allowed): \Closure {
            return static function ($v) use ($allowed) {
                if ($v === null || $v === '' || $v === false) return null;
                return in_array($v, $allowed, true) ? $v : null;
            };
        };
        $optionalString = static function (int $max): \Closure {
            return static function ($v) use ($max) {
                if ($v === null || $v === '' || $v === false) return null;
                return mb_substr((string) $v, 0, $max);
            };
        };
        $optionalInt = static function (int $min, int $max): \Closure {
            return static function ($v) use ($min, $max) {
                if ($v === null || $v === '' || $v === false) return null;
                return max($min, min($max, (int) $v));
            };
        };

        $map = [
            'display_name'       => ['display_name', fn($v) => trim((string) $v)],
            // `email` intentionally absent, handled by changeEmail() which
            // requires a current-password re-prompt and clears
            // email_verified_at. Allowing it through the mass-assignment
            // map used to let a session-hijacker silently flip the address
            // and trigger password reset to lock the real owner out.
            // role intentionally absent, driver/passenger is per-journey now.
            'avatar_url'         => ['avatar_url',   fn($v) => $v === '' ? null : (string) $v],
            'bio'                => ['bio',          fn($v) => $v === '' ? null : (string) $v],
            'age'                => ['age',          $clampAge],
            'age_min'            => ['age_min',      $clampAge],
            'age_max'            => ['age_max',      $clampAge],
            // sex: 'male' / 'female' / null (= prefer not to say). Anything
            // else clamps to null.
            'sex'                => ['sex',          fn($v) => in_array($v, ['male','female'], true) ? $v : null],
            // pref_sex: 'any' (default) / 'male' / 'female'. Required field,
            // null or unknown values normalise to 'any' so we never end
            // up with a NULL in a NOT NULL column.
            'pref_sex'           => ['pref_sex',     fn($v) => in_array($v, ['male','female'], true) ? $v : 'any'],
            'is_away'            => ['is_away',      fn($v) => $v ? 1 : 0],
            'theme'              => ['theme',        fn($v) => in_array($v, ['dark','light','auto'], true) ? $v : 'dark'],
            'default_radius_m'   => ['default_radius_m',   fn($v) => max(100, min(2000,  (int) $v))],
            'default_window_min' => ['default_window_min', fn($v) => max(5, min(240, (int) $v))],

            // Vehicle / preferences
            'car_make'           => ['car_make',     $optionalString(60)],
            'car_colour'         => ['car_colour',   $optionalString(40)],
            'car_seats'          => ['car_seats',    $optionalInt(1, 8)],
            'pref_smoking'       => ['pref_smoking', $optionalEnum(['yes','no','outside'])],
            'pref_pets'          => ['pref_pets',    $optionalEnum(['yes','no','small_only'])],
            'pref_music'         => ['pref_music',   $optionalString(80)],
            'detour_m'           => ['detour_m',     fn($v) => max(0, min(5000, (int) $v))],

            // Email me when a new journey matches one of mine.
            'notify_matches'     => ['notify_matches', fn($v) => $v ? 1 : 0],
        ];

        // Track avatar changes separately so the admin dashboard can show
        // "recently updated avatars". We only stamp avatar_updated_at when
        // the value actually changes (and only when this update payload
        // mentions avatar_url at all).
        $stampAvatar = false;
        if (array_key_exists('avatar_url', $in)) {
            $current = self::findByIdIncludingTombstones($userId);
            $newAvatar = $in['avatar_url'] === '' ? null : (string) $in['avatar_url'];
            if (($current['avatar_url'] ?? null) !== $newAvatar) {
                $stampAvatar = true;
            }
        }

        foreach ($map as $key => [$col, $norm]) {
            if (!array_key_exists($key, $in)) continue;
            $fields[] = "`$col` = ?";
            $params[] = $norm($in[$key]);
        }

        if ($stampAvatar) {
            $fields[] = "`avatar_updated_at` = CURRENT_TIMESTAMP";
        }

        if ($fields) {
            $params[] = $userId;
            $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = ?";
            Db::pdo()->prepare($sql)->execute($params);
        }

        return self::findById($userId) ?? [];
    }

    /**
     * <summary>
     * Replace the user's password after verifying the current one.
     * Bumps auth_epoch so other live sessions for this user are
     * evicted on their next request.
     * </summary>
     * <param name="userId">The user changing their password.</param>
     * <param name="current">Their current password, for re-auth.</param>
     * <param name="new">The new plain-text password. Hashed here.</param>
     * <returns>True on success, false when the current password did not verify (also returned when the user has no password set).</returns>
     */
    public static function changePassword(int $userId, string $current, string $new): bool
    {
        $pdo  = Db::pdo();
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($current, $row['password_hash'])) return false;

        $hash = password_hash($new, PASSWORD_DEFAULT);
        // Bump auth_epoch so any other live sessions for this user are
        // evicted on their next request (front controller compares the
        // session's stored epoch to the DB value).
        $upd  = $pdo->prepare("UPDATE users SET password_hash = ?, auth_epoch = COALESCE(auth_epoch, 0) + 1 WHERE id = ?");
        $upd->execute([$hash, $userId]);
        return true;
    }

    /**
     * <summary>
     * Set the user's password without verifying any existing one.
     * Used by the password-reset flow once an auth token has been
     * consumed. Bumps auth_epoch.
     * </summary>
     * <param name="userId">The user whose password to set.</param>
     * <param name="new">The new plain-text password. Hashed here.</param>
     */
    public static function setPassword(int $userId, string $new): void
    {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        Db::pdo()->prepare(
            "UPDATE users SET password_hash = ?, auth_epoch = COALESCE(auth_epoch, 0) + 1 WHERE id = ?"
        )->execute([$hash, $userId]);
    }

    /**
     * <summary>
     * Upgrade a stored password hash to PASSWORD_DEFAULT's current
     * parameters without invalidating sessions.
     * </summary>
     * <param name="userId">The user whose hash to upgrade.</param>
     * <param name="new">The plain-text password just verified. Rehashed with the latest cost.</param>
     * <remarks>
     * Called from auth_login.php after a successful verify when
     * password_needs_rehash() says yes. Does NOT bump auth_epoch.
     * It is a silent cost-bump, not a credential change.
     * </remarks>
     */
    public static function setPasswordRaw(int $userId, string $new): void
    {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        Db::pdo()->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $userId]);
    }

    /**
     * <summary>
     * Read the current auth_epoch value for a user.
     * </summary>
     * <param name="userId">The user id.</param>
     * <returns>The integer epoch, or 0 when the column or row is missing.</returns>
     */
    public static function authEpoch(int $userId): int
    {
        $stmt = Db::pdo()->prepare("SELECT COALESCE(auth_epoch, 0) FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    /**
     * <summary>
     * Change a user's email address after verifying the current
     * password. Clears email_verified_at so the new address must be
     * re-confirmed.
     * </summary>
     * <param name="userId">The user whose email to change.</param>
     * <param name="newEmail">The new email address.</param>
     * <param name="currentPassword">The user's current password, required to re-authenticate.</param>
     * <returns>The freshly hydrated profile row.</returns>
     * <exception cref="\RuntimeException">If the current password does not verify, the account has no password set (OAuth-only), or the new email is already in use by another account.</exception>
     * <remarks>
     * The current-password requirement stops a session-hijacker
     * silently taking the account over. Without it, they could flip
     * the email, request a password reset (which goes to the new
     * address), and lock the legitimate owner out forever. The
     * caller (profile route) is expected to send a fresh verify-email
     * to the new address.
     * </remarks>
     */
    public static function changeEmail(int $userId, string $newEmail, string $currentPassword): array
    {
        $pdo  = Db::pdo();
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        // OAuth-only accounts (no password set) can't change their email
        // through this path, they should re-link rather than re-target.
        if (!$row || empty($row['password_hash']) || !password_verify($currentPassword, (string) $row['password_hash'])) {
            throw new \RuntimeException('Current password is incorrect');
        }

        $existing = self::findByEmail($newEmail);
        if ($existing && (int) $existing['id'] !== $userId) {
            throw new \RuntimeException('Email already in use');
        }

        $upd = $pdo->prepare(
            "UPDATE users SET email = ?, email_verified_at = NULL WHERE id = ?"
        );
        $upd->execute([$newEmail, $userId]);

        return self::findById($userId) ?? [];
    }

    /**
     * <summary>
     * Stamp the user's email as verified and clear any disabled_at
     * flag, since the only reason we auto-disable is unfinished
     * verification.
     * </summary>
     * <param name="userId">The user to mark verified.</param>
     * <remarks>
     * Manual admin disables (different cause) are also cleared here.
     * That is fine: the act of verifying proves the email is real,
     * which removes the audit reason for the disable too. If the user
     * finally clicks the link after FORCE_DAYS they are back in.
     * </remarks>
     */
    public static function markEmailVerified(int $userId): void
    {
        // Verifying also self-rehabilitates a disable, since the only
        // reason we auto-disable is unfinished verification. If the
        // user finally clicks the link after FORCE_DAYS, they're back
        // in. Manual admin disables (different cause) are also cleared
        // here, that's fine, the act of verifying proves the email
        // is real, which removes the audit reason for the disable too.
        Db::pdo()
            ->prepare("UPDATE users SET email_verified_at = CURRENT_TIMESTAMP, disabled_at = NULL WHERE id = ?")
            ->execute([$userId]);
    }

    /**
     * <summary>
     * Soft-delete a user: scrub identifying fields, drop their
     * journeys and OAuth links, and stamp deleted_at while leaving
     * the row in place so old conversations and accepted requests
     * still resolve to "(deleted user)" rather than crashing.
     * </summary>
     * <param name="userId">The user to soft-delete.</param>
     * <remarks>
     * What we keep: the id and the timestamps. Everything user-
     * identifying is cleared (email rewritten to a unique placeholder
     * so the UNIQUE constraint still holds, password hash blanked,
     * name and avatar and bio gone, vehicle and contact details
     * gone). Side effects:
     *
     *   * Their journeys are deleted outright (so they stop appearing
     *     in match queries). FKs on lift_requests cascade so any
     *     pending requests on those journeys go too.
     *   * Their OAuth links are dropped so a returning Facebook /
     *     Google login starts a fresh account rather than
     *     resurrecting the tombstone.
     *   * Sessions across all devices are not invalidated here. The
     *     next auth check sees deleted_at and refuses, which is
     *     enough.
     *   * Blocks and reports they raised stay, and ones against them
     *     stay too. They may still be useful to moderators.
     * </remarks>
     */
    public static function delete(int $userId): void
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            // 1. Drop their journeys (cascades to lift_requests + messages
            //    via the existing FKs).
            $pdo->prepare("DELETE FROM journeys WHERE user_id = ?")->execute([$userId]);

            // 2. Unlink any OAuth accounts so the same FB/Google login
            //    doesn't re-attach to the tombstone next time.
            $pdo->prepare("DELETE FROM user_oauth_accounts WHERE user_id = ?")->execute([$userId]);

            // 3. Blocks / reports they raised stay (they may still be useful
            //    to moderators); ones AGAINST them we leave too for the same
            //    reason. user_blocks rows referencing this user as either
            //    side simply become unreachable from the UI.

            // 4. Tombstone the row. Email rewritten to a unique placeholder
            //    so a future signup with the same address can succeed.
            $stmt = $pdo->prepare(
                "UPDATE users
                 SET email = CONCAT('deleted-', id, '@deleted.swiftlift.local'),
                     password_hash = NULL,
                     display_name  = '(deleted user)',
                     avatar_url    = NULL,
                     bio           = NULL,
                     age           = NULL,
                     age_min       = NULL,
                     age_max       = NULL,
                     car_make      = NULL,
                     car_colour    = NULL,
                     car_seats     = NULL,
                     pref_smoking  = NULL,
                     pref_pets     = NULL,
                     pref_music    = NULL,
                     sex           = NULL,
                     pref_sex      = 'any',
                     deleted_at    = CURRENT_TIMESTAMP
                 WHERE id = ?"
            );
            $stmt->execute([$userId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * <summary>
     * Ban or unban a user. Used by the admin moderation page. Setting
     * banned to false clears banned_at.
     * </summary>
     * <param name="userId">The user to flip.</param>
     * <param name="banned">True to ban, false to lift the ban.</param>
     * <remarks>
     * Banning skips deleted rows so a tombstoned account cannot be
     * banned. Unbanning works regardless of deleted_at so admins can
     * clean up accidentally banned tombstones.
     * </remarks>
     */
    public static function setBanned(int $userId, bool $banned): void
    {
        $sql = $banned
            ? "UPDATE users SET banned_at = CURRENT_TIMESTAMP WHERE id = ? AND deleted_at IS NULL"
            : "UPDATE users SET banned_at = NULL                WHERE id = ?";
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute([$userId]);
    }

    /**
     * <summary>
     * Find or create a user from an OAuth profile. Resolution order
     * is: existing OAuth link, then verified-email match, then create
     * a brand new account.
     * </summary>
     * <param name="provider">OAuth provider name, e.g. "google" or "facebook".</param>
     * <param name="providerUserId">The provider's stable user id (Google "sub", Facebook id).</param>
     * <param name="email">The email returned by the provider, or null if not granted.</param>
     * <param name="displayName">The display name returned by the provider.</param>
     * <returns>The resolved or newly created user id.</returns>
     * <exception cref="\RuntimeException">If the resolved account is banned, disabled or deleted.</exception>
     * <remarks>
     * Missing email is handled gracefully: we synthesise a unique
     * placeholder (oauth-{provider}-{id}@swiftlift.local) so the
     * UNIQUE(email) constraint still holds and the user can set a
     * real address later via the profile page.
     *
     * Resolution detail:
     *
     *   1. Existing user_oauth_accounts row: use the linked user
     *      after refreshing their email. We must not silently
     *      re-authenticate banned, disabled or deleted users via this
     *      path (the front controller's session check is skipped for
     *      /api/auth/... routes, so a banned user could otherwise
     *      re-enter just by clicking "Continue with Google").
     *   2. Existing verified, active user with the same email: link,
     *      but only when the provider has verified the email
     *      ($emailVerified). A user who registered by email but never
     *      confirmed should not have an attacker silently take over
     *      their account through OAuth, and neither should anyone who
     *      can persuade a provider to hand us an unverified address
     *      belonging to somebody else.
     *   3. Brand new user with no password and an OAuth link. The new
     *      row is stamped verified only when the provider verified the
     *      email; otherwise the app's own verification flow runs, the
     *      same as for a password signup.
     *
     * Long names and emails are capped server-side so an oversized
     * provider response cannot break the INSERT.
     * </remarks>
     */
    public static function findOrCreateOauthUser(string $provider, string $providerUserId, ?string $email, string $displayName, bool $emailVerified = false): int
    {
        $pdo = Db::pdo();
        $email = $email !== null ? strtolower(trim($email)) : null;
        if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = null;
        // Schema caps: email VARCHAR(191), display_name VARCHAR(80). Some
        // providers return very long names ("Christopher Andrew Mountbatten-
        // Windsor of the House …") or unusually long email locals. Cap
        // server-side so an oversized response doesn't break the INSERT.
        if ($email !== null) $email = mb_substr($email, 0, 191);
        $displayName = mb_substr(trim($displayName), 0, 80);

        // 1. Already linked. We must NOT silently re-authenticate banned or
        //    deleted users via this path, the front controller's session
        //    check is skipped for /api/auth/... routes, so without these
        //    guards a banned user could re-enter just by clicking "Continue
        //    with Google" again.
        $stmt = $pdo->prepare(
            "SELECT u.id, u.banned_at, u.disabled_at, u.deleted_at
               FROM user_oauth_accounts oa
               JOIN users u ON u.id = oa.user_id
              WHERE oa.provider = ? AND oa.provider_user_id = ?"
        );
        $stmt->execute([$provider, $providerUserId]);
        $linked = $stmt->fetch();
        if ($linked) {
            if (!empty($linked['banned_at']))   throw new AuthMessageException('This account has been suspended. Contact hello@swiftlift.gg');
            if (!empty($linked['disabled_at'])) throw new AuthMessageException('This account is disabled. Contact hello@swiftlift.gg to reactivate.');
            if (!empty($linked['deleted_at']))  throw new AuthMessageException('This account no longer exists.');
            $pdo->prepare(
                "UPDATE user_oauth_accounts
                 SET email = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE provider = ? AND provider_user_id = ?"
            )->execute([$email, $provider, $providerUserId]);
            return (int) $linked['id'];
        }

        // 2. Existing verified user with the same email, link only if
        //    verified AND active. A user who registered by email but
        //    never confirmed shouldn't have an attacker silently take
        //    over their account through OAuth. A banned account must not
        //    be re-entered through this path either.
        if ($email !== null) {
            $stmt = $pdo->prepare(
                "SELECT id, email_verified_at, banned_at, disabled_at, deleted_at FROM users WHERE email = ?"
            );
            $stmt->execute([$email]);
            $row = $stmt->fetch();
            if ($row) {
                if (!empty($row['banned_at'])) {
                    throw new AuthMessageException('This account has been suspended. Contact hello@swiftlift.gg');
                }
                if (!empty($row['disabled_at'])) {
                    throw new AuthMessageException('This account is disabled. Contact hello@swiftlift.gg to reactivate.');
                }
                // Deleted users have their email scrubbed in the delete()
                // path, so an exact email-match against a tombstoned row
                // shouldn't really happen, but guard anyway.
                if (empty($row['deleted_at']) && !empty($row['email_verified_at'])) {
                    // Only a provider-verified email may claim an existing
                    // account. Without this gate, any provider that hands us
                    // an address it has not itself confirmed becomes an
                    // account-takeover vector against the password account
                    // that owns that address.
                    if (!$emailVerified) {
                        throw new AuthMessageException(
                            'An account already exists for that email address. '
                            . 'Sign in with your password, then link ' . $provider . ' from your profile.'
                        );
                    }
                    $pdo->prepare(
                        "INSERT INTO user_oauth_accounts (user_id, provider, provider_user_id, email)
                         VALUES (?, ?, ?, ?)"
                    )->execute([(int) $row['id'], $provider, $providerUserId, $email]);
                    return (int) $row['id'];
                }
                // An unverified account exists for this email. Creating a
                // second row would violate UNIQUE(email), so stop with
                // something the user can act on rather than a 500.
                if (empty($row['deleted_at'])) {
                    throw new AuthMessageException(
                        'An account already exists for that email address but has never been '
                        . 'confirmed. Use "Forgot password" to confirm it, then sign in.'
                    );
                }
            }
        }

        // 3. Brand new user.
        $emailToUse = $email ?: ('oauth-' . $provider . '-' . $providerUserId . '@swiftlift.local');
        $name       = trim($displayName) !== '' ? trim($displayName) : 'New passenger';
        // `role` left to the column default ('both'); not surfaced to clients.
        $stmt = $pdo->prepare(
            "INSERT INTO users (email, password_hash, display_name, email_verified_at)
             VALUES (?, NULL, ?, " . ($email && $emailVerified ? "CURRENT_TIMESTAMP" : "NULL") . ")"
        );
        $stmt->execute([$emailToUse, $name]);
        $userId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO user_oauth_accounts (user_id, provider, provider_user_id, email)
             VALUES (?, ?, ?, ?)"
        )->execute([$userId, $provider, $providerUserId, $email]);
        return $userId;
    }

    /**
     * <summary>
     * Normalise a raw user row into typed, JSON-friendly values for
     * the profile API. Casts ints and bools, computes an
     * email_verified flag from the timestamp, and ensures age_min /
     * age_max are ordered correctly.
     * </summary>
     * <param name="row">A raw associative row as returned by PDO.</param>
     * <returns>The hydrated row.</returns>
     */
    private static function hydrate(array $row): array
    {
        $row['id']                 = (int)  $row['id'];
        $row['is_away']            = (bool) $row['is_away'];
        if (array_key_exists('notify_matches', $row)) $row['notify_matches'] = (bool) $row['notify_matches'];
        $row['default_radius_m']   = (int)  $row['default_radius_m'];
        $row['default_window_min'] = (int)  $row['default_window_min'];
        foreach (['age', 'age_min', 'age_max'] as $k) {
            $row[$k] = $row[$k] === null ? null : (int) $row[$k];
        }
        $row['email_verified'] = !empty($row['email_verified_at']);

        if (array_key_exists('car_seats', $row)) {
            $row['car_seats'] = $row['car_seats'] === null ? null : (int) $row['car_seats'];
        }
        if (array_key_exists('detour_m', $row))          $row['detour_m']          = (int) $row['detour_m'];

        // Enforce: if both set and reversed, swap so max >= min.
        if ($row['age_min'] !== null && $row['age_max'] !== null && $row['age_min'] > $row['age_max']) {
            [$row['age_min'], $row['age_max']] = [$row['age_max'], $row['age_min']];
        }
        return $row;
    }

    /**
     * <summary>
     * Report where on the verify-email timeline a user sits. The
     * caller is responsible for the actual gating; this helper just
     * reports state.
     * </summary>
     * <param name="user">A hydrated user row.</param>
     * <returns>One of "verified", "remind", "forced" or "disabled".</returns>
     * <remarks>
     * Buckets:
     *
     *   * verified: email_verified_at is set; no banner, no gate.
     *   * remind: unverified, age below REMIND_DAYS. Banner.
     *   * forced: unverified, age between REMIND_DAYS and FORCE_DAYS.
     *     App is blocked behind a full-screen "verify your email"
     *     gate.
     *   * disabled: explicitly disabled_at is set (either lazily by
     *     the front controller at FORCE_DAYS, or via admin action).
     *
     * banned_at is not mapped here. Bans are a separate admin-driven
     * state for misconduct. created_at comes from MySQL as
     * YYYY-MM-DD HH:MM:SS in UTC (Db.php pins the session tz to UTC
     * at connect time). A defensive fallback treats a missing
     * created_at as a fresh remind so a parse error never accidentally
     * locks someone out.
     * </remarks>
     */
    public static function verifyState(array $user): string
    {
        if (!empty($user['email_verified_at'])) return 'verified';
        if (!empty($user['disabled_at']))       return 'disabled';

        // created_at comes from MySQL as 'YYYY-MM-DD HH:MM:SS' in UTC
        // (Db.php pins the session tz to UTC at connect time, so this
        // is the right interpretation regardless of host timezone).
        $created = isset($user['created_at']) ? strtotime((string) $user['created_at'] . ' UTC') : false;
        if ($created === false) {
            // Defensive fallback. A row with no created_at shouldn't
            // exist in practice, treat as a fresh remind so we don't
            // accidentally lock someone out from a parse error.
            return 'remind';
        }
        $ageSec = time() - $created;
        if ($ageSec >= self::VERIFY_FORCE_DAYS  * 86400) return 'disabled';
        if ($ageSec >= self::VERIFY_REMIND_DAYS * 86400) return 'forced';
        return 'remind';
    }

    /**
     * <summary>
     * When this user's account will move from "remind" to "forced",
     * as an ISO 8601 string.
     * </summary>
     * <param name="user">A hydrated user row.</param>
     * <returns>An ISO 8601 timestamp or null when created_at is missing or unparseable.</returns>
     */
    public static function verifyForcedAt(array $user): ?string
    {
        if (empty($user['created_at'])) return null;
        $created = strtotime((string) $user['created_at'] . ' UTC');
        if ($created === false) return null;
        return gmdate('c', $created + self::VERIFY_REMIND_DAYS * 86400);
    }

    /**
     * <summary>
     * When this user's account will move from "forced" to "disabled",
     * as an ISO 8601 string.
     * </summary>
     * <param name="user">A hydrated user row.</param>
     * <returns>An ISO 8601 timestamp or null when created_at is missing or unparseable.</returns>
     */
    public static function verifyDisabledAt(array $user): ?string
    {
        if (empty($user['created_at'])) return null;
        $created = strtotime((string) $user['created_at'] . ' UTC');
        if ($created === false) return null;
        return gmdate('c', $created + self::VERIFY_FORCE_DAYS * 86400);
    }
}
