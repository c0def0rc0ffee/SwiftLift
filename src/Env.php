<?php
declare(strict_types=1);

namespace SwiftLift;

/**
 * <summary>
 * Minimal .env loader and accessor. Parses a KEY=VALUE file once, then
 * answers Env::get() lookups from that file or, failing that, the real
 * process environment.
 * </summary>
 * <remarks>
 * The loader ignores blank lines and lines starting with '#'. It is
 * intentionally tiny and does not handle quoting or escapes. The
 * load() call is idempotent.
 * </remarks>
 */
final class Env
{
    /**
     * <summary>
     * True once load() has run, so subsequent calls become no-ops.
     * </summary>
     */
    private static bool $loaded = false;
    /**
     * <summary>
     * Parsed key/value pairs from the .env file.
     * </summary>
     */
    private static array $values = [];

    /**
     * <summary>
     * Parse the given .env file into the in-memory store. No-op if it
     * has already run or the file is missing.
     * </summary>
     * <param name="path">Absolute filesystem path to the .env file to parse.</param>
     */
    public static function load(string $path): void
    {
        if (self::$loaded) return;
        self::$loaded = true;
        if (!is_file($path)) return;

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if ($line === '' || $line[0] === '#') continue;
            [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
            self::$values[trim($k)] = trim($v);
        }
    }

    /**
     * <summary>
     * Read a value from the loaded .env, then from the process
     * environment, then fall back to the supplied default.
     * </summary>
     * <param name="key">The variable name to look up.</param>
     * <param name="default">Returned when neither source yields a non-empty value.</param>
     * <returns>The resolved string value, or the default when nothing is set.</returns>
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        return self::$values[$key] ?? getenv($key) ?: $default;
    }

    /**
     * <summary>
     * Whether a secret is unusable as a gate: empty, still set to one of
     * the documented .env.example placeholders, or too short to resist
     * guessing.
     * </summary>
     * <param name="value">The configured secret.</param>
     * <returns>True when the value must not be accepted as a credential.</returns>
     * <remarks>
     * .env.example ships `change-me-to-a-long-random-string` for
     * SETUP_TOKEN and ADMIN_TOKEN. Those values are public, they are in
     * the repository, so an install where the operator skipped that step
     * would hand full schema-admin access to anyone who had read the
     * README. The token gates call this and refuse rather than trusting
     * hash_equals against a known string.
     * </remarks>
     */
    public static function isPlaceholderSecret(?string $value): bool
    {
        $v = trim((string) $value);
        if ($v === '') return true;
        if (strlen($v) < 16) return true;
        return stripos($v, 'change-me') !== false
            || stripos($v, 'changeme') !== false
            || stripos($v, 'your-') === 0;
    }
}
