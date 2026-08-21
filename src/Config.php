<?php
declare(strict_types=1);

namespace SwiftLift;

/**
 * <summary>
 * Hierarchical config lookup that prefers environment variables and
 * falls back to a PHP config file per group.
 * </summary>
 * <remarks>
 * Priority order is env vars (via Env or .env), then a PHP config file
 * like config/oauth.php, then null. Supporting both lets operators on
 * FTP-only hosts (where editing a dotfile is fiddly) drop a regular PHP
 * file in instead. Usage:
 *
 *   Config::get('oauth', 'facebook.app_id')
 *   Config::get('oauth', 'facebook.app_secret', $default = null)
 *
 * The first argument is the config group (which maps to a PHP file
 * name), the second is a dotted path inside the returned array. The
 * matching env-var name is constructed by upper-casing the path with
 * underscores, so "facebook.app_id" becomes FACEBOOK_APP_ID.
 * </remarks>
 */
final class Config
{
    /**
     * <summary>
     * In-memory cache of loaded config groups, keyed by group name.
     * </summary>
     * @var array<string, array<string, mixed>>
     */
    private static array $cache = [];

    /**
     * <summary>
     * Look up a config value, preferring the corresponding env var and
     * falling back to the PHP config file for the named group.
     * </summary>
     * <param name="group">The config group (maps to config/{group}.php).</param>
     * <param name="path">Dotted path inside the loaded array, e.g. "facebook.app_id".</param>
     * <param name="default">Returned when nothing is found at the requested path. Defaults to null.</param>
     * <returns>The resolved value, or the default if neither source supplies one.</returns>
     */
    public static function get(string $group, string $path, mixed $default = null): mixed
    {
        $envKey = strtoupper(str_replace('.', '_', $path));
        $envVal = Env::get($envKey);
        if ($envVal !== null && $envVal !== '') return $envVal;

        $cfg = self::load($group);
        $cur = $cfg;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($cur) || !array_key_exists($segment, $cur)) return $default;
            $cur = $cur[$segment];
        }
        return $cur === '' ? $default : $cur;
    }

    /**
     * <summary>
     * Load a config group's PHP file into the in-memory cache and return
     * its array. Missing or non-array files cache as an empty array.
     * </summary>
     * <param name="group">The config group name (and config/{group}.php file stem).</param>
     * <returns>The loaded associative array for the group, possibly empty.</returns>
     */
    private static function load(string $group): array
    {
        if (isset(self::$cache[$group])) return self::$cache[$group];

        $candidates = [
            dirname(__DIR__) . '/config/' . $group . '.php',
        ];
        foreach ($candidates as $file) {
            if (is_file($file)) {
                $value = require $file;
                self::$cache[$group] = is_array($value) ? $value : [];
                return self::$cache[$group];
            }
        }
        return self::$cache[$group] = [];
    }
}
