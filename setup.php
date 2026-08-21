<?php
declare(strict_types=1);

/**
 * <summary>
 * SwiftLift database setup page. Token gated, server rendered, runs the
 * schema migrations against the configured database with a single click.
 * </summary>
 * <remarks>
 * Visit /setup.php?token=&lt;SETUP_TOKEN&gt; to inspect the database and create
 * the tables in one go. Safe to rerun. Uses CREATE TABLE IF NOT EXISTS plus
 * a portable column add migration that works on both MySQL 5.7+ and MariaDB
 * without relying on the MariaDB only ADD COLUMN IF NOT EXISTS syntax.
 *
 * Requires SETUP_TOKEN to be set in .env. The older behaviour (open access
 * when SETUP_TOKEN was blank) meant anyone who hit the page before the
 * operator deleted the file could POST drop=1 and wipe every table. Now no
 * token means no setup page, full stop.
 *
 * Destructive actions (DROP existing tables) always also require an explicit
 * checkbox in the form, so an accidental click cannot lose data.
 *
 * The file should be removed from the webroot once the install is finished.
 * Error display is forced on at the top because IONOS hosting defaults to
 * display_errors=Off, which would otherwise leave setup failures silent.
 * Display is enabled only after the SETUP_TOKEN gate passes, so failures
 * before that point are logged rather than shown to a stranger.
 * </remarks>
 */

// Report everything, but do NOT display it yet. Bootstrap runs before the
// token gate below, so anything it throws (a malformed .env, an unreadable
// include) would otherwise be rendered to an unauthenticated visitor,
// connection failures in particular carry the DB host and user. Errors are
// logged either way; display is switched on only once the token checks out.
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

require __DIR__ . '/src/bootstrap.php';

use SwiftLift\{Db, Env};

/**
 * <summary>
 * Token gate. The page is locked behind SETUP_TOKEN. If the env value is
 * missing or the supplied ?token=... does not match, the request is
 * refused with a 403 and a plain text explanation.
 * </summary>
 * <remarks>
 * hash_equals is used so the comparison runs in constant time and cannot
 * be brute forced via timing differences. A missing env value is treated
 * as a hard failure rather than allowing open access, which is what the
 * previous version did and what the file level summary warns about.
 * </remarks>
 */
$token = (string) (Env::get('SETUP_TOKEN') ?? '');
if (Env::isPlaceholderSecret($token)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit(
        "Forbidden: SETUP_TOKEN is still the example value, or is too short.\n\n" .
        "The placeholder in .env.example is published in the repository, so it\n" .
        "is not a secret. Set SETUP_TOKEN to at least 16 random characters in\n" .
        ".env, then revisit this page as /setup.php?token=<that string>.\n"
    );
}
if ($token === '') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit(
        "Forbidden: SETUP_TOKEN is not configured.\n\n" .
        "Set SETUP_TOKEN=<a long random string> in .env, then revisit this page\n" .
        "as /setup.php?token=<that string>. Once setup is finished, delete this\n" .
        "file from the webroot.\n"
    );
}
if (!hash_equals($token, (string) ($_GET['token'] ?? ''))) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Forbidden: the ?token=... in the URL doesn't match SETUP_TOKEN.\n");
}

// Past the gate: this is the operator. Show them everything, that is the
// whole point of this page on a host with display_errors=Off.
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

/**
 * <summary>
 * Inline SQL schema. Holds the full CREATE TABLE statements for every
 * SwiftLift table. This heredoc is the authoritative schema,
 * there is no separate .sql file to keep in sync.
 * </summary>
 * <remarks>
 * Keeping the schema inline means setup.php can be uploaded on its own
 * without dragging the whole /database folder along. The statements use
 * CREATE TABLE IF NOT EXISTS throughout, so rerunning on an already
 * populated database is a safe no op for tables that already exist.
 * Column level evolution on existing tables is handled separately by
 * runColumnMigrations() below.
 * </remarks>
 */
