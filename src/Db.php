<?php
declare(strict_types=1);

namespace SwiftLift;

use PDO;

/**
 * <summary>
 * Singleton accessor for the application's PDO handle. Reads connection
 * settings from env (DB_HOST, DB_NAME, DB_USER, DB_PASS) and pins the
 * session timezone to UTC so timestamps line up with PHP's UTC clock.
 * </summary>
 * <remarks>
 * Configures PDO with exception error mode, associative fetch as the
 * default, and prepared statement emulation off so type-strict
 * parameters work properly. The connection is created on first use and
 * reused thereafter.
 * </remarks>
 */
final class Db
{
    /**
     * <summary>
     * Cached PDO handle. Null until the first call to pdo().
     * </summary>
     */
    private static ?PDO $pdo = null;

    /**
     * <summary>
     * Return the shared PDO handle, creating it on first use.
     * </summary>
     * <returns>The application's PDO connection, configured with UTF-8mb4 and the UTC session timezone.</returns>
     * <remarks>
     * The session timezone is pinned to UTC via "SET time_zone =
     * '+00:00'" so CURRENT_TIMESTAMP, NOW() and timestamp columns line
     * up with PHP's gmdate() and strtotime('UTC') calls. Hosts that run
     * MySQL in a regional timezone (IONOS shared hosting often defaults
     * to Europe/Berlin) would otherwise produce values one or two hours
     * ahead of PHP, breaking relative-time arithmetic. The numeric
     * offset form is used because some MySQL installs lack the time
     * zone description tables; the catch is belt and braces for an even
     * weirder edge case where SET time_zone fails entirely.
     * </remarks>
     */
    public static function pdo(): PDO
    {
        if (self::$pdo !== null) return self::$pdo;

        $host = Env::get('DB_HOST', 'localhost');
        $name = Env::get('DB_NAME', 'swiftlift');
        $user = Env::get('DB_USER', 'root');
        $pass = Env::get('DB_PASS', '');

        $dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
        self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        // Pin the connection's session timezone to UTC so CURRENT_TIMESTAMP /
        // NOW() and timestamp columns all use the same clock as the PHP
        // code that parses them. Without this, hosts that run MySQL in
        // a regional timezone (IONOS shared hosting often defaults to
        // Europe/Berlin or local) produce values 1-2 hours ahead of PHP's
        // gmdate(), which makes "X seconds ago" calculations either go
        // negative (clamped to 0 in admin.php) or show wildly wrong
        // relative times. UTC at the DB layer and gmdate()/strtotime
        // 'UTC' in PHP keeps both sides on the same clock.
        try {
            self::$pdo->exec("SET time_zone = '+00:00'");
        } catch (\Throwable $e) {
            // Some MySQL installs reject SET time_zone if the time zone
            // tables haven't been loaded. The numeric offset form above
            // works without those tables, so this catch is belt-and-
            // suspenders for an even weirder edge case.
        }

        return self::$pdo;
    }
}
