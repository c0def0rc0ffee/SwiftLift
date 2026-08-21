<?php
declare(strict_types=1);

/**
 * <summary>
 * SwiftLift admin dashboard. Token gated, plain server rendered HTML, no
 * React. Provides the operator with user management, message inspection,
 * abuse report triage and the public changelog (issues) editor.
 * </summary>
 * <remarks>
 * Two ways in:
 *   1. /admin.php?token=XXX where XXX matches the ADMIN_TOKEN env var.
 *      Bookmarkable, useful for ops access.
 *   2. Sign in to the React app as a user with users.is_admin = 1, then
 *      visit /admin.php in the same browser. The session cookie is
 *      enough, no token needed.
 *
 * Views, switched by ?view=:
 *   ?view=users    (default) list of users with sort options
 *   ?view=messages every message in the system with optional user filter
 *   ?view=reports  user reports with ban/unban controls
 *   ?view=issues   public changelog and roadmap editor
 *
 * Plain server rendered HTML on purpose:
 *   One operator, one device, sees this every few days.
 *   Trivial to delete and rebuild later as the real admin grows.
 *   Smaller blast radius than wiring an admin section into the React app.
 *
 * Unauthorised visitors get a generic 404 rather than a 401 so the URL
 * itself is hidden from anyone probing the site.
 * </remarks>
 */

require __DIR__ . '/src/bootstrap.php';

use SwiftLift\{Auth, Db, Env, IssueRepo, UserRepo, Version};

// ---- Auth ----------------------------------------------------------------

/**
 * <summary>
 * Section: authentication. Enforces that the visitor is either an admin via
 * session cookie or has supplied the matching ADMIN_TOKEN, and gives the
 * operator a one shot bootstrap path to grant the first ever admin flag.
 * </summary>
 * <remarks>
 * One time bootstrap: if no admin user exists yet, promote a given email to
 * is_admin=1. Once any user has is_admin=1 the path is permanently dead.
 * Useful right after first deploy when neither a token nor an admin session
 * exists yet.
 *
 * Authentication is required to use the bootstrap path. The older version
 * was unauthenticated, which created a race where anyone hitting
 * /admin.php?bootstrap=email between the first deploy and the operator
 * running it would become admin. We now require either:
 *   ?token=&lt;ADMIN_TOKEN&gt; matching the env value, or
 *   a logged in session whose user owns the email being promoted.
 * </remarks>
 */

/**
 * <summary>
 * Emits a generic 404 Not Found response and exits, used to hide the
 * existence of admin.php from anyone unauthorised.
 * </summary>
 * <returns>
 * Never returns; calls exit. The PHP "never" return type makes this
 * explicit to the type checker so callers do not need a trailing return.
 * </returns>
 * <remarks>
 * The page is rendered to look like a plain missing file. There is no hint
 * that admin.php exists, and no clue about what credentials would unlock
 * it. The operator already knows what they need.
 * </remarks>
 */
$denyAsNotFound = static function (): never {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><html><head><title>404 Not Found</title></head>"
       . "<body><h1>Not Found</h1><p>The requested URL was not found on this server.</p></body></html>";
    exit;
};

/**
 * <summary>
 * Action handler for the one shot bootstrap path. Promotes a given email
 * to is_admin=1, but only while no admin currently exists, and only when
 * the caller can prove they are authorised via token or session ownership.
 * </summary>
 * <remarks>
 * Once any user has is_admin=1 the path becomes permanently dead and falls
 * through to the normal access check below. Errors are logged but the
 * response is always a generic 404 so probing visitors cannot detect the
 * feature.
 * </remarks>
 */
if (!empty($_GET['bootstrap'])) {
    try {
        $email = strtolower(trim((string) $_GET['bootstrap']));

        // Path 1: ADMIN_TOKEN match.
        $expectedToken = (string) (Env::get('ADMIN_TOKEN') ?? '');
        $suppliedToken = (string) ($_GET['token'] ?? '');
        // A placeholder or too-short token is not a credential: the example
        // value is published in the repository.
        $tokenOk = (!Env::isPlaceholderSecret($expectedToken)
                    && hash_equals($expectedToken, $suppliedToken));

        // Path 2: session belongs to the email being promoted.
        $sessionOk = false;
        if (!$tokenOk) {
            Auth::start();
            $sessUid = $_SESSION['uid'] ?? null;
            if ($sessUid !== null) {
                $s = Db::pdo()->prepare("SELECT email FROM users WHERE id = ? AND deleted_at IS NULL AND banned_at IS NULL AND disabled_at IS NULL");
                $s->execute([(int) $sessUid]);
                $sessEmail = (string) ($s->fetchColumn() ?: '');
                $sessionOk = ($sessEmail !== '' && strcasecmp($sessEmail, $email) === 0);
            }
        }

        if (!$tokenOk && !$sessionOk) {
            // Generic 404. Do not reveal that bootstrap exists or what
            // criteria gate it. The operator already knows.
            $denyAsNotFound();
        }

        $hasAdmin = (int) Db::pdo()->query("SELECT COUNT(*) FROM users WHERE is_admin = 1")->fetchColumn();
        if ($hasAdmin === 0) {
            $stmt  = Db::pdo()->prepare("UPDATE users SET is_admin = 1 WHERE email = ? AND deleted_at IS NULL");
            $stmt->execute([$email]);
            $rows = $stmt->rowCount();
            header('Content-Type: text/plain');
            if ($rows > 0) {
                exit("Promoted $email to admin. Sign in to the React app as that user, then visit /admin.php.\n");
            }
            exit("No matching active user found for $email. Register the account first, then re-run the bootstrap.\n");
        }
        // hasAdmin > 0. Bootstrap is permanently dead. Fall through to
        // the normal access check below, which will 404 if you are not
        // already an admin via token or session.
    } catch (\Throwable $e) {
        // Log the real error for the operator, but still hide the
        // admin path from anyone probing.
        error_log('[SwiftLift] admin bootstrap error: ' . $e->getMessage());
        $denyAsNotFound();
    }
}

/**
 * <summary>
 * Main access gate. The visitor must either be a logged in user whose
 * users.is_admin row is 1, or hold an admin session previously established
 * via a one-shot ADMIN_TOKEN login. Unauthorised visitors get a generic
 * 404, matching the bootstrap path above.
 * </summary>
 * <remarks>
 * The ADMIN_TOKEN is accepted ONLY from the query string on a GET request,
 * and only to set a session flag, after which we immediately redirect to
 * the same URL with the token stripped. This keeps the secret out of
 * browser history, server access logs, and the Referer header sent to
 * outbound resources (fonts, etc). All subsequent auth, including every
 * mutating POST, rides on the session cookie. Token comparison uses
 * hash_equals so timing cannot reveal the value.
 * </remarks>
 */
Auth::start();

$expected = (string) (Env::get('ADMIN_TOKEN') ?? '');
$supplied = (string) ($_GET['token'] ?? '');
// A placeholder or too-short token is not a credential: the example value
// is published in the repository.
if ($_SERVER['REQUEST_METHOD'] === 'GET'
    && !Env::isPlaceholderSecret($expected) && hash_equals($expected, $supplied)) {
    // One-shot token login: remember it in the session, then bounce to the
    // same URL without the token so it never lingers anywhere visible.
    $_SESSION['admin_token_ok'] = true;
    $clean = $_GET;
    unset($clean['token']);
    $self = strtok((string) $_SERVER['REQUEST_URI'], '?');
    header('Location: ' . $self . ($clean ? '?' . http_build_query($clean) : ''));
    exit;
}

// Admin via a prior one-shot token login.
$tokenAdmin = !empty($_SESSION['admin_token_ok']);

// Session admin: visitor logged into the React app and their users row
// has is_admin = 1.
$sessionAdmin = false;
$sessionUid   = $_SESSION['uid'] ?? null;
if ($sessionUid !== null) {
    try {
        $stmt = Db::pdo()->prepare("SELECT is_admin FROM users WHERE id = ? AND deleted_at IS NULL AND banned_at IS NULL AND disabled_at IS NULL");
        $stmt->execute([(int) $sessionUid]);
        $sessionAdmin = (bool) $stmt->fetchColumn();
    } catch (\Throwable $e) { /* swallow */ }
}

