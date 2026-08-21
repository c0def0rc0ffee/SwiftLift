<?php
declare(strict_types=1);

use SwiftLift\{Http, Auth, AuthTokenRepo, Mailer, PasswordSecurity, UserRepo};

/**
 * <summary>
 * /api/profile router. Reads, updates, and deletes the signed in user's
 * profile. Also exposes password change and the GDPR data export endpoint.
 * </summary>
 * <remarks>
 * Every branch requires an authenticated session. Sensitive changes
 * (password change, email change, account deletion) go through
 * dedicated paths with their own validation rules so a hijacked session
 * cannot mass assign its way to a takeover. The export endpoint
 * returns everything we hold against the calling user as a downloadable
 * JSON file for portability and right of access compliance.
 * </remarks>
 */

$uid    = Auth::require();
$method = $_SERVER['REQUEST_METHOD'];
$tail   = $_GET['_tail'] ?? '';

/**
 * <summary>
 * POST /api/profile/password. Changes the signed in user's password.
 * </summary>
 * <remarks>
 * Requires the current password as well as the new one. Password
 * strength is validated by PasswordSecurity::validate. On success
 * the session id is regenerated to invalidate any copies of the old
 * session that may exist on other devices via the forgot password
 * flow; we are already authenticated as the calling user so this is
 * an in place regenerate, not a logout. changePassword bumps the
 * user's auth_epoch, and the local session's snapshot is refreshed
 * so this device's next request is not evicted by the front
 * controller's epoch mismatch check. Other devices keep their old
 * epoch and get kicked out on their next request, which is the whole
 * point of the mechanism.
 * </remarks>
 */
if ($method === 'POST' && $tail === 'password') {
    $b   = Http::body();
    $cur = (string) ($b['current_password'] ?? '');
    $new = (string) ($b['new_password'] ?? '');
    $pwErr = PasswordSecurity::validate($new);
    if ($pwErr !== null) Http::error($pwErr);

    $ok = UserRepo::changePassword($uid, $cur, $new);
    if (!$ok) Http::error('Current password is incorrect', 401);

    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);

    Auth::refreshAuthEpoch($uid);

    Http::json(['ok' => true]);
}

/**
 * <summary>
 * GET /api/profile. Returns the calling user's profile row.
 * </summary>
 * <remarks>
 * Wrapped in a "user" envelope for symmetry with the PATCH response
 * shape. password_hash is never returned by UserRepo::findById.
 * </remarks>
 */
if ($method === 'GET' && $tail === '') {
    Http::json(['user' => UserRepo::findById($uid)]);
}

/**
 * <summary>
 * PATCH /api/profile. Partially updates the calling user's profile fields.
 * </summary>
 * <remarks>
 * Email changes are handled by a separate branch inside this handler
 * so a hijacked session cannot silently flip the email and pivot
 * through password reset. The avatar URL is checked to be a real
 * http(s) URL (rejecting data:, javascript:, blob: and so on) and is
 * length capped at 500 characters to match the column width. Display
 * name cannot be set to whitespace only. All other writes flow into
 * UserRepo::updateProfile which whitelists the safe columns.
 * </remarks>
 */