$sqlText = <<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email              VARCHAR(191) NOT NULL UNIQUE,
    password_hash      VARCHAR(255) NULL,
    display_name       VARCHAR(80)  NOT NULL,
    role               ENUM('driver','passenger','both') NOT NULL DEFAULT 'both',
    avatar_url         VARCHAR(500) NULL,
    bio                VARCHAR(280) NULL,
    age                TINYINT UNSIGNED NULL,
    age_min            TINYINT UNSIGNED NULL,
    age_max            TINYINT UNSIGNED NULL,
    is_away            TINYINT(1)   NOT NULL DEFAULT 0,
    theme              ENUM('dark','light','auto') NOT NULL DEFAULT 'light',
    default_radius_m   SMALLINT UNSIGNED NOT NULL DEFAULT 500,
    default_window_min TINYINT  UNSIGNED NOT NULL DEFAULT 10,
    car_make           VARCHAR(60)  NULL,
    car_colour         VARCHAR(40)  NULL,
    car_seats          TINYINT UNSIGNED NULL,
    pref_smoking       ENUM('yes','no','outside') NULL,
    pref_pets          ENUM('yes','no','small_only') NULL,
    pref_music         VARCHAR(80)  NULL,
    sex                ENUM('male','female') NULL,
    pref_sex           ENUM('any','male','female') NOT NULL DEFAULT 'any',
    detour_m           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    email_verified_at  TIMESTAMP NULL,
    banned_at          TIMESTAMP NULL,
    disabled_at        TIMESTAMP NULL,
    deleted_at         TIMESTAMP NULL,
    last_login_at      TIMESTAMP NULL,
    avatar_updated_at  TIMESTAMP NULL,
    is_admin           TINYINT(1) NOT NULL DEFAULT 0,
    auth_epoch         INT UNSIGNED NOT NULL DEFAULT 0,
    notify_matches     TINYINT(1) NOT NULL DEFAULT 1,
    unsub_token        VARCHAR(64) NULL,
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journeys (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    label           VARCHAR(120) NOT NULL,
    start_point     POINT NOT NULL,
    end_point       POINT NOT NULL,
    route_line      LINESTRING NULL,
    start_time      TIME NOT NULL,
    days_mask       TINYINT UNSIGNED NOT NULL,
    direction       ENUM('offer','request') NOT NULL DEFAULT 'offer',
    seats           TINYINT UNSIGNED NOT NULL DEFAULT 1,
    radius_m        SMALLINT UNSIGNED NOT NULL DEFAULT 2000,
    window_min      TINYINT  UNSIGNED NOT NULL DEFAULT 30,
    -- Per-journey age + sex filter on counterparties. Seeded from the
    -- user's profile defaults at create-time; editable per journey.
    age_min         TINYINT UNSIGNED NULL,
    age_max         TINYINT UNSIGNED NULL,
    pref_sex        ENUM('any','male','female') NOT NULL DEFAULT 'any',
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    SPATIAL INDEX idx_start (start_point),
    SPATIAL INDEX idx_end   (end_point)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lift_requests (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    from_journey_id INT UNSIGNED NOT NULL,
    to_journey_id   INT UNSIGNED NOT NULL,
    status          ENUM('pending','accepted','declined','cancelled') NOT NULL DEFAULT 'pending',
    message         VARCHAR(500) NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    responded_at    TIMESTAMP NULL,
    FOREIGN KEY (from_journey_id) REFERENCES journeys(id) ON DELETE CASCADE,
    FOREIGN KEY (to_journey_id)   REFERENCES journeys(id) ON DELETE CASCADE,
    UNIQUE KEY uq_pair (from_journey_id, to_journey_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_oauth_accounts (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id          INT UNSIGNED NOT NULL,
    provider         VARCHAR(20)  NOT NULL,
    provider_user_id VARCHAR(255) NOT NULL,
    email            VARCHAR(191) NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_provider_id (provider, provider_user_id),
    INDEX idx_user (user_id),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_attempts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip           VARCHAR(45) NOT NULL,
    action       VARCHAR(40) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_action_time (ip, action, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS auth_tokens (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    purpose      ENUM('verify_email','password_reset') NOT NULL,
    token_hash   CHAR(64) NOT NULL,
    expires_at   TIMESTAMP NOT NULL,
    used_at      TIMESTAMP NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_hash (token_hash),
    INDEX idx_user_purpose (user_id, purpose)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_blocks (
    blocker_id INT UNSIGNED NOT NULL,
    blocked_id INT UNSIGNED NOT NULL,
    reason     VARCHAR(280) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (blocker_id, blocked_id),
    FOREIGN KEY (blocker_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (blocked_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_blocked (blocked_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_reports (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reporter_id  INT UNSIGNED NOT NULL,
    target_id    INT UNSIGNED NOT NULL,
    reason       VARCHAR(60)  NOT NULL,
    detail       VARCHAR(1000) NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    resolved_at  TIMESTAMP NULL,
    FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (target_id)   REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_target (target_id),
    INDEX idx_unresolved (resolved_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lift_request_id INT UNSIGNED NOT NULL,
    sender_id       INT UNSIGNED NOT NULL,
    body            VARCHAR(280) NOT NULL,
    -- 'system' rows are auto-generated notices (e.g. driver moved pins).
    -- The UI renders them centred + italic, separate from chat bubbles.
    kind            ENUM('user','system') NOT NULL DEFAULT 'user',
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    read_at         TIMESTAMP NULL,
    FOREIGN KEY (lift_request_id) REFERENCES lift_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id)       REFERENCES users(id)         ON DELETE CASCADE,
    INDEX idx_lr_created     (lift_request_id, created_at),
    INDEX idx_sender_created (sender_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journey_groups (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    type        ENUM('school_run','other') NOT NULL DEFAULT 'school_run',
    dest_point  POINT NULL,
    dest_label  VARCHAR(160) NULL,
    start_time  TIME NOT NULL,
    days_mask   TINYINT UNSIGNED NOT NULL,
    creator_id  INT UNSIGNED NOT NULL,
    invite_code CHAR(16) NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (creator_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_invite_code (invite_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journey_group_members (
    group_id    INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    home_point  POINT NULL,
    home_label  VARCHAR(160) NULL,
    joined_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (group_id, user_id),
    FOREIGN KEY (group_id) REFERENCES journey_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)  REFERENCES users(id)          ON DELETE CASCADE,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journey_group_rotation (
    group_id        INT UNSIGNED NOT NULL,
    day_of_week     TINYINT UNSIGNED NOT NULL,
    driver_user_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (group_id, day_of_week),
    FOREIGN KEY (group_id)       REFERENCES journey_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (driver_user_id) REFERENCES users(id)          ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Public-visible changelog / issue tracker. Shown in the About page so
-- users can see what's planned, what's in progress and what got fixed
-- in which version. Operator-only writes via the admin dashboard.
CREATE TABLE IF NOT EXISTS issues (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind             ENUM('feature','improvement','bug') NOT NULL DEFAULT 'feature',
    title            VARCHAR(160) NOT NULL,
    description      TEXT NULL,
    status           ENUM('planned','in_progress','fixed','rejected') NOT NULL DEFAULT 'planned',
    severity         ENUM('low','medium','high','critical') NULL,
    target_version   VARCHAR(20)  NULL,
    fixed_in_version VARCHAR(20)  NULL,
    is_public        TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order       INT          NOT NULL DEFAULT 0,
    created_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_public_status (is_public, status),
    INDEX idx_fixed_in      (fixed_in_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Dedupe ledger for "a new journey matches yours" emails: one row per
-- (recipient, source journey) so nobody is emailed twice about the same
-- new journey.
CREATE TABLE IF NOT EXISTS match_notifications (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipient_user_id INT UNSIGNED NOT NULL,
    source_journey_id INT UNSIGNED NOT NULL,
    created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_recip_source (recipient_user_id, source_journey_id),
    INDEX idx_recip (recipient_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Small key/value store for app-level settings. Currently holds the live
-- version string (set via update.php); falls back to the Version::CURRENT
-- constant when absent.
CREATE TABLE IF NOT EXISTS app_meta (
    k          VARCHAR(64) PRIMARY KEY,
    v          VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

/**
 * <summary>
 * Returns the full list of columns the application expects each table to
 * have, used by the portable column add migration to bring an older
 * install up to date without needing MariaDB specific syntax.
 * </summary>
 * <returns>
 * A list of triplets [table, column, type definition suffix]. The type
 * definition is the part that follows ADD COLUMN `name` in the generated
 * ALTER TABLE, for example "VARCHAR(500) NULL" or
 * "TINYINT(1) NOT NULL DEFAULT 0".
 * </returns>
 * <remarks>
 * Works on MySQL 5.7+ and MariaDB. The naive approach of using ADD COLUMN
 * IF NOT EXISTS would be cleaner but is MariaDB only, so the runner checks
 * INFORMATION_SCHEMA.COLUMNS first and only emits an ALTER for genuinely
 * missing columns. Inline remarks on specific columns capture rationale,
 * for example the split of disabled_at from banned_at: ban is misbehaviour
 * (admin action), disable is auto applied when the email verify deadline
 * lapses. The auth_epoch column drives forced sign out on password change,
 * letting a reset log out other devices.
 * </remarks>
 */
function expectedColumns(): array
{
    return [
        // users
        ['users', 'avatar_url',         "VARCHAR(500) NULL"],
        ['users', 'bio',                "VARCHAR(280) NULL"],
        ['users', 'age',                "TINYINT UNSIGNED NULL"],
        ['users', 'age_min',            "TINYINT UNSIGNED NULL"],
        ['users', 'age_max',            "TINYINT UNSIGNED NULL"],
        ['users', 'is_away',            "TINYINT(1) NOT NULL DEFAULT 0"],
        ['users', 'theme',              "ENUM('dark','light','auto') NOT NULL DEFAULT 'light'"],
        ['users', 'default_radius_m',   "SMALLINT UNSIGNED NOT NULL DEFAULT 500"],
        ['users', 'default_window_min', "TINYINT UNSIGNED NOT NULL DEFAULT 10"],
        ['users', 'car_make',           "VARCHAR(60) NULL"],
        ['users', 'car_colour',         "VARCHAR(40) NULL"],
        ['users', 'car_seats',          "TINYINT UNSIGNED NULL"],
        ['users', 'pref_smoking',       "ENUM('yes','no','outside') NULL"],
        ['users', 'pref_pets',          "ENUM('yes','no','small_only') NULL"],
        ['users', 'pref_music',         "VARCHAR(80) NULL"],
        ['users', 'sex',                "ENUM('male','female') NULL"],
        ['users', 'pref_sex',           "ENUM('any','male','female') NOT NULL DEFAULT 'any'"],
        ['users', 'detour_m',           "SMALLINT UNSIGNED NOT NULL DEFAULT 0"],
        ['users', 'email_verified_at',  "TIMESTAMP NULL"],
        ['users', 'banned_at',          "TIMESTAMP NULL"],
        // disabled_at is distinct from banned_at: ban = misbehaviour
        // (admin action), disable = auto-applied when the email-verify
        // deadline (15 days) passes, recoverable if the user gets in
        // touch with a legit reason. Both states make the account
        // inert; the column split keeps the audit story clean.
        ['users', 'disabled_at',        "TIMESTAMP NULL"],
        ['users', 'deleted_at',         "TIMESTAMP NULL"],
        ['users', 'last_login_at',      "TIMESTAMP NULL"],
        ['users', 'avatar_updated_at',  "TIMESTAMP NULL"],
        ['users', 'is_admin',           "TINYINT(1) NOT NULL DEFAULT 0"],
        // Bumped on every password change. Sessions snapshot this value
        // at login and the front controller evicts any session whose
        // stored epoch != the current users.auth_epoch. Lets a password
        // reset actually log out other devices.
        ['users', 'auth_epoch',         "INT UNSIGNED NOT NULL DEFAULT 0"],
        // Match-alert emails: opt-out flag (default on) + a stable per-user
        // token for the one-click unsubscribe link.
        ['users', 'notify_matches',     "TINYINT(1) NOT NULL DEFAULT 1"],
        ['users', 'unsub_token',        "VARCHAR(64) NULL"],
        ['users', 'updated_at',         "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"],
        // journeys
        ['journeys', 'seats',           "TINYINT UNSIGNED NOT NULL DEFAULT 1"],
        ['journeys', 'radius_m',        "SMALLINT UNSIGNED NOT NULL DEFAULT 2000"],
        ['journeys', 'window_min',      "TINYINT UNSIGNED NOT NULL DEFAULT 30"],
        // Per-journey age + sex filter on who you want to share with. Seeded
        // from the user's profile defaults at create-time, then editable per
        // journey. NULL age_min/age_max means "no age limit on this side".
        ['journeys', 'age_min',         "TINYINT UNSIGNED NULL"],
        ['journeys', 'age_max',         "TINYINT UNSIGNED NULL"],
        ['journeys', 'pref_sex',        "ENUM('any','male','female') NOT NULL DEFAULT 'any'"],
        // lift_requests
        ['lift_requests', 'responded_at', "TIMESTAMP NULL"],
        // messages, `kind` distinguishes user-typed chats from automated
        // system notes (e.g. "driver moved the pickup pin"). UI renders
        // system messages centred + italic so they don't masquerade as the
        // driver typing.
        ['messages', 'kind',              "ENUM('user','system') NOT NULL DEFAULT 'user'"],
        // user_oauth_accounts (only matters if upgrading from the earlier rename)
        ['user_oauth_accounts', 'email',      "VARCHAR(191) NULL"],
        ['user_oauth_accounts', 'updated_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"],
        // issues (public changelog), every column lives here so the
        // table can be created from the inline schema OR via this list
        // on an existing install where the CREATE didn't fire.
        ['issues', 'kind',             "ENUM('feature','improvement','bug') NOT NULL DEFAULT 'feature'"],
        ['issues', 'title',            "VARCHAR(160) NOT NULL"],
        ['issues', 'description',      "TEXT NULL"],
        ['issues', 'status',           "ENUM('planned','in_progress','fixed','rejected') NOT NULL DEFAULT 'planned'"],
        ['issues', 'severity',         "ENUM('low','medium','high','critical') NULL"],
        ['issues', 'target_version',   "VARCHAR(20)  NULL"],
        ['issues', 'fixed_in_version', "VARCHAR(20)  NULL"],
        ['issues', 'is_public',        "TINYINT(1)   NOT NULL DEFAULT 1"],
        ['issues', 'sort_order',       "INT          NOT NULL DEFAULT 0"],
        ['issues', 'updated_at',       "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"],
    ];
}

/**
 * <summary>
 * Looks up the column names that currently exist on a given table in the
 * active database, used by the migration runner to decide whether an
 * ADD COLUMN is needed.
 * </summary>
 * <param name="pdo">Live PDO connection to the SwiftLift database.</param>
 * <param name="table">Name of the table to inspect.</param>
 * <returns>
 * A list of column names, lower cased so callers can compare without
 * worrying about case sensitivity differences between MySQL and MariaDB.
 * </returns>
 * <remarks>
 * Reads from INFORMATION_SCHEMA.COLUMNS scoped to DATABASE() so the answer
 * is correct regardless of which database the credentials happen to point
 * at. Returns an empty list if the table does not yet exist, which is the
 * natural starting state when the migrator runs on a fresh install before
 * the CREATE TABLE pass.
 * </remarks>
 */
function existingColumns(\PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->execute([$table]);
    return array_map('strtolower', $stmt->fetchAll(\PDO::FETCH_COLUMN));
}

/**
 * <summary>
 * Brings an existing database into line with the current schema. Adds any
 * missing columns, tightens or relaxes a handful of constraints, removes
 * legacy columns that are no longer used, and folds the old oauth_accounts
 * table into user_oauth_accounts if needed.
 * </summary>
 * <param name="pdo">Live PDO connection to the SwiftLift database.</param>
 * <returns>
 * A list of result rows, one per statement attempted. Each row has the
 * shape ['sql' =&gt; string, 'status' =&gt; 'ok'|'error', 'message' =&gt; string?]
 * so the UI can print the trail of what ran and what failed.
 * </returns>
 * <remarks>
 * Designed to be safe on fresh installs (everything is already in the
 * desired state, so all checks short circuit) and on older installs
 * (missing columns are added, dropped columns are removed). Each step is
 * wrapped in its own try block so one failure does not abort the rest.
 *
 * Removed columns and the reasons they went:
 *   fuel_pence_per_km. Cost share dropped because paid lifts require a
 *   taxi/PHV licence that SwiftLift drivers do not hold.
 *   phone. Phone numbers were collected but never surfaced to other users,
 *   and in app chat already covers contact swapping. Holding the data
 *   served no purpose.
 *
 * The oauth_accounts to user_oauth_accounts rename handles three cases:
 * neither table present (no op), only the old name present (RENAME), both
 * present (INSERT IGNORE the orphans then DROP the old).
 * </remarks>
 */
function runColumnMigrations(\PDO $pdo): array
{
    $results = [];
    $cache   = [];
    foreach (expectedColumns() as [$table, $col, $def]) {
        if (!isset($cache[$table])) $cache[$table] = existingColumns($pdo, $table);
        if (in_array(strtolower($col), $cache[$table], true)) continue;

        $sql = "ALTER TABLE `$table` ADD COLUMN `$col` $def";
        try {
            $pdo->exec($sql);
            $cache[$table][] = strtolower($col);
            $results[] = ['sql' => $sql, 'status' => 'ok'];
        } catch (\Throwable $e) {
            $results[] = ['sql' => $sql, 'status' => 'error', 'message' => $e->getMessage()];
        }
    }
    /**
     * <summary>Brings the theme default in line on existing tables.</summary>
     */
    try {
        $pdo->exec("ALTER TABLE users MODIFY COLUMN theme ENUM('dark','light','auto') NOT NULL DEFAULT 'light'");
        $results[] = ['sql' => "ALTER TABLE users MODIFY COLUMN theme ... DEFAULT 'light'", 'status' => 'ok'];
    } catch (\Throwable $e) {
        $results[] = ['sql' => "ALTER TABLE users MODIFY COLUMN theme", 'status' => 'error', 'message' => $e->getMessage()];
    }
    /**
     * <summary>
     * Relaxes password_hash to NULL so OAuth users (who never set a local
     * password) can live in the users table alongside email and password
     * accounts.
     * </summary>
     */
    try {
        $pdo->exec("ALTER TABLE users MODIFY COLUMN password_hash VARCHAR(255) NULL");
        $results[] = ['sql' => "ALTER TABLE users MODIFY COLUMN password_hash NULL", 'status' => 'ok'];
    } catch (\Throwable $e) {
        $results[] = ['sql' => "ALTER TABLE users MODIFY COLUMN password_hash", 'status' => 'error', 'message' => $e->getMessage()];
    }

    /**
     * <summary>
     * Drops the legacy fuel_pence_per_km column from users where present.
     * Money and cost share are no longer part of SwiftLift because paid
     * lifts require a taxi/PHV licence that drivers do not hold.
     * </summary>
     * <remarks>
     * Checks for the column first so a missing column on a fresh install
     * is a clean no op rather than being reported as an error.
     * </remarks>
     */
    try {
        $cols = existingColumns($pdo, 'users');
        if (in_array('fuel_pence_per_km', $cols, true)) {
            $pdo->exec("ALTER TABLE users DROP COLUMN fuel_pence_per_km");
            $results[] = ['sql' => "ALTER TABLE users DROP COLUMN fuel_pence_per_km", 'status' => 'ok'];
        }
    } catch (\Throwable $e) {
        $results[] = ['sql' => "ALTER TABLE users DROP COLUMN fuel_pence_per_km", 'status' => 'error', 'message' => $e->getMessage()];
    }

    /**
     * <summary>
     * Drops the legacy phone column from users where present. Phone numbers
     * were collected but never shown to other users, and the in app chat
     * already covers contact swapping. Holding unused personal data is bad
     * practice, so the column goes.
     * </summary>
     */
    try {
        $cols = $cols ?? existingColumns($pdo, 'users');
        if (in_array('phone', $cols, true)) {
            $pdo->exec("ALTER TABLE users DROP COLUMN phone");
            $results[] = ['sql' => "ALTER TABLE users DROP COLUMN phone", 'status' => 'ok'];
        }
    } catch (\Throwable $e) {
        $results[] = ['sql' => "ALTER TABLE users DROP COLUMN phone", 'status' => 'error', 'message' => $e->getMessage()];
    }

    /**
     * <summary>
     * Folds the legacy oauth_accounts table into the current
     * user_oauth_accounts table. Old name only renames to the new name; if
     * both exist, copies any orphans across with INSERT IGNORE then drops
     * the old table.
     * </summary>
     */
    try {
        $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
        $check->execute(['oauth_accounts']);
        $oldExists = (int) $check->fetchColumn();
        $check->execute(['user_oauth_accounts']);
        $newExists = (int) $check->fetchColumn();
        if ($oldExists && !$newExists) {
            $pdo->exec("RENAME TABLE oauth_accounts TO user_oauth_accounts");
            $results[] = ['sql' => "RENAME TABLE oauth_accounts TO user_oauth_accounts", 'status' => 'ok'];
        } elseif ($oldExists && $newExists) {
            // Both exist, copy any orphan rows then drop the old.
            $pdo->exec(
                "INSERT IGNORE INTO user_oauth_accounts (user_id, provider, provider_user_id, created_at)
                 SELECT user_id, provider, provider_user_id, created_at FROM oauth_accounts"
            );
            $pdo->exec("DROP TABLE oauth_accounts");
            $results[] = ['sql' => "Merged oauth_accounts into user_oauth_accounts and dropped old table", 'status' => 'ok'];
        }
    } catch (\Throwable $e) {
        $results[] = ['sql' => "Migrate oauth_accounts → user_oauth_accounts", 'status' => 'error', 'message' => $e->getMessage()];
    }
    return $results;
}

/**
 * <summary>
 * Snapshots the current database. Lists every table and how many rows
 * it currently holds. Used both for the "Current tables" panel and to
 * gate the destructive paths in the form handler.
 * </summary>
 * <returns>
 * On success ['ok' =&gt; true, 'tables' =&gt; [name =&gt; row count, ...]]; on
 * failure ['ok' =&gt; false, 'error' =&gt; string]. Returning a result shape
 * rather than throwing lets the caller render the connection failure as
 * an inline message instead of a white screen.
 * </returns>
 */
function tableInfo(): array
{
    try {
        $pdo = Db::pdo();
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $info = [];
        foreach ($tables as $t) {
            $count = (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
            $info[$t] = $count;
        }
        return ['ok' => true, 'tables' => $info];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * <summary>
 * Splits a multi statement SQL blob into individual statements ready for
 * PDO::exec, ignoring semicolons inside quoted string literals.
 * </summary>
 * <param name="sql">Raw SQL text, possibly with line comments and many statements separated by semicolons.</param>
 * <returns>
 * A list of trimmed statements with comments and blank lines stripped.
 * Empty statements are dropped.
 * </returns>
 * <remarks>
 * Used because PDO::exec on most drivers will not accept a multi statement
 * string. The splitter walks the input character by character, tracking
 * whether it is currently inside a single quoted or double quoted string,
 * so a literal semicolon inside an ENUM list or a default string value
 * does not falsely terminate the statement. Line comments starting with
 * "--" are dropped before the split so the parser does not have to know
 * about them.
 * </remarks>
 */
function splitStatements(string $sql): array
{
    // Strip line comments, then split on semicolons not inside quotes.
    $lines = preg_split('/\R/', $sql);
    $clean = [];
    foreach ($lines as $line) {
        $trim = ltrim($line);
        if (str_starts_with($trim, '--') || $trim === '') continue;
        $clean[] = $line;
    }
    $sql = implode("\n", $clean);

    $stmts = [];
    $buf = '';
    $inS = false; $inD = false;
    for ($i = 0, $n = strlen($sql); $i < $n; $i++) {
        $c = $sql[$i];
        if ($c === "'" && !$inD) $inS = !$inS;
        elseif ($c === '"' && !$inS) $inD = !$inD;
        if ($c === ';' && !$inS && !$inD) {
            $s = trim($buf);
            if ($s !== '') $stmts[] = $s;
            $buf = '';
        } else {
            $buf .= $c;
        }
    }
    $s = trim($buf);
    if ($s !== '') $stmts[] = $s;
    return $stmts;
}

/**
 * <summary>
 * Reads the POST request, captures the three knobs the form exposes
 * (action, drop, force) and primes the result accumulator the page will
 * render below.
 * </summary>
 * <remarks>
 * drop means "DROP TABLE the legacy tables before recreating" and is the
 * destructive path. force means "run anyway even if tables are not empty"
 * and is the safety net for the also non destructive CREATE IF NOT EXISTS
 * pass. Both must be opted into individually via checkboxes in the form.
 * </remarks>
 */
$action  = $_POST['action']  ?? null;
$drop    = !empty($_POST['drop']);
$force   = !empty($_POST['force']);
$results = [];
$ran     = false;

/**
 * <summary>
 * Action handler for the "run" POST. Runs the setup pipeline: schema
 * CREATE pass, optional DROP first, then the portable column migration.
 * Bails out with an error if data is present and the user did not tick
 * force.
 * </summary>
 */
if ($action === 'run' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $ran = true;
    $state = tableInfo();
    $hasData = false;
    if ($state['ok']) {
        foreach ($state['tables'] as $count) if ($count > 0) $hasData = true;
    }

    if ($hasData && !$force) {
        $results[] = ['sql' => '(aborted)', 'status' => 'error',
                      'message' => 'Tables contain data. Tick "force" to run anyway (CREATE IF NOT EXISTS is still non-destructive).'];
    } else {
        $pdo = Db::pdo();

        /**
         * <summary>
         * Optional destructive prepass. When drop is ticked, removes the
         * three core tables in foreign key safe order before the CREATE
         * pass runs. Used when the operator wants a clean rebuild.
         * </summary>
         */
        if ($drop) {
            foreach (['lift_requests', 'journeys', 'users'] as $t) {
                try {
                    $pdo->exec("DROP TABLE IF EXISTS `$t`");
                    $results[] = ['sql' => "DROP TABLE IF EXISTS `$t`", 'status' => 'ok'];
                } catch (Throwable $e) {
                    $results[] = ['sql' => "DROP TABLE IF EXISTS `$t`", 'status' => 'error', 'message' => $e->getMessage()];
                }
            }
        }

        /**
         * <summary>
         * Main CREATE pass. Executes every statement in the inline SQL
         * blob one at a time, recording success or failure per statement
         * so the result panel can show a per statement trail.
         * </summary>
         */
        foreach (splitStatements($sqlText) as $stmt) {
            try {
                $pdo->exec($stmt);
                $results[] = ['sql' => $stmt, 'status' => 'ok'];
            } catch (Throwable $e) {
                $results[] = ['sql' => $stmt, 'status' => 'error', 'message' => $e->getMessage()];
            }
        }
        /**
         * <summary>
         * Portable column migration after the CREATE TABLE pass. Adds any
         * columns missing on older installs and removes legacy ones. This
         * is the step that makes the page safe to rerun.
         * </summary>
         */
        foreach (runColumnMigrations($pdo) as $r) {
            $results[] = $r;
        }
    }
}

/**
 * <summary>
 * Fresh snapshot of the database after any migration ran, used by the
 * "Current tables" panel below so the rendered list reflects what the
 * setup actually ended up with rather than the pre run state.
 * </summary>
 */
$state = tableInfo();

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>SwiftLift: Database Setup</title>
<style>
  :root { color-scheme: dark; }
  body { font-family: ui-sans-serif, system-ui, sans-serif; background: #0f172a; color: #f1f5f9;
         max-width: 900px; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; }
  h1 { margin-top: 0; }
  code, pre { font-family: ui-monospace, Menlo, Consolas, monospace; }
  pre { background: #1e293b; padding: 1rem; border-radius: 8px; overflow: auto; font-size: .85rem; }
  .panel { background: #1e293b; border-radius: 10px; padding: 1rem 1.25rem; margin: 1rem 0; border: 1px solid #334155; }
  .row { display: flex; justify-content: space-between; padding: .3rem 0; border-bottom: 1px solid #334155; }
  .row:last-child { border: 0; }
  .ok { color: #22c55e; }
  .error { color: #ef4444; }
  .muted { color: #94a3b8; }
  button { background: #2563eb; color: white; border: 0; padding: .7rem 1.2rem;
           border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 1rem; }
  button.danger { background: #b91c1c; }
  label { display: inline-flex; gap: .4rem; align-items: center; margin-right: 1rem; }
  .actions { display: flex; gap: .75rem; align-items: center; margin-top: 1rem; flex-wrap: wrap; }
  .result { padding: .5rem; border-radius: 6px; margin-bottom: .3rem; background: #0b1221; }
  .result.error { background: #3f1d1d; }
  .result pre { margin: .3rem 0 0; padding: .5rem; }
</style>
</head>
<body>

<h1>SwiftLift: Database Setup</h1>

<p class="muted">
  Runs the schema below against the database configured in
  <code>.env</code>. Schema uses <code>CREATE TABLE IF NOT EXISTS</code> so running
  this on an already-set-up DB is safe.
</p>

<?php
/**
 * <summary>
 * Connection panel. Echoes the DB host, name and user from .env so the
 * operator can confirm at a glance that setup is pointed at the right
 * database before pressing the button.
 * </summary>
 */
?>
<div class="panel">
  <h2 style="margin-top:0">Connection</h2>
  <div class="row"><span>Host</span><code><?= htmlspecialchars(Env::get('DB_HOST', '?') ?? '?') ?></code></div>
  <div class="row"><span>Database</span><code><?= htmlspecialchars(Env::get('DB_NAME', '?') ?? '?') ?></code></div>
  <div class="row"><span>User</span><code><?= htmlspecialchars(Env::get('DB_USER', '?') ?? '?') ?></code></div>
</div>

<?php
/**
 * <summary>
 * Current tables panel. Renders three different states: a connection
 * failure block, an empty database hint, or the table by table row count
 * summary returned from tableInfo().
 * </summary>
 */
?>
<div class="panel">
  <h2 style="margin-top:0">Current tables</h2>
  <?php if (!$state['ok']): ?>
    <div class="error">Could not connect: <?= htmlspecialchars($state['error']) ?></div>
  <?php elseif (empty($state['tables'])): ?>
    <div class="muted">No tables yet. Click <b>Run setup</b> below.</div>
  <?php else: ?>
    <?php foreach ($state['tables'] as $t => $count): ?>
      <div class="row">
        <code><?= htmlspecialchars($t) ?></code>
        <span class="muted"><?= $count ?> row<?= $count === 1 ? '' : 's' ?></span>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php
/**
 * <summary>
 * Result panel. Only rendered after a POST run so the page does not
 * carry stale output. Lists each statement that ran along with its
 * status, and shows the error message verbatim where one statement
 * failed so the operator can copy it into a search or bug report.
 * </summary>
 */
?>
<?php if ($ran): ?>
<div class="panel">
  <h2 style="margin-top:0">Result</h2>
  <?php foreach ($results as $r): ?>
    <div class="result <?= $r['status'] ?>">
      <span class="<?= $r['status'] ?>"><?= $r['status'] === 'ok' ? '✓' : '✗' ?></span>
      <code><?= htmlspecialchars(substr(preg_replace('/\s+/', ' ', $r['sql']), 0, 160)) ?></code>
      <?php if (!empty($r['message'])): ?>
        <pre><?= htmlspecialchars($r['message']) ?></pre>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
/**
 * <summary>
 * Run setup panel. The single point of entry for the setup pipeline.
 * Carries the ?token=... through to the POST action so the form submit
 * stays inside the gated section. Exposes the two opt in checkboxes for
 * force (run even with data) and drop (destructive prepass).
 * </summary>
 */
?>
<div class="panel">
  <h2 style="margin-top:0">Run setup</h2>
  <form method="post" action="setup.php<?= isset($_GET['token']) ? '?token=' . urlencode((string)$_GET['token']) : '' ?>">
    <input type="hidden" name="action" value="run">
    <label><input type="checkbox" name="force"> force (run even if tables have data)</label>
    <label><input type="checkbox" name="drop"> <b class="error">DROP existing tables first</b> (destroys data)</label>
    <div class="actions">
      <button type="submit">Run setup</button>
      <span class="muted">After running, delete this file from the server, or set <code>SETUP_TOKEN</code> in <code>.env</code>.</span>
    </div>
  </form>
</div>

<?php
/**
 * <summary>
 * Collapsible schema viewer. Lets the operator inspect the exact SQL
 * the page is about to run without opening the file on disk.
 * </summary>
 */
?>
<details class="panel">
  <summary>View the schema</summary>
  <pre><?= htmlspecialchars($sqlText) ?></pre>
</details>

</body>
</html>