if (!$tokenAdmin && !$sessionAdmin) {
    // Hide admin.php from anyone unauthorised. Generic 404 (defined
    // earlier in this file) gives no hint about the URL existence or
    // what credentials would unlock it.
    $denyAsNotFound();
}

/**
 * <summary>
 * Per-session CSRF token. Embedded as a hidden field in every mutating
 * form and validated in the action dispatcher below, so a cross-site page
 * cannot drive admin actions (ban, toggle_admin, delete, etc) even if the
 * operator's session cookie rides along.
 * </summary>
 */
if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string) $_SESSION['admin_csrf'];

// ---- Action handlers (POST only) -----------------------------------------

/**
 * <summary>
 * Section: action dispatcher. Every mutating operation on the dashboard
 * arrives as a POST with an action field; this block routes to the right
 * UserRepo / IssueRepo / direct SQL call and accumulates a one line $flash
 * message to render at the top of the next page load.
 * </summary>
 * <remarks>
 * Two top level groups: user actions (ban, unban, disable, enable,
 * dismiss_report, toggle_admin) keyed on a non zero user_id, and issue
 * actions (issue_create, issue_update, issue_delete) that operate on the
 * issues table directly. Exceptions from the issue path are caught and
 * folded into $flash so the operator sees a clean error message instead
 * of a fatal.
 * </remarks>
 */