if ($method === 'PATCH' && $tail === '') {
    $b = Http::body();

    /**
     * <summary>
     * Sub branch for email change. Requires current password re-prompt and
     * clears the verified flag.
     * </summary>
     * <remarks>
     * Handled separately from the mass assignment path so the password
     * re-prompt cannot be bypassed by simply omitting current_password
     * from a wider PATCH. UserRepo::changeEmail enforces uniqueness
     * and rejects OAuth only accounts that have no password to verify
     * against. The uniqueness collision is mapped to a 409, every
     * other failure mode (wrong password, OAuth only) to a 401, which
     * mirrors the previous behaviour.
     * </remarks>
     */
    if (array_key_exists('email', $b)) {
        $newEmail = strtolower(trim((string) $b['email']));
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) Http::error('Invalid email');

        $current = UserRepo::findById($uid);
        if ($current === null) Http::error('User not found', 404);
        if (strtolower((string) $current['email']) !== $newEmail) {
            $pw = (string) ($b['current_password'] ?? '');
            if ($pw === '') {
                Http::error('Current password is required to change your email', 401);
            }
            try {
                UserRepo::changeEmail($uid, $newEmail, $pw);
            } catch (\Throwable $e) {
                $status = (strpos($e->getMessage(), 'already in use') !== false) ? 409 : 401;
                Http::error($e->getMessage(), $status);
            }

            /**
             * <summary>
             * Send a fresh verification email to the new address so the user
             * re-confirms ownership.
             * </summary>
             * <remarks>
             * The Mailer outbox fallback keeps development working
             * without a real mail() configuration. Mail send failure
             * is logged but never blocks the API response, because by
             * this point the email change has already committed.
             * </remarks>
             */
            try {
                $token = AuthTokenRepo::issue($uid, 'verify_email', 60 * 24);
                $link  = Http::appBaseUrl() . '/?verify=' . urlencode($token);
                $body  = "Hi " . ($current['display_name'] ?: 'there') . ",\n\n" .
                         "Your SwiftLift email was just changed to this address.\n" .
                         "Confirm it by opening this link in your browser within 24 hours:\n\n" .
                         $link . "\n\n" .
                         "If this wasn't you, sign in to SwiftLift and change your password immediately.\n\n" .
                         "Thanks,\nSwiftLift\n";
                Mailer::send($newEmail, 'Confirm your new SwiftLift email', $body);
            } catch (\Throwable $e) {
                error_log('[SwiftLift] post-email-change verify send failed for user ' . $uid . ': ' . $e->getMessage());
            }
        }

        // Strip handled fields so updateProfile does not see them.
        unset($b['email'], $b['current_password']);
    }

    if (isset($b['display_name']) && trim((string) $b['display_name']) === '') {
        Http::error('Display name cannot be empty');
    }

    /**
     * <summary>
     * Avatar images are disabled for now, so any avatar_url in the payload
     * is ignored.
     * </summary>
     * <remarks>
     * A free-text avatar URL with no moderation is an abuse vector
     * (arbitrary or offensive imagery, hotlinking, tracking pixels). We
     * strip the key so updateProfile never sees it, existing values are
     * left untouched (the column update is keyed on array_key_exists), and
     * a hand-crafted PATCH can't set or change an avatar. The client hides
     * the field too; this is the server-side backstop. Re-enable by
     * restoring the scheme/length validation below and flipping
     * AVATARS_ENABLED in web/src/lib/features.ts.
     *
     *   if (array_key_exists('avatar_url', $b) && $b['avatar_url'] !== null && $b['avatar_url'] !== '') {
     *       $url = (string) $b['avatar_url'];
     *       if (mb_strlen($url) > 500) Http::error('Avatar URL is too long (max 500 characters)');
     *       if (!preg_match('~^https?://~i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
     *           Http::error('Avatar URL must be a full http(s) URL');
     *       }
     *   }
     */
    unset($b['avatar_url']);

    Http::json(['user' => UserRepo::updateProfile($uid, $b)]);
}

/**
 * <summary>
 * DELETE /api/profile. Marks the calling user's account as deleted and logs them out.
 * </summary>
 * <remarks>
 * UserRepo::delete is a soft delete: deleted_at is stamped, the
 * password hash is scrubbed, and the account behaves as if the email
 * never existed from any future probe. Auth::logout clears the
 * session cookie so the SPA naturally falls back to its signed out
 * view on the next page load.
 * </remarks>
 */
if ($method === 'DELETE' && $tail === '') {
    UserRepo::delete($uid);
    Auth::logout();
    Http::json(['deleted' => true]);
}

