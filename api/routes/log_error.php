<?php
declare(strict_types=1);

/**
 * <summary>
 * POST /api/log-error. Receives a JavaScript error report from the SPA
 * (boundary caught or hand raised) and appends one JSON line per report
 * to a rolling monthly file the operator can FTP down.
 * </summary>
 * <remarks>
 * Unauthenticated by design because errors happen before login, during
 * logout, and on the marketing pages. Rate limited per IP (40 per
 * hour) so it cannot be turned into a denial of service or disk fill
 * vector by a hostile client. Each entry is truncated to MAX_LINE
 * bytes; the file is rotated by month (errors-YYYY-MM.log) to keep
 * things tidy, and once any file passes the 50 MB cap the route
 * returns logged=false rather than continuing to grow disk usage on
 * shared hosting. The CSRF origin check in api/index.php still
 * applies; same origin Beacon or fetch calls carry a matching Origin
 * header so they pass.
 * </remarks>
 */

use SwiftLift\{Http, RateLimit};

Http::requireMethod('POST');

/**
 * <summary>
 * Apply the 40 per IP per hour rate limit.
 * </summary>
 * <remarks>
 * Generous enough that a real bug storm comes through, tight enough
 * that a single bad actor cannot fill the disk on their own.
 * </remarks>
 */
RateLimit::guard('log_error', 40, 3600);
RateLimit::record('log_error');

$body = Http::body();

$msg = (string) ($body['message']        ?? '');
$stk = (string) ($body['stack']          ?? '');
$cs  = (string) ($body['componentStack'] ?? '');
$url = (string) ($body['url']            ?? '');
$ua  = (string) ($body['ua']             ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''));
$ts  = (string) ($body['ts']             ?? gmdate('c'));

$ip  = $_SERVER['REMOTE_ADDR'] ?? '?';

/**
 * <summary>
 * Replace control characters (including newlines) with spaces.
 * </summary>
 * <param name="s">The raw string to sanitise.</param>
 * <returns>The sanitised string with control characters collapsed to spaces.</returns>
 * <remarks>
 * Keeps each log entry to a single line so each report stays one row
 * in the file. Anything in U+0000..U+001F or U+007F gets folded.
 * </remarks>
 */
$strip = static fn(string $s): string => preg_replace('/[\x00-\x1f\x7f]+/', ' ', $s) ?? '';

$entry = json_encode([
    'ts'  => $strip($ts),
    'ip'  => $ip,
    'url' => $strip(substr($url, 0, 500)),
    'ua'  => $strip(substr($ua,  0, 300)),
    'msg' => $strip(substr($msg, 0, 500)),
    'stk' => $strip(substr($stk, 0, 4000)),
    'cs'  => $strip(substr($cs,  0, 2000)),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if ($entry === false) {
    Http::error('Bad JSON payload');
}

$MAX_LINE = 8192;
if (strlen($entry) > $MAX_LINE) $entry = substr($entry, 0, $MAX_LINE);

/**
 * <summary>
 * Ensure the monthly errors directory exists, then resolve the current file.
 * </summary>
 * <remarks>
 * One file per month, kept under storage/errorlog/. The parent
 * storage/ directory is the same one the file fallback Mailer uses,
 * so it usually already exists on installs that have sent any mail.
 * </remarks>
 */
$root = dirname(__DIR__, 2);
$dir  = $root . '/storage/errorlog';
if (!is_dir($dir)) @mkdir($dir, 0775, true);

$file = $dir . '/errors-' . gmdate('Y-m') . '.log';

/**
 * <summary>
 * Hard cap of 50 MB per monthly file.
 * </summary>
 * <remarks>
 * Keeps a distributed botnet from filling shared host disk by
 * rotating IPs. Without the cap, the arithmetic is roughly 40 per
 * IP per hour times 1000 IPs times 8 KB which is around 7.7 GB per
 * month. Beyond the cap we drop the report; the operator's storage
 * stays intact and the SPA's error boundary still recovers.
 * </remarks>
 */
$MAX_FILE = 50 * 1024 * 1024; // 50 MB
if (is_file($file) && filesize($file) >= $MAX_FILE) {
    Http::json(['logged' => false, 'reason' => 'log_full']);
}

/**
 * <summary>
 * Append the JSON entry to the monthly file with an exclusive lock.
 * </summary>
 * <remarks>
 * LOCK_EX prevents concurrent writers from interleaving half lines,
 * which would corrupt the JSON parsing later when the operator pulls
 * the log down.
 * </remarks>
 */
@file_put_contents($file, $entry . "\n", FILE_APPEND | LOCK_EX);

Http::json(['logged' => true]);