$flash = null;
// CSRF guard: every mutating POST must echo back the per-session token.
// A request that fails the check is dropped before any action runs.
$csrfOk = hash_equals($csrf, (string) ($_POST['csrf'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$csrfOk) {
    $flash = 'Security check failed. Reload the page and try again.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $csrfOk) {
    $action = (string) ($_POST['action']  ?? '');
    $userId = (int)    ($_POST['user_id'] ?? 0);

    if ($userId > 0) {
        switch ($action) {
            /**
             * <summary>
             * Bans the named user. They will be logged out on their next
             * request and locked out of sign in until unbanned. Used for
             * misconduct, distinct from the recoverable disable path.
             * </summary>
             */
            case 'ban':
                UserRepo::setBanned($userId, true);
                $flash = "Banned user #$userId. They will be logged out on their next request.";
                break;
            /**
             * <summary>Lifts a ban on the named user.</summary>
             */
            case 'unban':
                UserRepo::setBanned($userId, false);
                $flash = "Unbanned user #$userId.";
                break;
            /**
             * <summary>
             * Disables the named user. Soft suspension intended for cases
             * like an unverified email past deadline. Recoverable via
             * reactivate.
             * </summary>
             */
            case 'disable':
                UserRepo::setDisabled($userId, true);
                $flash = "Disabled user #$userId. They'll be logged out on the next request and won't be able to sign in until reactivated.";
                break;
            /**
             * <summary>Reactivates a previously disabled user.</summary>
             */
            case 'enable':
                UserRepo::setDisabled($userId, false);
                $flash = "Reactivated user #$userId.";
                break;
            /**
             * <summary>
             * Marks an abuse report as resolved without taking action
             * against the target. Stamps user_reports.resolved_at.
             * </summary>
             */
            case 'dismiss_report':
                $reportId = (int) ($_POST['report_id'] ?? 0);
                if ($reportId > 0) {
                    Db::pdo()->prepare("UPDATE user_reports SET resolved_at = CURRENT_TIMESTAMP WHERE id = ?")
                        ->execute([$reportId]);
                    $flash = "Dismissed report #$reportId.";
                }
                break;
            /**
             * <summary>
             * Flips the is_admin flag on the named user, granting or
             * revoking access to this dashboard. Skips users that have
             * been soft deleted.
             * </summary>
             * <remarks>
             * Uses the SQL idiom 1 - is_admin so the call is symmetric
             * (one statement promotes or demotes depending on current
             * value) without an extra read first.
             * </remarks>
             */
            case 'toggle_admin':
                $stmt = Db::pdo()->prepare("UPDATE users SET is_admin = 1 - is_admin WHERE id = ? AND deleted_at IS NULL");
                $stmt->execute([$userId]);
                $flash = "Toggled admin flag on user #$userId.";
                break;
        }
    }

    /**
     * <summary>
     * Issue tracker actions. No user_id required for these; they operate
     * on the issues table directly via IssueRepo.
     * </summary>
     */
    switch ($action) {
        /**
         * <summary>
         * Creates a new public changelog or roadmap issue from the form
         * fields, with kind, title, description, status, severity, target
         * version, fixed in version, public visibility and sort order all
         * mapped one to one from POST.
         * </summary>
         */
        case 'issue_create':
            try {
                $newId = IssueRepo::create([
                    'kind'             => $_POST['kind']             ?? 'feature',
                    'title'            => $_POST['title']            ?? '',
                    'description'      => $_POST['description']      ?? null,
                    'status'           => $_POST['status']           ?? 'planned',
                    'severity'         => $_POST['severity']         ?? null,
                    'target_version'   => $_POST['target_version']   ?? null,
                    'fixed_in_version' => $_POST['fixed_in_version'] ?? null,
                    'is_public'        => !empty($_POST['is_public']),
                    'sort_order'       => (int) ($_POST['sort_order'] ?? 0),
                ]);
                $flash = "Created issue #$newId.";
            } catch (\Throwable $e) {
                $flash = "Couldn't create issue: " . $e->getMessage();
            }
            break;
        /**
         * <summary>
         * Updates an existing issue identified by issue_id with the new
         * field values posted from the inline edit form on the issues
         * view. Catches exceptions so a constraint failure surfaces as a
         * flash message rather than a fatal.
         * </summary>
         */
        case 'issue_update':
            $iid = (int) ($_POST['issue_id'] ?? 0);
            if ($iid > 0) {
                try {
                    IssueRepo::update($iid, [
                        'kind'             => $_POST['kind']             ?? null,
                        'title'            => $_POST['title']            ?? null,
                        'description'      => $_POST['description']      ?? null,
                        'status'           => $_POST['status']           ?? null,
                        'severity'         => $_POST['severity']         ?? null,
                        'target_version'   => $_POST['target_version']   ?? null,
                        'fixed_in_version' => $_POST['fixed_in_version'] ?? null,
                        'is_public'        => !empty($_POST['is_public']),
                        'sort_order'       => (int) ($_POST['sort_order'] ?? 0),
                    ]);
                    $flash = "Updated issue #$iid.";
                } catch (\Throwable $e) {
                    $flash = "Couldn't update issue: " . $e->getMessage();
                }
            }
            break;
        /**
         * <summary>
         * Permanently removes an issue from the changelog. The action
         * cannot be undone, so the confirmation dialog wired up at the
         * bottom of the page asks the operator first.
         * </summary>
         */
        case 'issue_delete':
            $iid = (int) ($_POST['issue_id'] ?? 0);
            if ($iid > 0 && IssueRepo::delete($iid)) {
                $flash = "Deleted issue #$iid.";
            }
            break;
    }
}

// ---- Shared data ---------------------------------------------------------

/**
 * <summary>
 * Section: shared data. Computes the values every view needs (counts for
 * the top bar, helper closures for escaping and relative time formatting,
 * and the sanitised view / sort selection from the query string).
 * </summary>
 */

$pdo = Db::pdo();

/**
 * <summary>
 * Aggregate row counts shown in the top of page stats strip. One query for
 * the lot so the dashboard does not fan out into many small selects.
 * Includes total users, banned, disabled, deleted, admins, active in last
 * 7 and 30 days, and how many have set an avatar.
 * </summary>
 */
$counts = $pdo->query(
    "SELECT
        COUNT(*)                                                    AS total_users,
        SUM(banned_at   IS NOT NULL)                                AS banned,
        SUM(disabled_at IS NOT NULL)                                AS disabled,
        SUM(deleted_at  IS NOT NULL)                                AS deleted,
        SUM(is_admin    = 1)                                        AS admins,
        SUM(last_login_at >= NOW() - INTERVAL 7 DAY)                AS active_7d,
        SUM(last_login_at >= NOW() - INTERVAL 30 DAY)               AS active_30d,
        SUM(avatar_url IS NOT NULL)                                 AS with_avatar
       FROM users"
)->fetch();

$journeysCount = (int) $pdo->query("SELECT COUNT(*) FROM journeys")->fetchColumn();
$messagesCount = (int) $pdo->query("SELECT COUNT(*) FROM messages")->fetchColumn();
$openReports   = (int) $pdo->query("SELECT COUNT(*) FROM user_reports WHERE resolved_at IS NULL")->fetchColumn();

/**
 * <summary>
 * HTML escape helper. Convenience closure aliased to $h to keep the
 * inline echo statements short and readable.
 * </summary>
 * <param name="s">Raw string to escape.</param>
 * <returns>The input encoded for safe insertion into HTML.</returns>
 */
$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

/**
 * <summary>
 * Formats a MySQL TIMESTAMP into a friendly relative string such as
 * "5m ago", "3d ago" or a short date for older values. Returns an
 * en dash for null inputs.
 * </summary>
 * <param name="iso">A MySQL TIMESTAMP string, or null.</param>
 * <returns>
 * A short human readable phrase: seconds, minutes, hours or days ago for
 * recent values, or a "j M" calendar date for anything older than a week.
 * </returns>
 * <remarks>
 * MySQL TIMESTAMP columns store and return values in UTC but the string
 * has no timezone suffix. We tell strtotime to treat it as UTC explicitly
 * so we do not double shift on servers running a non UTC default timezone.
 * </remarks>
 */
$rel = static function (?string $iso): string {
    if (!$iso) return '-';
    // MySQL TIMESTAMP columns store and return values in UTC but the
    // returned string has no timezone suffix. Tell strtotime to treat it
    // as UTC explicitly so we don't double-shift on servers running a
    // non-UTC default timezone.
    $t = strtotime($iso . ' UTC');
    if (!$t) return $iso;
    $secs = max(0, time() - $t);
    if ($secs < 60)        return $secs . 's ago';
    if ($secs < 3600)      return floor($secs / 60) . 'm ago';
    if ($secs < 86400)     return floor($secs / 3600) . 'h ago';
    if ($secs < 86400 * 7) return floor($secs / 86400) . 'd ago';
    return date('j M', $t);
};

/**
 * <summary>
 * Sanitises the ?view= and ?sort= query string parameters by whitelisting
 * them against a fixed set, so a typo or attempt at SQL injection lands
 * the visitor back on the default users view sorted by newest.
 * </summary>
 */
$validViews = ['users', 'reports', 'messages', 'issues'];
$view = in_array($_GET['view'] ?? '', $validViews, true) ? $_GET['view'] : 'users';
$sort = (string) ($_GET['sort'] ?? 'new');
$validSorts = ['new', 'recent_login', 'recent_avatar', 'oldest'];
if (!in_array($sort, $validSorts, true)) $sort = 'new';

/**
 * <summary>
 * Messages view filters. ?user=N restricts to one person; ?responses=1
 * also pulls in the other side of the conversation so the operator sees
 * the full thread, not just the named user's own typing.
 * </summary>
 */
$filterUser     = (int) ($_GET['user'] ?? 0);
$withResponses  = !empty($_GET['responses']);

/**
 * <summary>
 * Builds a query string that preserves the current view, sort and filter
 * state while overlaying any caller supplied overrides. Passing a key with
 * a null value clears that parameter, which is how the "Clear filters"
 * link drops the user and responses values.
 * </summary>
 * <param name="extra">Map of additional query string parameters to merge or null out.</param>
 * <returns>A "?..." string ready to drop into an href attribute.</returns>
 */
$qs = static function (array $extra) use ($view, $sort, $filterUser, $withResponses): string {
    $params = array_merge([
        'view' => $view,
        'sort' => $sort,
    ], $extra);
    // Carry the messages-view filters through unless the caller is overriding them.
    if ($filterUser    > 0 && !array_key_exists('user',      $extra)) $params['user']      = $filterUser;
    if ($withResponses    && !array_key_exists('responses', $extra)) $params['responses'] = '1';
    return '?' . http_build_query($params);
};

// ---- View specific data -------------------------------------------------

/**
 * <summary>
 * Section: per view data loaders. Populates one of the four arrays
 * depending on the chosen ?view= so the rendering block at the bottom can
 * just iterate. Each branch runs the minimum SQL the view needs.
 * </summary>
 */
$users    = [];
$reports  = [];
$messages = [];
$issues   = [];

/**
 * <summary>
 * Users view loader. Fetches up to 200 users along with two correlated
 * subquery counts: journeys per user, and accepted connections (lift
 * requests where this user is on either side of the pair). The sort key
 * is mapped through a match expression to a server side ORDER BY clause.
 * </summary>
 * <remarks>
 * recent_login and recent_avatar use the "IS NULL, then DESC" pattern so
 * users that never logged in or never set an avatar sink to the bottom of
 * the list instead of being silently treated as ancient. The 200 row cap
 * is deliberate; this dashboard is meant for sampling, not exhaustive
 * paging.
 * </remarks>
 */
if ($view === 'users') {
    $orderBy = match ($sort) {
        'new'             => 'created_at DESC',
        'oldest'          => 'created_at ASC',
        'recent_login'    => 'last_login_at IS NULL, last_login_at DESC',
        'recent_avatar'   => 'avatar_updated_at IS NULL, avatar_updated_at DESC',
    };

    $users = $pdo->query(
        "SELECT u.id, u.email, u.display_name, u.avatar_url,
                u.email_verified_at, u.banned_at, u.disabled_at, u.deleted_at, u.is_admin,
                u.created_at, u.last_login_at, u.avatar_updated_at,
                (SELECT COUNT(*) FROM journeys j WHERE j.user_id = u.id) AS journeys_count,
                (SELECT COUNT(*) FROM lift_requests lr
                  JOIN journeys jf ON jf.id = lr.from_journey_id
                  JOIN journeys jt ON jt.id = lr.to_journey_id
                 WHERE lr.status = 'accepted'
                   AND (jf.user_id = u.id OR jt.user_id = u.id)) AS connections_count
           FROM users u
       ORDER BY $orderBy
          LIMIT 200"
    )->fetchAll();
/**
 * <summary>
 * Messages view loader. Pulls up to 500 messages with sender, thread and
 * counterparty information joined in. Supports an optional single user
 * filter, with an "include responses" toggle that widens the search to
 * the full thread either side of the chosen user takes part in.
 * </summary>
 * <remarks>
 * Native prepares require unique placeholder names, so when the WHERE
 * clause needs to bind the same user id twice (the from and to sides of
 * the journey pair) the parameters are split into uid_from and uid_to
 * rather than reusing :uid.
 * </remarks>
 */
} elseif ($view === 'messages') {
    // Tiny user list just for the filter dropdown. Every active user with
    // at least one message either sent or received. Keeps the dropdown
    // focused on the people who actually have something to look at.
    $userOptions = $pdo->query(
        "SELECT DISTINCT u.id, u.display_name
           FROM users u
           JOIN journeys j      ON j.user_id = u.id
           JOIN lift_requests lr ON lr.from_journey_id = j.id OR lr.to_journey_id = j.id
           JOIN messages m       ON m.lift_request_id = lr.id
          WHERE u.deleted_at IS NULL
       ORDER BY u.display_name"
    )->fetchAll();

    // Build the WHERE clause around the user filter and the
    // include-responses toggle.
    //   no filter           : every message in the system
    //   user=N              : only messages SENT by user N
    //   user=N + responses  : every message in any thread N is a party to,
    //                         which folds in the other side's replies
    $where  = '';
    $params = [];
    if ($filterUser > 0) {
        if ($withResponses) {
            // Native prepares require unique placeholder names. Bind the
            // same user id under two keys rather than reusing :uid twice.
            $where = 'WHERE jf.user_id = :uid_from OR jt.user_id = :uid_to';
            $params['uid_from'] = $filterUser;
            $params['uid_to']   = $filterUser;
        } else {
            $where = 'WHERE m.sender_id = :uid';
            $params['uid'] = $filterUser;
        }
    }

    $orderBy = $sort === 'oldest' ? 'm.created_at ASC' : 'm.created_at DESC';

    $stmt = $pdo->prepare(
        "SELECT m.id, m.lift_request_id, m.sender_id, m.body, m.created_at, m.read_at,
                sender.display_name AS sender_name,
                sender.email        AS sender_email,
                sender.deleted_at   AS sender_deleted,
                sender.banned_at    AS sender_banned,
                lr.id        AS lr_id,
                lr.status    AS lr_status,
                jf.user_id   AS from_uid,
                jf.label     AS from_label,
                jt.user_id   AS to_uid,
                jt.label     AS to_label,
                ufr.display_name AS from_name,
                uto.display_name AS to_name
           FROM messages m
           JOIN users         sender ON sender.id = m.sender_id
           JOIN lift_requests lr     ON lr.id     = m.lift_request_id
           JOIN journeys      jf     ON jf.id     = lr.from_journey_id
           JOIN journeys      jt     ON jt.id     = lr.to_journey_id
           JOIN users         ufr    ON ufr.id    = jf.user_id
           JOIN users         uto    ON uto.id    = jt.user_id
        $where
        ORDER BY $orderBy
          LIMIT 500"
    );
    $stmt->execute($params);
    $messages = $stmt->fetchAll();
/**
 * <summary>
 * Issues view loader. Just delegates to IssueRepo::listAll() since the
 * issue table is small enough that all rows are fetched in one go.
 * </summary>
 */
} elseif ($view === 'issues') {
    $issues = IssueRepo::listAll();
/**
 * <summary>
 * Reports view loader. Pulls up to 200 abuse reports along with reporter
 * and target user data, ordered so unresolved reports float to the top
 * of the list and resolved ones sink under them by date.
 * </summary>
 */
} else {
    $reports = $pdo->query(
        "SELECT r.id, r.reason, r.detail, r.resolved_at, r.created_at,
                r.reporter_id, r.target_id,
                ur.display_name AS reporter_name, ur.email AS reporter_email,
                ut.display_name AS target_name,   ut.email AS target_email,
                ut.banned_at,   ut.deleted_at
           FROM user_reports r
           LEFT JOIN users ur ON ur.id = r.reporter_id
           LEFT JOIN users ut ON ut.id = r.target_id
       ORDER BY (r.resolved_at IS NULL) DESC, r.created_at DESC
          LIMIT 200"
    )->fetchAll();
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SwiftLift admin</title>
<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Baumans&family=Red+Hat+Display:wght@400;500;600;700;800&display=swap">
<style>
  /* SwiftLift palette, kept in lockstep with web/src/styles.css :root. */
  :root {
    --bg: #1a1a1a; --panel: #242424; --panel-2: #2f2f2f; --panel-3: #3a3a3a;
    --input-bg: #161616; --text: #ececec; --muted: #9a9a9a;
    --accent: #6ec546; --accent-2: #8fd66a; --accent-ink: #0e1a08;
    --danger: #e56b6b; --border: #3a3a3a;
  }
  * { box-sizing: border-box; }
  html, body { margin: 0; padding: 0; }
  body {
    background: var(--bg); color: var(--text);
    font-family: "Red Hat Display", system-ui, sans-serif;
    font-size: 14px; line-height: 1.45;
  }
  .wrap { max-width: 1400px; margin: 0 auto; padding: 1.25rem 1.25rem 3rem; }

  /* Header bar matching the AppBar look. */
  header.bar {
    display: flex; align-items: center; gap: .75rem;
    padding: .65rem 1rem; background: var(--panel);
    border-bottom: 1px solid var(--border);
    position: sticky; top: 0; z-index: 10;
  }
  header.bar .mark {
    width: 30px; height: 32px; color: var(--accent);
    display: grid; place-items: center;
  }
  header.bar .mark svg { width: 100%; height: 100%; display: block; }
  header.bar h1 {
    font-family: "Baumans", system-ui, sans-serif;
    font-weight: 400; font-size: 1.3rem; letter-spacing: .02em;
    margin: 0; color: var(--text);
  }
  header.bar .who { color: var(--muted); font-size: .8rem; margin-left: auto; }

  .stats {
    display: flex; flex-wrap: wrap; gap: .5rem .85rem;
    color: var(--muted); font-size: .85rem;
    background: var(--panel); border: 1px solid var(--border);
    border-radius: 6px; padding: .65rem .85rem; margin-bottom: 1rem;
  }
  .stats b { color: var(--text); }
  .stats .sep { color: var(--panel-3); }

  /* Tab strip, looks like the auth-tab pair on Login. */
  nav.tabs {
    display: flex; gap: .35rem;
    background: var(--input-bg); border: 1px solid var(--border);
    padding: .25rem; border-radius: 6px;
    margin-bottom: 1rem;
  }
  nav.tabs a {
    flex: 1; text-align: center;
    text-decoration: none; color: var(--muted);
    padding: .45rem .85rem; border-radius: 4px; font-size: .85rem; font-weight: 600;
  }
  nav.tabs a:hover { background: var(--panel-2); color: var(--text); }
  nav.tabs a.on {
    background: var(--panel); color: var(--text);
    box-shadow: 0 0 0 1px var(--accent) inset;
  }

  /* Controls strip (sort buttons, filter dropdowns). */
  .controls {
    display: flex; gap: .4rem; align-items: center; flex-wrap: wrap;
    margin-bottom: .85rem; font-size: .85rem; color: var(--muted);
  }
  .controls > span { color: var(--muted); margin-right: .15rem; }
  .controls a {
    color: var(--muted); text-decoration: none;
    background: var(--panel-2); border: 1px solid var(--border);
    padding: .35rem .7rem; border-radius: 4px; font-size: .8rem;
  }
  .controls a:hover { background: var(--panel-3); color: var(--text); }
  .controls a.on {
    color: var(--accent-ink); background: var(--accent); border-color: var(--accent);
    font-weight: 700;
  }
  .controls select, .controls input[type=text] {
    background: var(--input-bg); color: var(--text);
    border: 1px solid var(--border); border-radius: 4px;
    padding: .35rem .55rem; font: inherit; font-size: .85rem;
  }
  .controls label { display: flex; gap: .35rem; align-items: center; }
  .controls input[type=checkbox] { accent-color: var(--accent); }

  /* Table look. */
  table {
    width: 100%; border-collapse: collapse;
    background: var(--panel); border: 1px solid var(--border);
    border-radius: 6px; overflow: hidden;
  }
  th, td {
    padding: .55rem .7rem;
    border-bottom: 1px solid var(--border); vertical-align: top; text-align: left;
    font-size: .85rem;
  }
  th {
    background: var(--input-bg);
    color: var(--muted); font-weight: 600;
    text-transform: uppercase; letter-spacing: .08em; font-size: .7rem;
  }
  tr:last-child td { border-bottom: 0; }
  tr:hover td { background: rgba(110,197,70,.04); }
  tr.dismissed td { opacity: .5; }

  /* Pills, matching the React .pill family. */
  .pill {
    display: inline-block; padding: .15rem .5rem; border-radius: 999px;
    font-size: .68rem; font-weight: 700; line-height: 1.4;
    text-transform: uppercase; letter-spacing: .04em; vertical-align: middle;
  }
  .pill.open       { background: rgba(245,185,66,.18); color: #f5b942; }
  .pill.dismissed  { background: rgba(154,154,154,.18); color: var(--muted); }
  .pill.banned     { background: rgba(229,107,107,.18); color: var(--danger); }
  .pill.disabled   { background: rgba(160,160,200,.15); color: #a0a8c8; }
  .pill.deleted    { background: rgba(110,110,110,.18); color: var(--muted); }
  .pill.admin      { background: rgba(110,197,70,.18); color: var(--accent-2); }
  .pill.unverified { background: rgba(245,185,66,.15); color: #f5b942; }

  /* Buttons mirroring web/src/styles.css `.small` button + variants. */
  button {
    background: var(--panel-2); color: var(--text);
    border: 1px solid var(--border);
    padding: .35rem .65rem; border-radius: 4px;
    font: inherit; font-size: .78rem; cursor: pointer;
  }
  button:hover { background: var(--panel-3); }
  button.primary {
    background: var(--accent); border-color: var(--accent);
    color: var(--accent-ink); font-weight: 700;
  }
  button.primary:hover { filter: brightness(1.08); }
  button.danger {
    background: var(--danger); border-color: var(--danger);
    color: #1a0606; font-weight: 700;
  }
  button.danger:hover { filter: brightness(1.08); }
  form.inline { display: inline; margin-right: .25rem; }

  .flash {
    background: rgba(110,197,70,.12); border: 1px solid var(--accent);
    color: var(--accent-2); padding: .55rem .85rem;
    border-radius: 4px; margin-bottom: 1rem; font-size: .9rem;
  }

  details summary { cursor: pointer; color: var(--muted); }
  pre {
    white-space: pre-wrap; margin: .35rem 0 0;
    background: var(--input-bg); border: 1px solid var(--border);
    border-radius: 4px; padding: .5rem; font-size: .8rem;
  }

  /* Avatar bubble in user rows. */
  .avatar {
    display: inline-block; width: 26px; height: 26px; border-radius: 50%;
    background: var(--accent); color: var(--accent-ink);
    text-align: center; line-height: 26px;
    font-size: .72rem; font-weight: 700;
    vertical-align: middle; margin-right: .4rem;
    overflow: hidden;
  }
  .avatar img { width: 100%; height: 100%; object-fit: cover; }
  .muted { color: var(--muted); font-size: .78rem; }
  a { color: var(--accent-2); }

  /* ---------- Confirmation dialog (mirrors React ConfirmModal) ---------- */
  dialog.confirm {
    background: var(--panel); color: var(--text);
    border: 1px solid var(--border); border-radius: 8px;
    padding: 0; max-width: 460px; width: calc(100vw - 2rem);
    box-shadow: 0 20px 60px rgba(0,0,0,.6);
  }
  dialog.confirm::backdrop { background: rgba(0,0,0,.6); backdrop-filter: blur(4px); }
  dialog.confirm header {
    padding: .85rem 1.1rem; border-bottom: 1px solid var(--border);
    font-weight: 700;
  }
  dialog.confirm .body { padding: 1rem 1.1rem; line-height: 1.5; color: var(--text); }
  dialog.confirm .body small { display: block; color: var(--muted); margin-top: .35rem; font-size: .8rem; }
  dialog.confirm .actions {
    display: flex; gap: .5rem; justify-content: flex-end;
    padding: .75rem 1.1rem 1rem;
  }
</style>
</head>
<body>
<div class="wrap">
<?php
/**
 * <summary>
 * Top header bar. Shows the SwiftLift pin logo, the "SwiftLift admin"
 * title in the Baumans display face, and a small label on the right
 * indicating whether the visitor is in via session or token.
 * </summary>
 */
?>
<header class="bar">
  <span class="mark" aria-hidden="true">
    <!-- Same SwiftLift pin as the React AppBar, currentColor for the green. -->
    <svg viewBox="0 0 547 630" xmlns="http://www.w3.org/2000/svg">
      <path fill="currentColor" fill-rule="evenodd" d="M 265 16 L 190 37 L 154 61 L 125 91 L 91 155 L 82 231 L 96 291 L 126 353 L 268 587 L 288 594 L 303 581 L 458 316 L 478 262 L 483 201 L 468 137 L 448 101 L 415 64 L 349 26 L 310 17 Z M 224 56 L 302 48 L 360 64 L 409 102 L 441 154 L 452 201 L 442 273 L 358 428 L 317 400 L 290 361 L 295 339 L 314 319 L 358 296 L 387 304 L 404 285 L 402 192 L 380 174 L 362 132 L 346 116 L 236 111 L 214 119 L 186 173 L 162 196 L 161 284 L 170 300 L 188 305 L 204 295 L 208 274 L 349 278 L 254 316 L 178 377 L 122 272 L 114 199 L 128 145 L 174 85 Z M 211 176 L 225 145 L 231 139 L 236 137 L 329 137 L 333 139 L 340 146 L 349 169 L 352 173 L 353 179 L 352 180 L 212 180 Z M 264 339 L 267 340 L 267 343 L 261 362 L 261 375 L 268 396 L 279 413 L 293 428 L 315 447 L 335 461 L 335 465 L 321 487 L 319 487 L 300 473 L 276 453 L 254 429 L 242 408 L 238 389 L 240 372 L 249 354 Z M 374 214 L 374 215 L 375 216 L 375 220 L 376 221 L 376 224 L 375 225 L 375 227 L 374 228 L 374 230 L 369 235 L 368 235 L 367 236 L 364 236 L 363 237 L 343 237 L 342 236 L 338 236 L 337 235 L 336 235 L 331 230 L 331 229 L 330 228 L 330 220 L 335 215 L 338 214 L 340 212 L 341 212 L 344 210 L 346 210 L 347 209 L 349 209 L 350 208 L 353 208 L 354 207 L 366 207 L 367 208 L 368 208 Z M 189 220 L 191 217 L 191 215 L 192 214 L 192 213 L 197 208 L 198 208 L 199 207 L 211 207 L 212 208 L 215 208 L 216 209 L 218 209 L 219 210 L 221 210 L 222 211 L 223 211 L 225 213 L 226 213 L 227 214 L 230 215 L 234 219 L 234 220 L 235 221 L 235 228 L 234 229 L 233 232 L 231 234 L 230 234 L 227 236 L 224 236 L 223 237 L 203 237 L 202 236 L 199 236 L 198 235 L 196 235 L 192 231 L 192 230 L 190 227 L 190 224 L 189 223 Z"/>
    </svg>
  </span>
  <h1>SwiftLift admin</h1>
  <span class="who">
    <?php if ($sessionAdmin): ?>signed in as #<?= (int) $sessionUid ?><?php else: ?>token auth<?php endif; ?>
  </span>
</header>

<?php
/**
 * <summary>
 * Top of page stats strip. A one line summary of the whole instance.
 * Total users, active in 7 and 30 days, with avatar, banned, disabled,
 * deleted, admins, journeys, messages and open reports. Sourced from the
 * $counts aggregate plus the three small follow up queries above.
 * </summary>
 */
?>
<div class="stats">
  <span><b><?= (int) $counts['total_users'] ?></b> users</span><span class="sep">&middot;</span>
  <span><b><?= (int) $counts['active_7d'] ?></b> active 7d</span><span class="sep">&middot;</span>
  <span><b><?= (int) $counts['active_30d'] ?></b> active 30d</span><span class="sep">&middot;</span>
  <span><b><?= (int) $counts['with_avatar'] ?></b> with avatar</span><span class="sep">&middot;</span>
  <span><b><?= (int) $counts['banned'] ?></b> banned</span><span class="sep">&middot;</span>
  <span><b><?= (int) $counts['disabled'] ?></b> disabled</span><span class="sep">&middot;</span>
  <span><b><?= (int) $counts['deleted'] ?></b> deleted</span><span class="sep">&middot;</span>
  <span><b><?= (int) $counts['admins'] ?></b> admins</span><span class="sep">&middot;</span>
  <span><b><?= $journeysCount ?></b> journeys</span><span class="sep">&middot;</span>
  <span><b><?= $messagesCount ?></b> messages</span><span class="sep">&middot;</span>
  <span><b><?= $openReports ?></b> open reports</span>
</div>

<?php
/**
 * <summary>
 * Tab strip. Four pills for switching between Users, Messages, Reports
 * and Issues. The links carry the token (where present) and clear the
 * messages only filters when navigating away from the messages tab so
 * the URL stays clean.
 * </summary>
 */
?>
<nav class="tabs">
  <a class="<?= $view === 'users'    ? 'on' : '' ?>" href="<?= $h($qs(['view' => 'users',    'user' => null, 'responses' => null])) ?>">Users</a>
  <a class="<?= $view === 'messages' ? 'on' : '' ?>" href="<?= $h($qs(['view' => 'messages']))                                       ?>">Messages</a>
  <a class="<?= $view === 'reports'  ? 'on' : '' ?>" href="<?= $h($qs(['view' => 'reports',  'user' => null, 'responses' => null])) ?>">Reports<?php if ($openReports > 0): ?> (<?= $openReports ?>)<?php endif; ?></a>
  <a class="<?= $view === 'issues'   ? 'on' : '' ?>" href="<?= $h($qs(['view' => 'issues',   'user' => null, 'responses' => null])) ?>">Issues</a>
</nav>

<?php
/**
 * <summary>
 * Flash message banner. Shown above the active view whenever the previous
 * POST set a one line $flash status. The message is escaped before
 * rendering so SQL exception text from the issue editor cannot inject
 * HTML.
 * </summary>
 */
?>
<?php if ($flash): ?>
  <div class="flash"><?= $h($flash) ?></div>
<?php endif; ?>

<?php
/**
 * <summary>
 * Users view. Renders the sort buttons and a sortable table of up to 200
 * users with avatar, email, joined and last login times, journey and
 * connection counts, status pills (admin, unverified, banned, disabled,
 * deleted), and the per row action buttons (ban, unban, disable, enable,
 * toggle admin) wired up to the action dispatcher.
 * </summary>
 */
?>
<?php if ($view === 'users'): ?>

  <?php
  /**
   * <summary>
   * Sort controls. Four mutually exclusive pills that change the ORDER BY
   * used by the users view loader. Each link rewrites only the sort key
   * and leaves the rest of the query string intact.
   * </summary>
   */
  ?>
  <div class="controls">
    <span>Sort:</span>
    <a class="<?= $sort === 'new'           ? 'on' : '' ?>" href="<?= $h($qs(['sort' => 'new']))           ?>">Newest</a>
    <a class="<?= $sort === 'oldest'        ? 'on' : '' ?>" href="<?= $h($qs(['sort' => 'oldest']))        ?>">Oldest</a>
    <a class="<?= $sort === 'recent_login'  ? 'on' : '' ?>" href="<?= $h($qs(['sort' => 'recent_login']))  ?>">Recently logged in</a>
    <a class="<?= $sort === 'recent_avatar' ? 'on' : '' ?>" href="<?= $h($qs(['sort' => 'recent_avatar'])) ?>">Recent avatar updates</a>
  </div>

  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>User</th>
        <th>Email</th>
        <th>Joined</th>
        <th>Last login</th>
        <th>Avatar</th>
        <th>J</th>
        <th>C</th>
        <th>Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($users as $u):
        $initials = strtoupper(mb_substr((string) ($u['display_name'] ?? '?'), 0, 2));
        $isMe = ($sessionUid !== null && (int) $u['id'] === (int) $sessionUid);
    ?>
      <tr>
        <td><?= (int) $u['id'] ?></td>
        <td>
          <span class="avatar">
            <?php /* User avatars are disabled (unmoderated image URLs are an
                      abuse vector, see web/src/lib/features.ts). Render
                      initials here too rather than the stored URL, so the
                      operator isn't shown unmoderated remote images. */ ?>
            <?= $h($initials) ?>
          </span>
          <?= $h((string) $u['display_name']) ?>
          <?php if ($isMe): ?> <span class="muted">(you)</span><?php endif; ?>
        </td>
        <td><?= $h((string) $u['email']) ?></td>
        <td><?= $h($rel($u['created_at'] ?? null)) ?></td>
        <td><?= $h($rel($u['last_login_at'] ?? null)) ?></td>
        <td><?= !empty($u['avatar_updated_at']) ? $h($rel($u['avatar_updated_at'])) : '<span class="muted">none</span>' ?></td>
        <td><?= (int) $u['journeys_count'] ?></td>
        <td><?= (int) $u['connections_count'] ?></td>
        <td>
          <?php if ($u['is_admin']):                   ?> <span class="pill admin">admin</span>      <?php endif; ?>
          <?php if (empty($u['email_verified_at']) && empty($u['deleted_at'])): ?> <span class="pill unverified">unverified</span> <?php endif; ?>
          <?php if ($u['banned_at']):                  ?> <span class="pill banned">banned</span>    <?php endif; ?>
          <?php if ($u['disabled_at']):                ?> <span class="pill disabled">disabled</span><?php endif; ?>
          <?php if ($u['deleted_at']):                 ?> <span class="pill deleted">deleted</span>  <?php endif; ?>
        </td>
        <td>
          <?php if (!$u['deleted_at']): ?>
            <?php if ($u['banned_at']): ?>
              <form class="inline" method="post">
                <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                <input type="hidden" name="action"  value="unban">
                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                <button type="submit">Unban</button>
              </form>
            <?php else: ?>
              <form class="inline" method="post"
                    data-confirm-title="Ban <?= $h((string) $u['display_name']) ?>?"
                    data-confirm-message="They'll be logged out on their next request and won't be able to sign back in until you unban them. Use Ban for misconduct."
                    data-confirm-button="Ban"
                    data-confirm-tone="danger">
                <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                <input type="hidden" name="action"  value="ban">
                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                <button type="submit" class="danger">Ban</button>
              </form>
            <?php endif; ?>
            <?php if ($u['disabled_at']): ?>
              <form class="inline" method="post"
                    data-confirm-title="Reactivate <?= $h((string) $u['display_name']) ?>?"
                    data-confirm-message="They'll be able to sign in again and use SwiftLift normally. Use this when a disabled user has got in touch and you're happy with their reason."
                    data-confirm-button="Reactivate">
                <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                <input type="hidden" name="action"  value="enable">
                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                <button type="submit">Reactivate</button>
              </form>
            <?php else: ?>
              <form class="inline" method="post"
                    data-confirm-title="Disable <?= $h((string) $u['display_name']) ?>?"
                    data-confirm-message="They'll be logged out and won't be able to sign in. Their data stays in the system and you can reactivate any time. Use this for soft account suspensions. For misconduct use Ban instead."
                    data-confirm-button="Disable">
                <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                <input type="hidden" name="action"  value="disable">
                <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                <button type="submit">Disable</button>
              </form>
            <?php endif; ?>
            <form class="inline" method="post"
                  data-confirm-title="<?= $u['is_admin'] ? 'Revoke admin' : 'Make admin' ?>: <?= $h((string) $u['display_name']) ?>?"
                  data-confirm-message="<?= $u['is_admin']
                      ? 'They will lose access to this dashboard and the ban / promote tools.'
                      : 'They will gain full access to this dashboard, including banning users and promoting other admins.' ?>"
                  data-confirm-button="<?= $u['is_admin'] ? 'Revoke admin' : 'Make admin' ?>"
                  data-confirm-tone="<?= $u['is_admin'] ? 'danger' : 'primary' ?>">
              <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
              <input type="hidden" name="action"  value="toggle_admin">
              <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
              <button type="submit"><?= $u['is_admin'] ? 'Revoke admin' : 'Make admin' ?></button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$users): ?>
      <tr><td colspan="11" class="muted" style="padding:1rem">No users yet.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  <p class="muted">Showing up to 200 most recent.  J = journeys.  C = active connections.</p>

<?php
/**
 * <summary>
 * Messages view. Renders the filter form (user dropdown, include
 * responses checkbox, sort, clear filters link) and the table of up to
 * 500 messages with sender, recipient, thread metadata and the message
 * body. Empty state copy explains why the list is empty when filters are
 * in play.
 * </summary>
 */
?>
<?php elseif ($view === 'messages'): ?>

  <?php
  /**
   * <summary>
   * Messages view controls. A GET form that auto submits when the user
   * dropdown or the include responses checkbox changes, plus inline sort
   * pills and a clear filters shortcut.
   * </summary>
   */
  ?>
  <form method="get" class="controls" style="align-items: baseline;">
    <input type="hidden" name="view" value="messages">

    <span>User:</span>
    <select name="user" onchange="this.form.submit()">
      <option value="0">Anyone</option>
      <?php foreach ($userOptions as $u): ?>
        <option value="<?= (int) $u['id'] ?>" <?= $filterUser === (int) $u['id'] ? 'selected' : '' ?>>
          #<?= (int) $u['id'] ?> <?= $h((string) $u['display_name']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <label style="display:flex; gap:.3rem; align-items:center; margin-left:.5rem;">
      <input type="checkbox" name="responses" value="1" <?= $withResponses ? 'checked' : '' ?>
             onchange="this.form.submit()" <?= $filterUser > 0 ? '' : 'disabled' ?>>
      include responses
    </label>

    <span style="margin-left:.5rem;">Sort:</span>
    <a class="<?= $sort === 'new'    ? 'on' : '' ?>" href="<?= $h($qs(['sort' => 'new']))    ?>">Newest</a>
    <a class="<?= $sort === 'oldest' ? 'on' : '' ?>" href="<?= $h($qs(['sort' => 'oldest'])) ?>">Oldest</a>

    <?php if ($filterUser > 0 || $withResponses): ?>
      <a href="<?= $h($qs(['user' => null, 'responses' => null])) ?>" style="margin-left:auto;">Clear filters</a>
    <?php endif; ?>
  </form>

  <?php if (!$messages): ?>
    <p class="muted">No messages
      <?php if ($filterUser > 0): ?>
        for the selected user
        <?= $withResponses ? '(or in any thread they are part of)' : '' ?>.
      <?php else: ?>
        in the system yet.
      <?php endif; ?>
    </p>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th>When</th>
        <th>From</th>
        <th>To</th>
        <th>Thread</th>
        <th>Message</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($messages as $m):
      $otherUid  = (int) $m['from_uid'] === (int) $m['sender_id'] ? (int) $m['to_uid']   : (int) $m['from_uid'];
      $otherName =        (int) $m['from_uid'] === (int) $m['sender_id'] ? (string) $m['to_name'] : (string) $m['from_name'];
    ?>
      <tr>
        <td><?= $h($rel($m['created_at'])) ?><div class="muted"><?= $h((string) $m['created_at']) ?></div></td>
        <td>
          <a href="<?= $h($qs(['user' => (int) $m['sender_id'], 'responses' => null])) ?>">
            #<?= (int) $m['sender_id'] ?> <?= $h((string) $m['sender_name']) ?>
          </a>
          <?php if ($m['sender_deleted']): ?> <span class="pill deleted">deleted</span><?php endif; ?>
          <?php if ($m['sender_banned']):  ?> <span class="pill banned">banned</span> <?php endif; ?>
        </td>
        <td>
          <a href="<?= $h($qs(['user' => $otherUid, 'responses' => null])) ?>">
            #<?= $otherUid ?> <?= $h($otherName) ?>
          </a>
        </td>
        <td>
          <span class="muted">LR<?= (int) $m['lr_id'] ?></span>
          <?= $h((string) $m['from_label']) ?>
          → <?= $h((string) $m['to_label']) ?>
          <div class="muted"><?= $h((string) $m['lr_status']) ?></div>
        </td>
        <td><?= $h((string) $m['body']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <p class="muted">
    Showing <?= count($messages) ?> message<?= count($messages) === 1 ? '' : 's' ?>
    (cap 500). Click a name to filter to that user.
  </p>
  <?php endif; ?>

<?php
/**
 * <summary>
 * Issues view. Renders the public changelog editor: a one liner header,
 * the collapsible new issue form, and the table of existing issues with
 * inline edit and delete forms.
 * </summary>
 * <remarks>
 * Items with the Public flag on are surfaced in the Improvements section
 * of the About page in the React app; private items stay here for the
 * operator only. The current app version is shown so the operator can
 * pick sensible target and fixed in values.
 * </remarks>
 */
?>
<?php elseif ($view === 'issues'): ?>

  <p class="muted">
    Public changelog / roadmap. Items with <b>Public</b> on appear in the
    Improvements section of the About page; private items stay here for
    your eyes only. Current app version: <code><?= $h(Version::current()) ?></code>.
  </p>

  <?php
  /**
   * <summary>
   * Collapsible "New issue" form. Posts the issue_create action with
   * title, description, kind, status, severity, target version, fixed in
   * version, sort order and the public checkbox. Wrapped in a details
   * element so it does not eat space on the page until needed.
   * </summary>
   */
  ?>
  <details style="margin: 1rem 0; padding: 1rem; background: var(--panel); border: 1px solid var(--border); border-radius: 6px;">
    <summary style="cursor: pointer; font-weight: 600;">+ New issue</summary>
    <form method="post" style="margin-top: 1rem; display: grid; gap: .5rem;">
      <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
      <input type="hidden" name="action" value="issue_create">
      <label>Title <input type="text" name="title" required maxlength="160" style="width: 100%;"></label>
      <label>Description <textarea name="description" rows="3" style="width: 100%;"></textarea></label>
      <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
        <label>Kind
          <select name="kind">
            <option value="feature">Feature</option>
            <option value="improvement">Improvement</option>
            <option value="bug">Bug</option>
          </select>
        </label>
        <label>Status
          <select name="status">
            <option value="planned">Planned</option>
            <option value="in_progress">In progress</option>
            <option value="fixed">Fixed</option>
            <option value="rejected">Rejected</option>
          </select>
        </label>
        <label>Severity
          <select name="severity">
            <option value="">none</option>
            <option value="low">Low</option>
            <option value="medium">Medium</option>
            <option value="high">High</option>
            <option value="critical">Critical</option>
          </select>
        </label>
        <label>Target version <input type="text" name="target_version" maxlength="20" placeholder="e.g. 1.1.0" style="width: 6rem;"></label>
        <label>Fixed in <input type="text" name="fixed_in_version" maxlength="20" placeholder="e.g. 1.0.0" style="width: 6rem;"></label>
        <label>Sort <input type="number" name="sort_order" value="0" style="width: 4rem;"></label>
        <label><input type="checkbox" name="is_public" checked> Public</label>
      </div>
      <button type="submit" class="primary">Create issue</button>
    </form>
  </details>

  <?php
  /**
   * <summary>
   * Issues table. One row per tracked changelog entry with inline edit
   * (expanded via details summary on the title) and a small × delete
   * button on the right. Empty state copy nudges the operator towards
   * creating the first issue via the form above.
   * </summary>
   */
  ?>
  <?php if (!$issues): ?>
    <p class="muted">No issues tracked yet. Add the first one above.</p>
  <?php else: ?>
  <table>
    <thead>
      <tr>
        <th>#</th><th>Kind</th><th>Title</th><th>Status</th><th>Severity</th>
        <th>Target</th><th>Fixed in</th><th>Public</th><th>Sort</th><th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($issues as $iss): ?>
      <tr>
        <td><?= (int) $iss['id'] ?></td>
        <td><span class="pill"><?= $h($iss['kind']) ?></span></td>
        <td>
          <details>
            <summary><?= $h($iss['title']) ?></summary>
            <form method="post" style="display: grid; gap: .5rem; margin-top: .75rem; padding: .75rem; background: #0b1221; border-radius: 6px;">
              <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
              <input type="hidden" name="action" value="issue_update">
              <input type="hidden" name="issue_id" value="<?= (int) $iss['id'] ?>">
              <label>Title <input type="text" name="title" required maxlength="160" value="<?= $h((string) $iss['title']) ?>" style="width: 100%;"></label>
              <label>Description <textarea name="description" rows="3" style="width: 100%;"><?= $h((string) ($iss['description'] ?? '')) ?></textarea></label>
              <div style="display: flex; gap: .75rem; flex-wrap: wrap;">
                <label>Kind <select name="kind">
                  <?php foreach (['feature','improvement','bug'] as $k): ?>
                    <option value="<?= $k ?>"<?= $iss['kind'] === $k ? ' selected' : '' ?>><?= $k ?></option>
                  <?php endforeach; ?>
                </select></label>
                <label>Status <select name="status">
                  <?php foreach (['planned','in_progress','fixed','rejected'] as $s): ?>
                    <option value="<?= $s ?>"<?= $iss['status'] === $s ? ' selected' : '' ?>><?= $s ?></option>
                  <?php endforeach; ?>
                </select></label>
                <label>Severity <select name="severity">
                  <option value="">none</option>
                  <?php foreach (['low','medium','high','critical'] as $sv): ?>
                    <option value="<?= $sv ?>"<?= $iss['severity'] === $sv ? ' selected' : '' ?>><?= $sv ?></option>
                  <?php endforeach; ?>
                </select></label>
                <label>Target <input type="text" name="target_version" maxlength="20" value="<?= $h((string) ($iss['target_version'] ?? '')) ?>" style="width: 5rem;"></label>
                <label>Fixed in <input type="text" name="fixed_in_version" maxlength="20" value="<?= $h((string) ($iss['fixed_in_version'] ?? '')) ?>" style="width: 5rem;"></label>
                <label>Sort <input type="number" name="sort_order" value="<?= (int) ($iss['sort_order'] ?? 0) ?>" style="width: 4rem;"></label>
                <label><input type="checkbox" name="is_public" <?= !empty($iss['is_public']) ? 'checked' : '' ?>> Public</label>
              </div>
              <div style="display: flex; gap: .5rem;">
                <button type="submit">Save</button>
              </div>
            </form>
            <form method="post" style="margin-top: .5rem;"
                  data-confirm-title="Delete this issue?"
                  data-confirm-message="The issue will be removed from the changelog. This can't be undone."
                  data-confirm-button="Delete"
                  data-confirm-tone="danger">
              <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
              <input type="hidden" name="action" value="issue_delete">
              <input type="hidden" name="issue_id" value="<?= (int) $iss['id'] ?>">
              <button type="submit" class="danger">Delete</button>
            </form>
          </details>
        </td>
        <td><span class="pill <?= $iss['status'] === 'fixed' ? 'admin' : ($iss['status'] === 'in_progress' ? 'unverified' : '') ?>"><?= $h($iss['status']) ?></span></td>
        <td><?= $iss['severity'] ? $h($iss['severity']) : '<span class="muted">-</span>' ?></td>
        <td><?= $iss['target_version'] ? $h($iss['target_version']) : '<span class="muted">-</span>' ?></td>
        <td><?= $iss['fixed_in_version'] ? $h($iss['fixed_in_version']) : '<span class="muted">-</span>' ?></td>
        <td><?= !empty($iss['is_public']) ? 'yes' : '<span class="muted">no</span>' ?></td>
        <td><?= (int) ($iss['sort_order'] ?? 0) ?></td>
        <td>
          <form method="post" class="inline">
            <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
            <input type="hidden" name="action" value="issue_delete">
            <input type="hidden" name="issue_id" value="<?= (int) $iss['id'] ?>">
            <button type="submit" class="danger"
                    data-confirm-title="Delete issue #<?= (int) $iss['id'] ?>?"
                    data-confirm-message="Removes it from the changelog permanently."
                    data-confirm-button="Delete"
                    data-confirm-tone="danger">×</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

<?php
/**
 * <summary>
 * Reports view. Renders the abuse report inbox. Each row shows the
 * reporter, the target, the reason, an optional detail blob (in a
 * details element to keep the row short), the open or dismissed status,
 * and the per row actions (ban or unban the target, dismiss the report).
 * </summary>
 * <remarks>
 * Rows for dismissed reports are dimmed via the .dismissed CSS class so
 * the operator's eye is drawn to the open queue first.
 * </remarks>
 */
?>
<?php else: /* view = reports */ ?>

  <?php if (!$reports): ?>
    <p class="muted">No reports filed yet.</p>
  <?php else: ?>
  <table>
    <thead>
      <tr><th>When</th><th>Reporter</th><th>Target</th><th>Reason</th><th>Status</th><th>Action</th></tr>
    </thead>
    <tbody>
    <?php foreach ($reports as $r): $isOpen = $r['resolved_at'] === null; ?>
      <tr class="<?= $isOpen ? 'open' : 'dismissed' ?>">
        <td><?= $h((string) $r['created_at']) ?></td>
        <td>
          #<?= (int) $r['reporter_id'] ?> <?= $h((string) ($r['reporter_name'] ?? '?')) ?><br>
          <span class="muted"><?= $h((string) ($r['reporter_email'] ?? '')) ?></span>
        </td>
        <td>
          #<?= (int) $r['target_id'] ?> <?= $h((string) ($r['target_name'] ?? '?')) ?>
          <?php if ($r['banned_at']):  ?> <span class="pill banned">banned</span>  <?php endif; ?>
          <?php if ($r['deleted_at']): ?> <span class="pill deleted">deleted</span><?php endif; ?>
          <br>
          <span class="muted"><?= $h((string) ($r['target_email'] ?? '')) ?></span>
        </td>
        <td>
          <strong><?= $h((string) $r['reason']) ?></strong>
          <?php if ($r['detail']): ?>
            <details><summary>detail</summary><pre><?= $h((string) $r['detail']) ?></pre></details>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($isOpen): ?>
            <span class="pill open">open</span>
          <?php else: ?>
            <span class="pill dismissed">dismissed</span>
            <div class="muted"><?= $h((string) $r['resolved_at']) ?></div>
          <?php endif; ?>
        </td>
        <td>
          <?php if (!$r['deleted_at']): ?>
            <?php if ($r['banned_at']): ?>
              <form class="inline" method="post">
                <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                <input type="hidden" name="action"  value="unban">
                <input type="hidden" name="user_id" value="<?= (int) $r['target_id'] ?>">
                <button type="submit">Unban</button>
              </form>
            <?php else: ?>
              <form class="inline" method="post"
                    data-confirm-title="Ban <?= $h((string) ($r['target_name'] ?? 'user')) ?>?"
                    data-confirm-message="They'll be logged out on their next request and won't be able to sign back in until you unban them."
                    data-confirm-button="Ban"
                    data-confirm-tone="danger">
                <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
                <input type="hidden" name="action"  value="ban">
                <input type="hidden" name="user_id" value="<?= (int) $r['target_id'] ?>">
                <button type="submit" class="danger">Ban</button>
              </form>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ($isOpen): ?>
            <form class="inline" method="post">
              <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">
              <input type="hidden" name="action"    value="dismiss_report">
              <input type="hidden" name="user_id"   value="<?= (int) $r['target_id'] ?>">
              <input type="hidden" name="report_id" value="<?= (int) $r['id'] ?>">
              <button type="submit">Dismiss</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

<?php endif; ?>

</div><!-- /.wrap -->

<?php
/**
 * <summary>
 * Centred confirm dialog. Native &lt;dialog&gt; does the heavy lifting:
 * showModal() inserts the backdrop, esc to close works automatically,
 * and the click on backdrop behaviour is wired up by the script below.
 * The script promotes any form carrying data-confirm-title="..." into
 * an interception of submit so the operator gets a confirmation step
 * before destructive actions run.
 * </summary>
 */
?>
<dialog class="confirm" id="confirm-dialog">
  <header id="confirm-title">Are you sure?</header>
  <div class="body">
    <span id="confirm-message"></span>
  </div>
  <div class="actions">
    <button type="button" id="confirm-cancel">Cancel</button>
    <button type="button" id="confirm-ok" class="primary" autofocus>Confirm</button>
  </div>
</dialog>

<script>
  // Promote any <form data-confirm-title="..."> submission to a centred
  // confirm dialog, matching the React app's ConfirmModal behaviour.
  // Forms without these attributes submit normally.
  (function () {
    const dlg     = document.getElementById('confirm-dialog');
    const titleEl = document.getElementById('confirm-title');
    const msgEl   = document.getElementById('confirm-message');
    const okBtn   = document.getElementById('confirm-ok');
    const cancel  = document.getElementById('confirm-cancel');

    let pendingForm = null;

    /**
     * <summary>
     * Closes the confirm dialog and forgets the form that was awaiting
     * confirmation.
     * </summary>
     * <remarks>
     * dialog.close() throws if the dialog was never opened as a modal,
     * which is harmless here, so it is swallowed.
     * </remarks>
     */
    function close() {
      try { dlg.close(); } catch (_) {}
      pendingForm = null;
    }

    document.addEventListener('submit', function (e) {
      const form = e.target;
      if (!(form instanceof HTMLFormElement)) return;
      const title = form.dataset.confirmTitle;
      if (!title) return;
      e.preventDefault();
      pendingForm = form;
      titleEl.textContent   = title;
      msgEl.textContent     = form.dataset.confirmMessage || '';
      okBtn.textContent     = form.dataset.confirmButton  || 'Confirm';
      okBtn.className       = (form.dataset.confirmTone === 'danger') ? 'danger' : 'primary';
      try { dlg.showModal(); }
      catch (_) {
        // Fallback for very old browsers without <dialog>: just submit.
        if (window.confirm(title + '\n\n' + (form.dataset.confirmMessage || ''))) form.submit();
      }
      okBtn.focus();
    });

    okBtn.addEventListener('click', function () {
      const f = pendingForm;
      close();
      if (f) f.submit();
    });

    cancel.addEventListener('click', close);

    // Click on backdrop (outside the inner panel) closes the dialog.
    dlg.addEventListener('click', function (e) {
      if (e.target === dlg) close();
    });
  })();
</script>

</body>
</html>
