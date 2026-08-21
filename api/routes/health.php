<?php
declare(strict_types=1);

/**
 * <summary>
 * GET /api/health. Cheap liveness probe for UptimeRobot and similar monitors.
 * Returns 200 with { ok, db, ts } even when the database is down so the
 * caller can distinguish app up DB down from app down by inspecting the db field.
 * </summary>
 * <remarks>
 * No auth, no CSRF check (GETs skip the CSRF gate in api/index.php). A
 * 500 here would cause monitoring to flap on a transient DB hiccup, so
 * we deliberately swallow the error and report db=false instead. The
 * ts field is an ISO 8601 UTC timestamp useful for log correlation.
 * </remarks>
 */

use SwiftLift\{Db, Http};

Http::requireMethod('GET');

$dbOk = false;
try {
    Db::pdo()->query('SELECT 1')->fetchColumn();
    $dbOk = true;
} catch (\Throwable $e) {
    error_log('[SwiftLift] /api/health DB ping failed: ' . $e->getMessage());
}

Http::json([
    'ok' => true,
    'db' => $dbOk,
    'ts' => gmdate('c'),
]);