/**
 * <summary>
 * GET /api/profile/export. Returns everything we hold against the calling
 * user as a JSON attachment named swiftlift-export-{uid}-{date}.json.
 * </summary>
 * <remarks>
 * Right of access export. Includes the user row (minus password
 * hash, which is never present in API responses anyway), the user's
 * journeys decoded to lat/lng pairs and WKT route lines, every lift
 * request the user sent or received with the other party's display
 * name (but not their email, which is theirs), the message body of
 * every conversation, the user's own block list, the reports they
 * have filed, and the OAuth providers they have linked. Direction
 * fields ("sent"/"received", "me"/"them") are computed in SQL so the
 * caller can render the file directly without joining tables again
 * on the client.
 * </remarks>
 */
if ($method === 'GET' && $tail === 'export') {
    $pdo = \SwiftLift\Db::pdo();

    $user = UserRepo::findById($uid);
    if ($user) unset($user['password_hash']);  // never returned anyway, defensive

    $journeys = $pdo->prepare(
        "SELECT id, label,
                ST_Y(start_point) AS start_lat, ST_X(start_point) AS start_lng,
                ST_Y(end_point)   AS end_lat,   ST_X(end_point)   AS end_lng,
                TIME_FORMAT(start_time, '%H:%i') AS start_time,
                days_mask, direction, seats, radius_m, window_min,
                ST_AsText(route_line) AS route_wkt,
                is_active, created_at
           FROM journeys WHERE user_id = ?
       ORDER BY id ASC"
    );
    $journeys->execute([$uid]);

    $liftReqs = $pdo->prepare(
        "SELECT lr.id, lr.from_journey_id, lr.to_journey_id, lr.status, lr.message,
                lr.created_at, lr.responded_at,
                CASE WHEN jf.user_id = ? THEN 'sent' ELSE 'received' END AS direction,
                other.display_name AS other_display_name
           FROM lift_requests lr
           JOIN journeys jf ON jf.id = lr.from_journey_id
           JOIN journeys jt ON jt.id = lr.to_journey_id
           JOIN users other ON other.id = CASE WHEN jf.user_id = ? THEN jt.user_id ELSE jf.user_id END
          WHERE jf.user_id = ? OR jt.user_id = ?
       ORDER BY lr.id ASC"
    );
    $liftReqs->execute([$uid, $uid, $uid, $uid]);

    $messages = $pdo->prepare(
        "SELECT m.id, m.lift_request_id, m.sender_id, m.body,
                m.created_at, m.read_at,
                CASE WHEN m.sender_id = ? THEN 'me' ELSE 'them' END AS who
           FROM messages m
           JOIN lift_requests lr ON lr.id = m.lift_request_id
           JOIN journeys jf ON jf.id = lr.from_journey_id
           JOIN journeys jt ON jt.id = lr.to_journey_id
          WHERE jf.user_id = ? OR jt.user_id = ?
       ORDER BY m.id ASC"
    );
    $messages->execute([$uid, $uid, $uid]);

    $blocks = $pdo->prepare(
        "SELECT blocked_id AS user_id, reason, created_at
           FROM user_blocks WHERE blocker_id = ? ORDER BY created_at ASC"
    );
    $blocks->execute([$uid]);

    $reports = $pdo->prepare(
        "SELECT target_id, reason, detail, created_at, resolved_at
           FROM user_reports WHERE reporter_id = ? ORDER BY id ASC"
    );
    $reports->execute([$uid]);

    $oauth = $pdo->prepare(
        "SELECT provider, provider_user_id, email, created_at
           FROM user_oauth_accounts WHERE user_id = ? ORDER BY id ASC"
    );
    $oauth->execute([$uid]);

    $payload = [
        'export_format'   => 1,
        'exported_at'     => gmdate('c'),
        'user'            => $user,
        'journeys'        => $journeys->fetchAll(),
        'lift_requests'   => $liftReqs->fetchAll(),
        'messages'        => $messages->fetchAll(),
        'blocks'          => $blocks->fetchAll(),
        'reports_filed'   => $reports->fetchAll(),
        'oauth_accounts'  => $oauth->fetchAll(),
    ];

    $filename = 'swiftlift-export-' . $uid . '-' . gmdate('Y-m-d') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

Http::error('Not found', 404);
