<?php
declare(strict_types=1);

/**
 * <summary>
 * Front controller for the SwiftLift JSON API. Performs error handling
 * setup, CSRF origin enforcement, maintenance gating, diagnostic
 * endpoints, active session validation, and finally dispatches the
 * request to the matching file under api/routes/.
 * </summary>
 * <remarks>
 * Every state changing request is rejected unless its Origin (or Referer
 * fallback) exactly matches the host that served the page. Maintenance
 * mode shuts down everything except the diagnostic ping and the health
 * route so monitoring can still distinguish a controlled outage from
 * an actual fault. Authenticated routes carry an active session check
 * that lazily auto disables unverified accounts past the grace period
 * and evicts any session whose stored auth_epoch is stale after a
 * password change. A single wrap everything try/catch translates any
 * thrown error to a JSON 400 (deliberately not 500, which some shared
 * hosts intercept with a default ErrorDocument that swallows the body).
 * </remarks>
 */

/**
 * <summary>
 * Suppress browser visible PHP errors but keep them in the server log.
 * </summary>
 * <remarks>
 * A debug stance that surfaces problems through error_log while keeping
 * the JSON response clean. Will tighten further once the rewrite is
 * settled end to end.
 * </remarks>
 */
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/**
 * <summary>
 * Strip the X-Powered-By header regardless of whether mod_headers is loaded.
 * </summary>
 * <remarks>
 * Belt and braces against fingerprinting on shared hosting where the
 * Apache config may not include the usual scrubbing directives.
 * </remarks>
 */
header_remove('X-Powered-By');

/**
 * <summary>
 * CSRF defence. State changing requests must originate from our own host.
 * </summary>
 * <remarks>
 * Browsers always send Origin on cross origin POST, PATCH or DELETE.
 * For some same origin POSTs the Origin header may be missing, in which
 * case Referer is consulted as a fallback. The check is an EXACT origin
 * string equality against the page's own scheme://host[:port]. An
 * earlier revision used stripos(...) === 0 which was a prefix match;
 * a hostile origin like https://swiftlift.gg.evil.com matched the
 * https://swiftlift.gg prefix and slipped through.
 * </remarks>
 */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    /**
     * <summary>
     * Derive the allowed origin from the connection's own HTTPS flag.
     * </summary>
     * <remarks>
     * Only trust $_SERVER['HTTPS']. The older code also accepted
     * HTTP_X_FORWARDED_PROTO, but on shared hosting that header is
     * attacker controlled. A request could claim https while the
     * actual Origin was http, mismatching $allowed in ways that
     * occasionally let cross origin requests through.
     * </remarks>
     */
    $scheme  = (($_SERVER['HTTPS'] ?? 'off') === 'on') ? 'https' : 'http';
    $host    = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $allowed = $scheme . '://' . $host;
    $origin  = (string) ($_SERVER['HTTP_ORIGIN']  ?? '');
    $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');

    /**
     * <summary>
     * Reduce a URL to its origin form, scheme://host[:port], for comparison.
     * </summary>
     * <param name="url">The absolute URL to reduce.</param>
     * <returns>The origin substring, or empty string if input does not parse as absolute.</returns>
     * <remarks>
     * Used to compare Referer against the expected origin since Referer
     * carries a full URL with path and query.
     * </remarks>
     */
    $toOrigin = static function (string $url): string {
        if ($url === '') return '';
        $p = parse_url($url);
        if (!is_array($p) || !isset($p['scheme'], $p['host'])) return '';
        $o = strtolower($p['scheme']) . '://' . $p['host'];
        if (isset($p['port'])) $o .= ':' . $p['port'];
        return $o;
    };

    /**
     * <summary>
     * Decide whether the request's apparent origin matches the served host.
     * </summary>
     * <remarks>
     * Prefer the Origin header when present, since it is always pure
     * scheme://host[:port] with no path. If Origin is absent (some
     * same origin requests omit it), reduce Referer to its origin
     * before comparing. A missing Host or both indicators absent
     * leaves $ok false and the request is rejected.
     * </remarks>
     */
    $ok = false;
    if ($host !== '') {
        if ($origin !== '') {
            $ok = strcasecmp($origin, $allowed) === 0;
        } elseif ($referer !== '') {
            $ok = strcasecmp($toOrigin($referer), $allowed) === 0;
        }
    }
    if (!$ok) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'error'         => 'Cross-origin request blocked',
            'expected_host' => $host,
            'got_origin'    => $origin ?: null,
        ]);
        exit;
    }
}

