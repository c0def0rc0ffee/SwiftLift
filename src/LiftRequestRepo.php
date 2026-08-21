<?php
declare(strict_types=1);

namespace SwiftLift;

/**
 * <summary>
 * The driver / passenger pairing layer: lift requests between two
 * journeys. Handles creation with daily quotas and pair-validity
 * checks, status transitions (accept, decline, cancel), and listing
 * for the inbox / outbox UI.
 * </summary>
 * <remarks>
 * A lift request points at two journeys: the sender's own journey
 * (from) and the counterparty's (to). Exactly one side must be an
 * offer and the other a request, so driver-to-driver and
 * passenger-to-passenger pairings are rejected at create time.
 * Accepted requests carry the chat thread used by MessageRepo. Seat
 * capacity is enforced atomically at accept time so two concurrent
 * accepts cannot oversell the last seat.
 * </remarks>
 */
final class LiftRequestRepo
{
    /**
     * <summary>
     * Maximum character length of the optional message body, matching
     * the in-app message cap so the compose box behaves identically.
     * </summary>
     */
    public const MAX_MESSAGE = 280;
    /**
     * <summary>
     * How many new lift requests a single user can fire off in a
     * rolling 24-hour window. Reviving an existing row counts too.
     * </summary>
     */
    public const DAILY_LIMIT = 20;

    /**
     * <summary>
     * How many new lift requests the user has left in their rolling
     * 24-hour window.
     * </summary>
     * <param name="userId">The user whose quota to check.</param>
     * <returns>The number of new requests remaining, clamped at zero.</returns>
     * <remarks>
     * Counts every request *sent* in the window, whether it created a
     * new row or revived an existing one after a decline or cancel. The
     * revive path resets created_at precisely so the row reads as freshly
     * sent, and it counts against the quota for the same reason: re-asking
     * somebody who already declined is exactly the behaviour the daily
     * limit exists to bound.
     * </remarks>
     */
    public static function dailyRemaining(int $userId): int
    {
        $stmt = Db::pdo()->prepare(
            "SELECT COUNT(*) FROM lift_requests r
             JOIN journeys fj ON fj.id = r.from_journey_id
             WHERE fj.user_id = ?
               AND r.created_at >= NOW() - INTERVAL 1 DAY"
        );
        $stmt->execute([$userId]);
        return max(0, self::DAILY_LIMIT - (int) $stmt->fetchColumn());
    }

