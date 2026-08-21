<?php
declare(strict_types=1);

namespace SwiftLift;

/**
 * <summary>
 * Single source of truth for the app's version. Surfaced on the
 * About page and used as the default for fixed_in_version when an
 * admin marks an issue as fixed.
 * </summary>
 * <remarks>
 * Bump rules (semver-lite):
 *
 *   * MAJOR for anything that needs a manual op step at deploy time
 *     (schema migration the operator must trigger, breaking API
 *     change that the SPA cannot tolerate, etc.).
 *   * MINOR for new user-visible features that work after a normal
 *     redeploy.
 *   * PATCH for bug fixes and internal hardening; users do not see
 *     anything new.
 *
 * Bump the constant in this file before you deploy, in the same
 * commit as the changes the bump describes. That way Version::CURRENT
 * always matches what is running.
 * </remarks>
 */
final class Version
{
    /**
     * <summary>
     * Compiled-in baseline version. Used as the fallback when no version
     * has been set in the database (fresh install, or before update.php
     * has ever run).
     * </summary>
     */
    public const CURRENT = '1.1.0';

    /** Per-request cache so repeated lookups don't re-hit the database. */
    private static ?string $cached = null;

    /**
     * <summary>
     * The live application version: the value stored in app_meta by
     * update.php, falling back to the <see cref="CURRENT"/> constant.
     * </summary>
     * <returns>The current version string.</returns>
     * <remarks>
     * Reads app_meta['version']. If the table or row is missing (older
     * install, or update.php never run) it returns the constant, so this
     * is always safe to call. Result is cached for the request.
     * </remarks>
     */
    public static function current(): string
    {
        if (self::$cached !== null) return self::$cached;
        try {
            $v = Db::pdo()->query("SELECT v FROM app_meta WHERE k = 'version'")->fetchColumn();
            if (is_string($v) && $v !== '') return self::$cached = $v;
        } catch (\Throwable $e) {
            // app_meta may not exist yet; fall back to the baseline.
        }
        return self::$cached = self::CURRENT;
    }
}
