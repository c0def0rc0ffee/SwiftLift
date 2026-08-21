<?php
declare(strict_types=1);

namespace SwiftLift;

/**
 * <summary>
 * Sends "a new journey matches yours" emails. Called best-effort right
 * after a journey is created: it finds existing journeys the new one now
 * matches and emails each of their owners, so people who arrived to an
 * empty board get pulled back when the other side of their route appears.
 * </summary>
 * <remarks>
 * Anti-spam and privacy:
 *   - Only verified, non-away accounts that have left match alerts on
 *     are emailed; never the journey's own creator.
 *   - The same matching rules as the live matcher apply (distance, days,
 *     time window, age/sex filters, blocks).
 *   - A match_notifications row per (recipient, source journey) dedupes,
 *     so nobody is emailed twice about the same new journey, and a user
 *     with several matching routes still gets a single email.
 *   - Every email carries a one-click unsubscribe link backed by a
 *     stable per-user token.
 * Failures are swallowed and logged; this must never break journey
 * creation.
 * </remarks>
 */
final class MatchNotifier
{
    /**
     * <summary>
     * Short weekday labels in days_mask bit order (Monday = bit 0).
     * </summary>
     * <remarks>
     * Index matches the bit position, so <c>DAY_NAMES[$i]</c> is the label
     * for <c>1 &lt;&lt; $i</c>. Kept in this order to match the database
     * column and the frontend's day pickers.
     * </remarks>
     */
    private const DAY_NAMES = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    /**
     * <summary>
     * Notify the owners of existing journeys that the just-created journey
     * now matches.
     * </summary>
     * <param name="newJourneyId">Id of the journey that was just created.</param>
     */
    public static function notifyNewJourney(int $newJourneyId): void
    {
        $pdo = Db::pdo();

        // The new journey. Bail quietly if it's gone or inactive.
        $a = $pdo->prepare(
            "SELECT id, user_id, direction, is_active, TIME_FORMAT(start_time,'%H:%i') AS start_time, days_mask
               FROM journeys WHERE id = ?"
        );
        $a->execute([$newJourneyId]);
        $src = $a->fetch();
        if (!$src || !(int) $src['is_active']) return;

        // Owners of opposite-direction journeys that match this one, judged
        // by *their* radius/window (it's their match list we're mirroring),
        // filtered to verified, contactable, opted-in recipients we haven't
        // already told about this journey.
        $sql = "
            SELECT u.id   AS uid,
                   u.email AS email,
                   u.display_name AS name,
                   u.unsub_token  AS unsub_token,
                   TIME_FORMAT(b.start_time,'%H:%i') AS b_time,
                   b.days_mask AS b_days
              FROM journeys a
              JOIN users    ua ON ua.id = a.user_id
              JOIN journeys b  ON b.id != a.id
              JOIN users    u  ON u.id  = b.user_id
             WHERE a.id = :aid
               AND b.is_active   = 1
               AND b.user_id    != a.user_id
               AND b.direction  != a.direction
               AND u.is_away      = 0
               AND u.deleted_at  IS NULL
               AND u.banned_at   IS NULL
               AND u.disabled_at IS NULL
               AND u.email_verified_at IS NOT NULL
               AND u.notify_matches = 1
               AND NOT EXISTS (
                   SELECT 1 FROM user_blocks ub
                    WHERE (ub.blocker_id = a.user_id AND ub.blocked_id = b.user_id)
                       OR (ub.blocker_id = b.user_id AND ub.blocked_id = a.user_id)
               )
               AND (b.days_mask & a.days_mask) > 0
               AND LEAST(
                     ABS(TIME_TO_SEC(TIMEDIFF(b.start_time, a.start_time))),
                     86400 - ABS(TIME_TO_SEC(TIMEDIFF(b.start_time, a.start_time)))
                   ) <= (b.window_min * 60)
               AND ST_Distance_Sphere(a.start_point, b.start_point) <= b.radius_m
               AND ST_Distance_Sphere(a.end_point,   b.end_point)   <= b.radius_m
               -- B's filter must accept A's owner, and A's filter must accept B's owner.
               AND (b.age_min IS NULL OR (ua.age IS NOT NULL AND ua.age >= b.age_min))
               AND (b.age_max IS NULL OR (ua.age IS NOT NULL AND ua.age <= b.age_max))
               AND (b.pref_sex = 'any' OR ua.sex = b.pref_sex)
               AND (a.age_min IS NULL OR (u.age  IS NOT NULL AND u.age  >= a.age_min))
               AND (a.age_max IS NULL OR (u.age  IS NOT NULL AND u.age  <= a.age_max))
               AND (a.pref_sex = 'any' OR u.sex = a.pref_sex)
               AND NOT EXISTS (
                   SELECT 1 FROM match_notifications mn
                    WHERE mn.recipient_user_id = u.id AND mn.source_journey_id = a.id
               )";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':aid' => $newJourneyId]);

        // One email per recipient even if several of their routes match.
        $seen = [];
        foreach ($stmt->fetchAll() as $r) {
            $uid = (int) $r['uid'];
            if (isset($seen[$uid])) continue;
            $seen[$uid] = true;
            try {
                self::sendOne($r, (string) $src['direction'], $newJourneyId);
            } catch (\Throwable $e) {
                error_log('[SwiftLift] match-notify send failed for user ' . $uid . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * <summary>
     * Claim the (recipient, source) pair and, if newly claimed, send the email.
     * </summary>
     * <param name="r">Recipient row from the match query.</param>
     * <param name="sourceDirection">Direction of the new journey ('offer' = a driver appeared, 'request' = a passenger appeared).</param>
     * <param name="sourceJourneyId">Id of the new journey, for the dedupe ledger.</param>
     */
    private static function sendOne(array $r, string $sourceDirection, int $sourceJourneyId): void
    {
        $pdo = Db::pdo();

        // Claim first: INSERT IGNORE means concurrent creates can't double-send.
        $claim = $pdo->prepare(
            "INSERT IGNORE INTO match_notifications (recipient_user_id, source_journey_id) VALUES (?, ?)"
        );
        $claim->execute([(int) $r['uid'], $sourceJourneyId]);
        if ($claim->rowCount() === 0) return; // already notified

        $token = (string) ($r['unsub_token'] ?? '');
        if ($token === '') $token = self::ensureUnsubToken((int) $r['uid']);

        $base  = rtrim((string) (Env::get('APP_BASE_URL', '') ?? ''), '/');
        $name  = trim((string) ($r['name'] ?? '')) ?: 'there';
        $days  = self::daysLabel((int) $r['b_days']);
        $route = trim(($r['b_time'] ?? '') . ($days ? " on $days" : ''));

        // The new journey is the opposite direction to the recipient, so its
        // direction tells us who appeared.
        if ($sourceDirection === 'offer') {
            $subject = 'A driver now matches your SwiftLift route';
            $lead    = "Good news. A driver has posted a journey that matches your route ($route).";
            $action  = "Open SwiftLift to take a look and send them a lift request.";
        } else {
            $subject = 'Someone is looking for a lift on your route';
            $lead    = "Someone has posted a journey looking for a lift that matches your route ($route).";
            $action  = "Open SwiftLift to see who's around. They can send you a request to accept or decline.";
        }

        $unsub = $base . '/api/index.php?p=unsubscribe&u=' . (int) $r['uid'] . '&t=' . urlencode($token);
        $body  =
            "Hi $name,\n\n" .
            "$lead\n\n" .
            "$action\n\n" .
            "$base\n\n" .
            "----\n" .
            "You're getting this because match alerts are on for your account.\n" .
            "Turn them off any time in your profile, or unsubscribe here:\n" .
            "$unsub\n";

        Mailer::send((string) $r['email'], $subject, $body);
    }

    /**
     * <summary>
     * Generate and persist a stable unsubscribe token for a user if they
     * don't have one yet, and return it.
     * </summary>
     * <param name="userId">The user to stamp.</param>
     * <returns>The user's unsubscribe token.</returns>
     */
    private static function ensureUnsubToken(int $userId): string
    {
        $token = bin2hex(random_bytes(24));
        Db::pdo()
            ->prepare("UPDATE users SET unsub_token = ? WHERE id = ? AND unsub_token IS NULL")
            ->execute([$token, $userId]);
        // Read back in case a concurrent request set it first.
        $stmt = Db::pdo()->prepare("SELECT unsub_token FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        return (string) ($stmt->fetchColumn() ?: $token);
    }

    /**
     * <summary>
     * Render a days_mask as a short comma-separated weekday list, e.g.
     * "Mon, Wed, Fri".
     * </summary>
     * <param name="mask">7-bit days mask, Monday = bit 0.</param>
     */
    private static function daysLabel(int $mask): string
    {
        $out = [];
        foreach (self::DAY_NAMES as $i => $label) {
            if ($mask & (1 << $i)) $out[] = $label;
        }
        return implode(', ', $out);
    }
}