    /**
     * <summary>
     * Create a new pending lift request between two journeys, or revive
     * an existing closed pair so the user can re-ask after a decline.
     * </summary>
     * <param name="userId">The acting user. Must own the from journey.</param>
     * <param name="fromJourneyId">The caller's own journey on one side of the pair.</param>
     * <param name="toJourneyId">The counterparty's journey on the other side.</param>
     * <param name="message">Optional message to attach. Clamped to MAX_MESSAGE characters.</param>
     * <returns>The new (or revived / re-used) lift_requests row id.</returns>
     * <exception cref="\RuntimeException">If either journey is missing, the caller is connecting to themselves, the pair is not a valid offer / request combination, or the daily quota is exhausted.</exception>
     * <remarks>
     * The quota check and insert run inside a transaction with a row
     * lock on the user, so two concurrent POSTs from the same user
     * cannot both pass the count check and oversell DAILY_LIMIT. If a
     * row already exists for this pair and is pending or accepted, the
     * existing id is returned; if it is in a terminal state (declined
     * or cancelled), the row is revived as pending so the user can try
     * again without the unique key blocking them.
     * </remarks>
     */
    public static function create(int $userId, int $fromJourneyId, int $toJourneyId, ?string $message): int
    {
        $pdo = Db::pdo();

        // Validate: from_journey must belong to me, to_journey to someone else,
        // and the pair must be exactly one offer + one request (no driver→driver
        // or passenger→passenger connections).
        $stmt = $pdo->prepare(
            "SELECT
                fj.user_id   AS from_owner, fj.direction AS from_dir,
                tj.user_id   AS to_owner,   tj.direction AS to_dir
             FROM journeys fj, journeys tj
             WHERE fj.id = ? AND tj.id = ?"
        );
        $stmt->execute([$fromJourneyId, $toJourneyId]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new \RuntimeException('Journey not found');
        }
        if ((int) $row['from_owner'] !== $userId) {
            throw new \RuntimeException('You can only request from your own journey');
        }
        if ((int) $row['to_owner'] === $userId) {
            throw new \RuntimeException('You cannot connect to yourself');
        }
        // Only the person seeking a lift initiates. The "from" side (the
        // caller's own journey) must be a request, and the "to" side an
        // offer. Drivers don't send requests, they receive them and accept
        // or decline. This is the server-side enforcement of the
        // passengers-initiate model; the UI hides the driver-side compose,
        // but a hand-crafted POST is rejected here too.
        if ($row['from_dir'] !== 'request' || $row['to_dir'] !== 'offer') {
            throw new \RuntimeException(
                'Only the person looking for a lift can send a request. Drivers receive requests and accept or decline them.'
            );
        }

        // Quota check + insert must be atomic. Without a transaction, two
        // concurrent POSTs from the same user could both pass the
        // dailyRemaining check (returns "you have 1 left") and each insert
        // a row, exceeding DAILY_LIMIT. SELECT FOR UPDATE on the user
        // row serialises concurrent create() calls from the same user, so
        // the count read inside the transaction sees a consistent value.
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare("SELECT id FROM users WHERE id = ? FOR UPDATE");
            $lock->execute([$userId]);

            if (self::dailyRemaining($userId) <= 0) {
                throw new \RuntimeException(
                    'You\'ve sent today\'s limit of ' . self::DAILY_LIMIT . ' lift requests. Try again tomorrow.'
                );
            }

            // Re-create or revive an existing row for this pair so the
            // unique key doesn't block follow-up requests after a
            // decline/cancel.
            $existing = $pdo->prepare("SELECT id, status FROM lift_requests WHERE from_journey_id = ? AND to_journey_id = ?");
            $existing->execute([$fromJourneyId, $toJourneyId]);
            $exRow = $existing->fetch();

            if ($exRow) {
                if (in_array($exRow['status'], ['pending','accepted'], true)) {
                    $pdo->commit();
                    return (int) $exRow['id'];
                }
                $upd = $pdo->prepare(
                    "UPDATE lift_requests
                     SET status='pending', message=?, created_at=CURRENT_TIMESTAMP, responded_at=NULL
                     WHERE id=?"
                );
                $upd->execute([self::cleanMessage($message), (int) $exRow['id']]);
                $pdo->commit();
                return (int) $exRow['id'];
            }

            $ins = $pdo->prepare(
                "INSERT INTO lift_requests (from_journey_id, to_journey_id, message)
                 VALUES (?, ?, ?)"
            );
            $ins->execute([$fromJourneyId, $toJourneyId, self::cleanMessage($message)]);
            $newId = (int) $pdo->lastInsertId();
            $pdo->commit();
            return $newId;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * <summary>
     * List every lift request where the given user is on either side,
     * joining enough counterparty info that the inbox UI can render
     * rows directly. Pending requests sort first, then newest first
     * within each bucket.
     * </summary>
     * <param name="userId">The viewer.</param>
     * <returns>Up to 200 hydrated rows. Banned, disabled and deleted counterparties have identifying fields masked to "(deleted user)" with null avatar.</returns>
     * <remarks>
     * Counterparty masking happens at the SQL level. Deleted users
     * already have display_name scrubbed by UserRepo::delete(), but
     * banned users keep their real data and would otherwise leak
     * through this query.
     * </remarks>
     */
    public static function listForUser(int $userId): array
    {
        // Banned and deleted users get their identifying fields masked at
        // the SQL level so the sidebar never shows their real name or
        // avatar, deleted users already have display_name scrubbed to
        // "(deleted user)" by UserRepo::delete(), but banned users keep
        // their real data and would otherwise leak through this query.
        $sql = "
            SELECT r.id, r.status, r.message, r.created_at, r.responded_at,
                   r.from_journey_id, r.to_journey_id,
                   fj.user_id AS from_user_id,
                   tj.user_id AS to_user_id,
                   fj.label   AS from_label,
                   tj.label   AS to_label,
                   TIME_FORMAT(fj.start_time, '%H:%i') AS from_time,
                   TIME_FORMAT(tj.start_time, '%H:%i') AS to_time,
                   fj.days_mask AS from_days,
                   tj.days_mask AS to_days,
                   u_other.id           AS other_id,
                   CASE WHEN u_other.banned_at IS NULL AND u_other.disabled_at IS NULL AND u_other.deleted_at IS NULL
                        THEN u_other.display_name ELSE '(deleted user)' END AS other_name,
                   CASE WHEN u_other.banned_at IS NULL AND u_other.disabled_at IS NULL AND u_other.deleted_at IS NULL
                        THEN u_other.avatar_url   ELSE NULL                END AS other_avatar,
                   CASE WHEN u_other.banned_at IS NULL AND u_other.disabled_at IS NULL AND u_other.deleted_at IS NULL
                        THEN u_other.age          ELSE NULL                END AS other_age,
                   CASE WHEN u_other.banned_at IS NULL AND u_other.disabled_at IS NULL AND u_other.deleted_at IS NULL
                        THEN u_other.bio          ELSE NULL                END AS other_bio,
                   CASE WHEN u_other.banned_at IS NULL AND u_other.disabled_at IS NULL AND u_other.deleted_at IS NULL
                        THEN u_other.car_make     ELSE NULL                END AS other_car_make,
                   CASE WHEN u_other.banned_at IS NULL AND u_other.disabled_at IS NULL AND u_other.deleted_at IS NULL
                        THEN u_other.car_colour   ELSE NULL                END AS other_car_colour,
                   CASE WHEN u_other.banned_at IS NULL AND u_other.disabled_at IS NULL AND u_other.deleted_at IS NULL
                        THEN u_other.pref_smoking ELSE NULL                END AS other_pref_smoking,
                   CASE WHEN u_other.banned_at IS NULL AND u_other.disabled_at IS NULL AND u_other.deleted_at IS NULL
                        THEN u_other.pref_pets    ELSE NULL                END AS other_pref_pets,
                   CASE WHEN u_other.banned_at IS NULL AND u_other.disabled_at IS NULL AND u_other.deleted_at IS NULL
                        THEN u_other.pref_music   ELSE NULL                END AS other_pref_music,
                   CASE WHEN u_other.banned_at IS NULL AND u_other.disabled_at IS NULL AND u_other.deleted_at IS NULL
                        THEN u_other.detour_m     ELSE 0                   END AS other_detour_m
            FROM lift_requests r
            JOIN journeys fj    ON fj.id = r.from_journey_id
            JOIN journeys tj    ON tj.id = r.to_journey_id
            JOIN users    u_other ON u_other.id = CASE WHEN fj.user_id = :uid_join THEN tj.user_id ELSE fj.user_id END
            WHERE fj.user_id = :uid_from OR tj.user_id = :uid_to
            ORDER BY (r.status = 'pending') DESC, r.created_at DESC
            LIMIT 200";
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute([
            ':uid_join' => $userId,
            ':uid_from' => $userId,
            ':uid_to'   => $userId,
        ]);
        return array_map(fn(array $row) => self::hydrate($row, $userId), $stmt->fetchAll());
    }

    /**
     * <summary>
     * Update a lift request's status with permission checks. The
     * recipient can accept or decline a pending request; either side
     * can cancel a pending or accepted one.
     * </summary>
     * <param name="userId">The acting user.</param>
     * <param name="requestId">The lift_requests row to mutate.</param>
     * <param name="newStatus">One of "accepted", "declined" or "cancelled".</param>
     * <returns>The hydrated row as returned by listForUser, ready for the API response.</returns>
     * <exception cref="\RuntimeException">If the status string is unknown, the user is not part of the request, the action is not allowed in the current state, or accepting would oversell seats on the driver's journey.</exception>
     * <remarks>
     * Seat capacity always lives on the offer (driver) side of the
     * pair, regardless of which side initiated the request. The
     * acceptance path takes a row lock on the offer journey and
     * counts other accepted requests inside the transaction so two
     * concurrent accepts cannot both pass the count check and oversell
     * the last seat.
     * </remarks>
     */
    public static function setStatus(int $userId, int $requestId, string $newStatus): array
    {
        if (!in_array($newStatus, ['accepted', 'declined', 'cancelled'], true)) {
            throw new \RuntimeException('Invalid status');
        }

        $pdo  = Db::pdo();
        $stmt = $pdo->prepare(
            "SELECT r.id, r.status, fj.user_id AS from_user_id, tj.user_id AS to_user_id
             FROM lift_requests r
             JOIN journeys fj ON fj.id = r.from_journey_id
             JOIN journeys tj ON tj.id = r.to_journey_id
             WHERE r.id = ?"
        );
        $stmt->execute([$requestId]);
        $row = $stmt->fetch();
        if (!$row) throw new \RuntimeException('Request not found');

        $isSender    = (int) $row['from_user_id'] === $userId;
        $isRecipient = (int) $row['to_user_id']   === $userId;
        if (!$isSender && !$isRecipient) throw new \RuntimeException('Not your request');

        $current = $row['status'];

        if (in_array($newStatus, ['accepted', 'declined'], true)) {
            if (!$isRecipient)        throw new \RuntimeException('Only the recipient can accept or decline');
            if ($current !== 'pending') throw new \RuntimeException('Request is no longer pending');
        }

        if ($newStatus === 'cancelled') {
            if (!in_array($current, ['pending', 'accepted'], true)) {
                throw new \RuntimeException('Cannot cancel a request that is already finalised');
            }
        }

        // Capacity guard: capacity always lives on the OFFER (driver) side of
        // the pair, regardless of which side initiated. The check + update
        // must be atomic, without a row lock, two concurrent accepts could
        // both pass the count check and oversell the last seat.
        if ($newStatus === 'accepted') {
            $pdo->beginTransaction();
            try {
                $offer = $pdo->prepare(
                    "SELECT j_offer.id AS offer_id, j_offer.seats AS seats
                     FROM lift_requests r
                     JOIN journeys fj ON fj.id = r.from_journey_id
                     JOIN journeys tj ON tj.id = r.to_journey_id
                     JOIN journeys j_offer
                          ON j_offer.id = (CASE WHEN fj.direction = 'offer' THEN fj.id ELSE tj.id END)
                     WHERE r.id = ?
                     FOR UPDATE"
                );
                $offer->execute([$requestId]);
                $offerRow = $offer->fetch();
                if ($offerRow) {
                    $taken = $pdo->prepare(
                        "SELECT COUNT(*) FROM lift_requests
                         WHERE status = 'accepted'
                           AND (from_journey_id = ? OR to_journey_id = ?)"
                    );
                    $taken->execute([(int) $offerRow['offer_id'], (int) $offerRow['offer_id']]);
                    if ((int) $taken->fetchColumn() >= (int) $offerRow['seats']) {
                        $pdo->rollBack();
                        throw new \RuntimeException('All seats on this journey are already taken');
                    }
                }
                $upd = $pdo->prepare("UPDATE lift_requests SET status = ?, responded_at = CURRENT_TIMESTAMP WHERE id = ?");
                $upd->execute([$newStatus, $requestId]);
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
        } else {
            $upd = $pdo->prepare("UPDATE lift_requests SET status = ?, responded_at = CURRENT_TIMESTAMP WHERE id = ?");
            $upd->execute([$newStatus, $requestId]);
        }

        $listed = self::listForUser($userId);
        foreach ($listed as $r) if ($r['id'] === $requestId) return $r;
        throw new \RuntimeException('Updated row not found');
    }

    /**
     * <summary>
     * Trim a message body, clamp it to MAX_MESSAGE, and turn empty
     * strings into null so the column reads cleanly.
     * </summary>
     * <param name="msg">The raw message from the payload, or null.</param>
     * <returns>A cleaned string or null when empty.</returns>
     */
    private static function cleanMessage(?string $msg): ?string
    {
        if ($msg === null) return null;
        $trim = trim($msg);
        if ($trim === '') return null;
        return mb_substr($trim, 0, self::MAX_MESSAGE);
    }

    /**
     * <summary>
     * Shape a raw joined row into the inbox API response, with the
     * "direction" flag set relative to the viewer.
     * </summary>
     * <param name="row">A joined row from listForUser's SELECT.</param>
     * <param name="viewerId">The user whose perspective the rendered row is for.</param>
     * <returns>An associative array describing the request, the viewer's journey side, the counterparty's journey side, and the counterparty's profile snapshot.</returns>
     */
    private static function hydrate(array $row, int $viewerId): array
    {
        $direction = ((int) $row['from_user_id']) === $viewerId ? 'sent' : 'received';
        return [
            'id'            => (int) $row['id'],
            'status'        => (string) $row['status'],
            'direction'     => $direction,
            'message'       => $row['message'],
            'created_at'    => $row['created_at'],
            'responded_at'  => $row['responded_at'],
            'my_journey'    => [
                'id'    => (int) ($direction === 'sent' ? $row['from_journey_id'] : $row['to_journey_id']),
                'label' => $direction === 'sent' ? $row['from_label'] : $row['to_label'],
            ],
            'their_journey' => [
                'id'         => (int) ($direction === 'sent' ? $row['to_journey_id'] : $row['from_journey_id']),
                'label'      => $direction === 'sent' ? $row['to_label']  : $row['from_label'],
                'start_time' => $direction === 'sent' ? $row['to_time']   : $row['from_time'],
                'days_mask'  => (int) ($direction === 'sent' ? $row['to_days'] : $row['from_days']),
            ],
            'other_user' => [
                'id'           => (int) $row['other_id'],
                'display_name' => $row['other_name'],
                'avatar_url'   => $row['other_avatar'],
                'age'          => $row['other_age'] === null ? null : (int) $row['other_age'],
                'bio'          => $row['other_bio'],
                'car_make'     => $row['other_car_make'],
                'car_colour'   => $row['other_car_colour'],
                'pref_smoking' => $row['other_pref_smoking'],
                'pref_pets'    => $row['other_pref_pets'],
                'pref_music'   => $row['other_pref_music'],
                'detour_m'     => (int) ($row['other_detour_m'] ?? 0),
            ],
        ];
    }
}