/**
 * <summary>
 * Single wrap everything try/catch around bootstrap, routing, and dispatch.
 * </summary>
 * <remarks>
 * Avoids clever error handlers that can themselves fail. Anything thrown
 * inside is caught at the bottom of the file and translated into a JSON
 * 400 response.
 * </remarks>
 */
try {
    require __DIR__ . '/../src/bootstrap.php';

    /**
     * <summary>
     * Resolve the requested route from either the ?p= query parameter or the URL path.
     * </summary>
     * <remarks>
     * Query string form is reliable across every shared host configuration.
     * .htaccess rewrites sometimes drop POST bodies, so we accept both
     * shapes and normalise to a slash trimmed route key.
     * </remarks>
     */
    if (isset($_GET['p'])) {
        $path = trim((string) $_GET['p'], '/');
    } else {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
        $path = preg_replace('#^.*?/api/?#', '', $path) ?: '';
        $path = trim($path, '/');
    }

    /**
     * <summary>
     * Maintenance gate. Short circuit every route with a 503 when MAINTENANCE_MODE is on.
     * </summary>
     * <remarks>
     * The diagnostic ping and health endpoints are exempt so monitoring
     * can still distinguish a controlled outage from an actual fault.
     * The SPA detects the 503 on any API response and renders the
     * MaintenancePage shell instead of the app. The Retry-After header
     * is a hint to well behaved clients.
     * </remarks>
     */
    $maintenance = filter_var(\SwiftLift\Env::get('MAINTENANCE_MODE', '0'), FILTER_VALIDATE_BOOLEAN);
    $maintenanceBypass = in_array($path, ['__ping', 'health'], true);
    if ($maintenance && !$maintenanceBypass) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        header('Retry-After: 60');
        echo json_encode([
            'maintenance' => true,
            'message'     => \SwiftLift\Env::get('MAINTENANCE_MESSAGE')
                ?: "SwiftLift is doing a quick bit of maintenance. We'll be back shortly.",
            'error'       => 'maintenance',
        ]);
        exit;
    }

    /**
     * <summary>
     * Diagnostic endpoints written in plain PHP with no framework dependencies.
     * </summary>
     * <remarks>
     * __ping is harmless and just confirms PHP is running, so it stays
     * open. The others reveal internal config (env presence, table row
     * counts) and are gated behind APP_DEBUG=1 in .env so anyone who
     * stumbles on the path cannot enumerate them.
     * </remarks>
     */

    /**
     * <summary>
     * GET /api/__ping. Unauthenticated liveness check.
     * </summary>
     * <remarks>
     * Always open. Used by ad hoc curl checks and as a sanity test from
     * the SPA when nothing else seems to respond. Returns only a bare
     * ok flag: the PHP version and SAPI are deliberately withheld so an
     * unauthenticated probe can't fingerprint the runtime to look for
     * version-specific exploits. The version is still available behind
     * the APP_DEBUG-gated __env endpoint below.
     * </remarks>
     */
    if ($path === '__ping') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true]);
        exit;
    }

    $debugMode = filter_var(\SwiftLift\Env::get('APP_DEBUG', '0'), FILTER_VALIDATE_BOOLEAN);

    /**
     * <summary>
     * GET /api/__env. Reports which .env file was loaded and which DB settings are present.
     * </summary>
     * <remarks>
     * Booleans only, never the values themselves. Gated behind APP_DEBUG
     * because even presence/absence of credentials is useful intel to an
     * attacker probing a misconfigured install.
     * </remarks>
     */
    if ($path === '__env') {
        if (!$debugMode) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Diagnostic endpoint disabled. Set APP_DEBUG=1 in .env to enable.']);
            exit;
        }
        header('Content-Type: application/json; charset=utf-8');
        $webroot = dirname(__DIR__);
        $envAbove  = dirname($webroot) . '/.env';
        $envInside = $webroot . '/.env';
        echo json_encode([
            'php'                  => PHP_VERSION,
            'env_file_above_root'  => is_file($envAbove),
            'env_file_in_webroot'  => is_file($envInside),
            'db_host_set'          => (bool) \SwiftLift\Env::get('DB_HOST'),
            'db_name_set'          => (bool) \SwiftLift\Env::get('DB_NAME'),
            'db_user_set'          => (bool) \SwiftLift\Env::get('DB_USER'),
            'db_pass_set'          => (bool) \SwiftLift\Env::get('DB_PASS'),
        ]);
        exit;
    }

    /**
     * <summary>
     * GET /api/__dbtest. Confirms DB connectivity and returns the users row count.
     * </summary>
     * <remarks>
     * Gated behind APP_DEBUG. A trivial sanity test that the connection
     * pool, credentials, and schema are present without exposing any
     * actual data.
     * </remarks>
     */
    if ($path === '__dbtest') {
        if (!$debugMode) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'Diagnostic endpoint disabled. Set APP_DEBUG=1 in .env to enable.']);
            exit;
        }
        $pdo   = \SwiftLift\Db::pdo();
        $users = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'users' => $users]);
        exit;
    }

    /**
     * <summary>
     * Active session check. Evict any session belonging to a banned, deleted, disabled, or stale epoch user.
     * </summary>
     * <remarks>
     * Three jobs in one block. First, if the visitor's session cookie
     * points at a tombstoned identity we wipe the session so the next
     * call gets a clean 401 rather than letting them keep acting under
     * it. Second, the auth_epoch comparison evicts sessions whose stored
     * epoch is older than the user row, which is how a password reset
     * actually logs other devices out instead of leaving stale cookies
     * alive until expiry. Third, unverified users past the FORCE_DAYS
     * threshold are lazily stamped disabled_at on the spot, so every
     * authenticated request gets a chance to make that transition
     * without relying on a cron job. Skipped for the auth routes and
     * the cheap diagnostic or log endpoints, all of which are
     * unauthenticated by design.
     * </remarks>
     */
    $isAuthRoute  = str_starts_with($path, 'auth/') || $path === 'auth';
    $isCheapRoute = in_array($path, ['health', 'log-error', 'unsubscribe'], true) || str_starts_with($path, '__');
    if (!$isAuthRoute && !$isCheapRoute) {
        \SwiftLift\Auth::start();
        $sessUid = $_SESSION['uid'] ?? null;
        if ($sessUid !== null) {
            /**
             * <summary>
             * Pull the user's gating columns so we can compare against the session snapshot.
             * </summary>
             * <remarks>
             * COALESCE around auth_epoch covers installs that have not
             * yet run the column migration added by setup.php's
             * runColumnMigrations. disabled_at is the auto disable
             * column, distinct from banned_at which records an admin
             * misconduct action.
             * </remarks>
             */
            $stmt = \SwiftLift\Db::pdo()->prepare(
                "SELECT email_verified_at, created_at, banned_at, disabled_at, deleted_at, COALESCE(auth_epoch, 0) AS auth_epoch FROM users WHERE id = ?"
            );
            $stmt->execute([(int) $sessUid]);
            $row = $stmt->fetch();
            $sessEpoch = (int) ($_SESSION['auth_epoch'] ?? 0);
            $kick = !$row
                 || $row['deleted_at']
                 || $row['banned_at']
                 || $row['disabled_at']
                 || (int) $row['auth_epoch'] !== $sessEpoch;

            /**
             * <summary>
             * Lazy auto disable for unverified accounts past the FORCE_DAYS threshold.
             * </summary>
             * <remarks>
             * Stamps disabled_at on the spot so the standard disabled
             * account machinery takes over from here on. We deliberately
             * do not rely on a cron job: every authenticated request
             * gets a chance to make this transition. When the verify
             * state is "forced" we hard gate the app until the user
             * verifies, but still allow profile read and update so they
             * can correct a typo in their email. auth/* routes were
             * already excluded above.
             * </remarks>
             */
            if (!$kick && $row && empty($row['email_verified_at'])) {
                $vs = \SwiftLift\UserRepo::verifyState($row);
                if ($vs === 'disabled') {
                    \SwiftLift\Db::pdo()
                        ->prepare("UPDATE users SET disabled_at = CURRENT_TIMESTAMP WHERE id = ? AND disabled_at IS NULL")
                        ->execute([(int) $sessUid]);
                    $kick = true;
                } elseif ($vs === 'forced') {
                    if ($path !== 'profile') {
                        http_response_code(403);
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode([
                            'error'        => 'Email verification required. Open the link in your inbox, or use Resend.',
                            'verify_state' => 'forced',
                        ]);
                        exit;
                    }
                }
            }

            if ($kick) {
                \SwiftLift\Auth::logout();
            }
        }
    }

    /**
     * <summary>
     * Route table mapping route keys to the handler files under api/routes/.
     * </summary>
     * <remarks>
     * Both an exact match and a "starts with key/" match are accepted,
     * with the remainder placed in $_GET['_tail'] for the handler to
     * dispatch on. The forgot and reset password handlers share the
     * auth_verify file because the three flows are tightly coupled.
     * </remarks>
     */
    $routes = [
        'auth/register'  => __DIR__ . '/routes/auth_register.php',
        'auth/login'     => __DIR__ . '/routes/auth_login.php',
        'auth/logout'    => __DIR__ . '/routes/auth_logout.php',
        'auth/me'        => __DIR__ . '/routes/auth_me.php',
        'auth/verify'    => __DIR__ . '/routes/auth_verify.php',
        'auth/forgot'    => __DIR__ . '/routes/auth_verify.php',
        'auth/reset'     => __DIR__ . '/routes/auth_verify.php',
        'auth/oauth'     => __DIR__ . '/routes/auth_oauth.php',
        'profile'        => __DIR__ . '/routes/profile.php',
        'journeys'       => __DIR__ . '/routes/journeys.php',
        'groups'         => __DIR__ . '/routes/groups.php',
        'matches'        => __DIR__ . '/routes/matches.php',
        'lift-requests'  => __DIR__ . '/routes/lift_requests.php',
        'messages'       => __DIR__ . '/routes/messages.php',
        'blocks'         => __DIR__ . '/routes/blocks.php',
        'reports'        => __DIR__ . '/routes/reports.php',
        'issues'         => __DIR__ . '/routes/issues.php',
        'log-error'      => __DIR__ . '/routes/log_error.php',
        'health'         => __DIR__ . '/routes/health.php',
        'unsubscribe'    => __DIR__ . '/routes/unsubscribe.php',
    ];

    /**
     * <summary>
     * Dispatch loop. Find the first matching route key and require the handler file.
     * </summary>
     * <remarks>
     * The tail (everything after the matched key) is published in
     * $_GET['_tail'] for the route file to branch on. If no key
     * matches, the handler falls through to a JSON 404.
     * </remarks>
     */
    foreach ($routes as $key => $file) {
        if ($path === $key || strpos($path, $key . '/') === 0) {
            $_GET['_tail'] = ltrim(substr($path, strlen($key)), '/');
            require $file;
            exit;
        }
    }

    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    // Don't echo the requested path back: reflecting it aids route
    // enumeration and gives any downstream consumer an injection surface.
    echo json_encode(['error' => 'Not found']);

} catch (\Throwable $e) {
    /**
     * <summary>
     * Translate any uncaught throwable into a JSON 400 response.
     * </summary>
     * <remarks>
     * Status 400 rather than 500 because some shared hosts intercept
     * 500 with a default ErrorDocument that swallows our JSON body.
     * In production we return a generic message: raw exception strings
     * can leak column or table names from PDO, file paths, or other
     * internal details, and the real message is still recorded in the
     * server error log for the operator. Deliberately thrown
     * RuntimeExceptions are treated as user safe business errors
     * ("Email already in use" and so on), but PDOException, which
     * inherits from RuntimeException, has to be excluded explicitly
     * because its messages routinely contain table or column names.
     * </remarks>
     */
    if (!headers_sent()) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
    }
    error_log('[SwiftLift] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $debug = filter_var(\SwiftLift\Env::get('APP_DEBUG', '0'), FILTER_VALIDATE_BOOLEAN);
    $isBusinessError = ($e instanceof \RuntimeException) && !($e instanceof \PDOException);
    $payload = [
        'error' => ($debug || $isBusinessError) ? $e->getMessage() : 'Something went wrong. Please try again.',
    ];
    if ($debug) {
        $payload['type'] = get_class($e);
        $payload['file'] = basename($e->getFile());
        $payload['line'] = $e->getLine();
    }
    echo json_encode($payload);
}
