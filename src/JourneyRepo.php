<?php
declare(strict_types=1);

namespace SwiftLift;

/**
 * <summary>
 * One-to-one trips: the user's own journey rows, plus the spatial /
 * temporal match query that pairs offers and requests. Distinct from
 * GroupRepo, which handles shared, rotating school-run style trips.
 * </summary>
 * <remarks>
 * A journey owns its own start and end points, optional route polyline,
 * direction (offer or request), seats and a per-journey filter window
 * (age range, sex preference). Match queries enforce mutual filter
 * acceptance, mutual block checks, day-mask overlap, time window and a
 * spatial radius on both endpoints. Banned, disabled and deleted users
 * are filtered out so removed accounts never surface in matches.
 * </remarks>
 */
final class JourneyRepo
{
    /**
     * <summary>
     * List the user's own journeys, newest first, with seats_taken
     * pre-counted and (for request-direction journeys) the connected
     * driver's pin and time attached when an accepted lift exists.
     * </summary>
     * <param name="userId">The owner of the journeys to fetch.</param>
     * <returns>An array of hydrated journey rows.</returns>
     * <remarks>
     * The connected-driver swap keeps the passenger's own pins intact
     * in the DB while letting the UI display the driver's pickup /
     * dropoff and time ("be here at this time"). Edit and match logic
     * still uses the passenger's own values.
     * </remarks>
     */
    public static function listForUser(int $userId): array
    {
        $sql = "SELECT j.id, j.label,
                       ST_Y(j.start_point) AS start_lat, ST_X(j.start_point) AS start_lng,
                       ST_Y(j.end_point)   AS end_lat,   ST_X(j.end_point)   AS end_lng,
                       ST_AsText(j.route_line) AS route_wkt,
                       TIME_FORMAT(j.start_time, '%H:%i') AS start_time,
                       j.days_mask, j.direction, j.seats, j.radius_m, j.window_min, j.is_active,
                       j.age_min, j.age_max, j.pref_sex,
                       (SELECT COUNT(*) FROM lift_requests lr
                        WHERE lr.status = 'accepted'
                          AND (lr.from_journey_id = j.id OR lr.to_journey_id = j.id)) AS seats_taken
                FROM journeys j
                WHERE j.user_id = ?
                ORDER BY j.created_at DESC";
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute([$userId]);
        $journeys = array_map([self::class, 'hydrate'], $stmt->fetchAll());

        // Attach the driver's pickup/dropoff/time for any passenger journey
        // with an accepted connection. The original passenger pins are kept
        // intact, the UI uses the connected driver's values for *display*
        // ("be here at this time"), while edit/match logic still works off
        // the passenger's own row.
        foreach ($journeys as &$j) {
            $j['connected_driver'] = $j['direction'] === 'request'
                ? self::findConnectedDriver((int) $j['id'])
                : null;
        }
        return $journeys;
    }

    /**
     * <summary>
     * For a passenger journey, return the connected driver's journey
     * pin, time, route and identity, resolved from the most recent
     * accepted lift_request.
     * </summary>
     * <param name="passengerJourneyId">The passenger-side journey to resolve a driver for.</param>
     * <returns>An associative array describing the driver's connected journey, or null when no accepted driver connection exists.</returns>
     * <remarks>
     * Drivers whose accounts are banned, disabled or deleted are
     * filtered out so a removed driver disappears from the passenger's
     * view rather than leaking name and avatar.
     * </remarks>
     */
    private static function findConnectedDriver(int $passengerJourneyId): ?array
    {
        $sql = "
            SELECT d.id           AS driver_journey_id,
                   u.id           AS driver_user_id,
                   u.display_name AS driver_name,
                   u.avatar_url   AS driver_avatar,
                   ST_Y(d.start_point) AS start_lat, ST_X(d.start_point) AS start_lng,
                   ST_Y(d.end_point)   AS end_lat,   ST_X(d.end_point)   AS end_lng,
                   TIME_FORMAT(d.start_time, '%H:%i') AS start_time,
                   ST_AsText(d.route_line) AS route_wkt,
                   d.days_mask AS days_mask
            FROM lift_requests lr
            JOIN journeys d
                 ON d.id = IF(lr.from_journey_id = ?, lr.to_journey_id, lr.from_journey_id)
            JOIN users u ON u.id = d.user_id
            WHERE lr.status = 'accepted'
              AND d.direction = 'offer'
              AND (lr.from_journey_id = ? OR lr.to_journey_id = ?)
              AND u.deleted_at IS NULL
              AND u.banned_at  IS NULL
              AND u.disabled_at IS NULL
            ORDER BY lr.responded_at DESC
            LIMIT 1
        ";
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute([$passengerJourneyId, $passengerJourneyId, $passengerJourneyId]);
        $r = $stmt->fetch();
        if (!$r) return null;
        return [
            'driver_journey_id' => (int) $r['driver_journey_id'],
            'driver_user_id'    => (int) $r['driver_user_id'],
            'driver_name'       => $r['driver_name'],
            'driver_avatar'     => $r['driver_avatar'],
            'start_lat'         => (float) $r['start_lat'],
            'start_lng'         => (float) $r['start_lng'],
            'end_lat'           => (float) $r['end_lat'],
            'end_lng'           => (float) $r['end_lng'],
            'start_time'        => $r['start_time'],
            'route_wkt'         => $r['route_wkt'],
            'days_mask'         => (int) $r['days_mask'],
        ];
    }

    /**
     * <summary>
     * Sanity-check that an inbound route_wkt looks like a reasonable
     * LINESTRING string before letting it reach the database.
     * </summary>
     * <param name="v">Candidate WKT string, or null / empty for "no route".</param>
     * <exception cref="\RuntimeException">If the value is not a string, exceeds the size cap, or does not start with "LINESTRING (".</exception>
     * <remarks>
     * Not a SQL injection risk (parameterised), but a huge or wrong
     * shape WKT could fill the column with junk or make
     * ST_GeomFromText throw deeper in the query.
     * </remarks>
     */
    private static function validateRouteWkt($v): void
    {
        if ($v === null || $v === '') return;
        if (!is_string($v)) throw new \RuntimeException('route_wkt must be a string');
        if (strlen($v) > 100_000) throw new \RuntimeException('route_wkt is too long (max ~100KB)');
        if (!preg_match('/^\s*LINESTRING\s*\(/i', $v)) {
            throw new \RuntimeException('route_wkt must be a LINESTRING');
        }
    }

    /**
     * <summary>
     * Insert a new journey for the user, clamping seats, radius and
     * window inputs and seeding filter defaults from the user's profile.
     * </summary>
     * <param name="userId">The owning user.</param>
     * <param name="in">Payload with label, start_lat / start_lng, end_lat / end_lng, optional route_wkt, start_time, days_mask, direction, and optional seats, radius_m, window_min, age_min, age_max, pref_sex overrides.</param>
     * <returns>The new journey id.</returns>
     * <exception cref="\RuntimeException">If route_wkt is supplied but malformed.</exception>
     * <remarks>
     * The match radius is capped at 2 km. Guernsey is roughly 10 by 5
     * km, and a lift sharer realistically is not going to detour more
     * than a couple of kilometres for a pickup. Anything bigger swamps
     * the matches list with people on the other side of the island.
     * </remarks>
     */
    public static function create(int $userId, array $in): int
    {
        self::validateRouteWkt($in['route_wkt'] ?? null);
        $pdo       = Db::pdo();
        $seats     = isset($in['seats'])      ? max(1,   min(8,     (int) $in['seats']))      : 1;
        // Match radius is capped at 2 km. Guernsey is ~10×5 km, and a lift
        // sharer realistically isn't going to detour more than a couple of
        // kilometres for a pickup. Anything bigger swamps the matches list
        // with people on the other side of the island.
        $radius    = isset($in['radius_m'])   ? max(100, min(2000,  (int) $in['radius_m']))   : 500;
        $windowMin = isset($in['window_min']) ? max(5,   min(240,   (int) $in['window_min'])) : 10;

        // Age + sex filter on counterparties. If the client didn't post
        // values, fall back to the user's profile defaults so a journey
        // always carries an explicit filter the matches query can read.
        $defaults  = self::filterDefaultsForUser($userId);
        [$ageMin, $ageMax, $prefSex] = self::clampFilters($in, $defaults);

        $sql = "INSERT INTO journeys
                  (user_id, label, start_point, end_point, route_line,
                   start_time, days_mask, direction, seats, radius_m, window_min,
                   age_min, age_max, pref_sex)
                VALUES
                  (?, ?,
                   ST_GeomFromText(?, 4326),
                   ST_GeomFromText(?, 4326),
                   " . ($in['route_wkt'] ? "ST_GeomFromText(?, 4326)" : "NULL") . ",
                   ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $params = [
            $userId,
            $in['label'],
            sprintf('POINT(%F %F)', $in['start_lng'], $in['start_lat']),
            sprintf('POINT(%F %F)', $in['end_lng'],   $in['end_lat']),
        ];
        if ($in['route_wkt']) $params[] = $in['route_wkt'];
        array_push(
            $params,
            $in['start_time'], $in['days_mask'], $in['direction'],
            $seats, $radius, $windowMin,
            $ageMin, $ageMax, $prefSex
        );

        $pdo->prepare($sql)->execute($params);
        return (int) $pdo->lastInsertId();
    }

    /**
     * <summary>
     * Read the user's profile-level age and sex filter defaults so new
     * journeys start with the same preferences they set on their
     * profile.
     * </summary>
     * <param name="userId">The user whose defaults to look up.</param>
     * <returns>Associative array with age_min, age_max and pref_sex. Falls back to all-null / "any" when the user row is missing.</returns>
     */
    private static function filterDefaultsForUser(int $userId): array
    {
        $stmt = Db::pdo()->prepare("SELECT age_min, age_max, pref_sex FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        return $row ?: ['age_min' => null, 'age_max' => null, 'pref_sex' => 'any'];
    }

    /**
     * <summary>
     * Read and validate age_min, age_max and pref_sex from a payload,
     * falling back to defaults for any keys not present, and return the
     * triple ready for binding.
     * </summary>
     * <param name="in">The incoming payload. Any of age_min, age_max, pref_sex may be present.</param>
     * <param name="defaults">Fallback values typically pulled from the user's profile.</param>
     * <returns>A list of [?int ageMin, ?int ageMax, string prefSex] ready to bind.</returns>
     * <remarks>
     * Site-wide minimum age is 18, so values outside 18..120 collapse
     * to null. If ageMin and ageMax end up the wrong way round they
     * are silently swapped. pref_sex collapses to "any" unless it is
     * exactly "male" or "female".
     * </remarks>
     */
    private static function clampFilters(array $in, array $defaults): array
    {
        $clampAge = static function ($v): ?int {
            if ($v === null || $v === '' || $v === 'null') return null;
            $n = (int) $v;
            if ($n < 18 || $n > 120) return null;
            return $n;
        };
        $ageMin = array_key_exists('age_min', $in)
            ? $clampAge($in['age_min'])
            : ($defaults['age_min'] !== null ? (int) $defaults['age_min'] : null);
        $ageMax = array_key_exists('age_max', $in)
            ? $clampAge($in['age_max'])
            : ($defaults['age_max'] !== null ? (int) $defaults['age_max'] : null);
        // Swap if the user got them the wrong way round.
        if ($ageMin !== null && $ageMax !== null && $ageMin > $ageMax) {
            [$ageMin, $ageMax] = [$ageMax, $ageMin];
        }
        $prefSex = array_key_exists('pref_sex', $in)
            ? (in_array($in['pref_sex'], ['male', 'female'], true) ? $in['pref_sex'] : 'any')
            : ((string) ($defaults['pref_sex'] ?? 'any'));
        return [$ageMin, $ageMax, $prefSex];
    }

    /**
     * <summary>
     * Delete a journey owned by the caller.
     * </summary>
     * <param name="userId">The owning user. The journey must belong to this user.</param>
     * <param name="id">The journey id to delete.</param>
     * <returns>True if a row was removed, false if the caller does not own that journey.</returns>
     */
    public static function delete(int $userId, int $id): bool
    {
        $stmt = Db::pdo()->prepare("DELETE FROM journeys WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * <summary>
     * Patch a journey owned by the caller. Only fields present in the
     * payload are touched. Notifies connected counterparties when the
     * start or end pin moves.
     * </summary>
     * <param name="userId">The owning user.</param>
     * <param name="id">The journey id to patch.</param>
     * <param name="in">Any subset of label, start_time, days_mask, direction, is_active, seats, radius_m, window_min, age_min, age_max, pref_sex, start_lat / start_lng, end_lat / end_lng, route_wkt.</param>
     * <returns>True if any row was written, false if no recognised fields were supplied.</returns>
     * <exception cref="\RuntimeException">If the journey is missing, if direction would flip while active lift requests reference the row, if days_mask is out of range, or if route_wkt fails validation.</exception>
     * <remarks>
     * If start or end pin moves and the journey has accepted lift
     * connections, every connected counterparty receives a system
     * message in their chat thread. Pin-move detection uses a 1e-5
     * degree threshold (about 1.1 metres) so float noise from a
     * re-save does not trigger spurious notifications. Direction flips
     * are refused while active lift requests exist, otherwise the
     * accepted pair would end up with both sides on the same direction
     * and silently break findConnectedDriver and seats_taken counts.
     * </remarks>
     */
    public static function update(int $userId, int $id, array $in): bool
    {
        // Confirm ownership AND fetch the existing pins so we can tell
        // whether they actually changed.
        $check = Db::pdo()->prepare(
            "SELECT id, label, direction,
                    ST_Y(start_point) AS start_lat, ST_X(start_point) AS start_lng,
                    ST_Y(end_point)   AS end_lat,   ST_X(end_point)   AS end_lng
             FROM journeys WHERE id = ? AND user_id = ?"
        );
        $check->execute([$id, $userId]);
        $existing = $check->fetch();
        if (!$existing) throw new \RuntimeException('Journey not found');

        $sets = [];
        $params = [];

        if (isset($in['label']))     { $sets[] = "label = ?";      $params[] = (string) $in['label']; }
        if (isset($in['start_time'])) { $sets[] = "start_time = ?"; $params[] = (string) $in['start_time']; }
        if (isset($in['days_mask'])) {
            $dm = (int) $in['days_mask'];
            if ($dm < 0 || ($dm & ~0x7F)) throw new \RuntimeException('Invalid days_mask');
            $sets[] = "days_mask = ?";  $params[] = $dm;
        }
        if (isset($in['direction']) && in_array($in['direction'], ['offer','request'], true)) {
            // Refuse to flip direction while the journey is part of an
            // active lift_request pair. Otherwise the accepted pair ends
            // up with two journeys on the same side (both 'offer' or
            // both 'request'), which silently breaks findConnectedDriver
            // and the seats_taken count.
            if ($in['direction'] !== $existing['direction']) {
                $conn = Db::pdo()->prepare(
                    "SELECT COUNT(*) FROM lift_requests
                     WHERE (from_journey_id = ? OR to_journey_id = ?)
                       AND status IN ('pending','accepted')"
                );
                $conn->execute([$id, $id]);
                if ((int) $conn->fetchColumn() > 0) {
                    throw new \RuntimeException(
                        "Can't change driver/passenger while this journey has active lift requests. " .
                        "Decline or cancel them first, or create a new journey going the other way."
                    );
                }
            }
            $sets[] = "direction = ?";  $params[] = $in['direction'];
        }
        if (isset($in['is_active'])) { $sets[] = "is_active = ?";  $params[] = $in['is_active'] ? 1 : 0; }
        if (isset($in['seats']))     { $sets[] = "seats = ?";      $params[] = max(1, min(8, (int) $in['seats'])); }
        if (isset($in['radius_m']))  { $sets[] = "radius_m = ?";   $params[] = max(100, min(2000,  (int) $in['radius_m'])); }
        if (isset($in['window_min'])){ $sets[] = "window_min = ?"; $params[] = max(5, min(240, (int) $in['window_min'])); }

        // Per-journey age + sex filter on counterparties. Only fields the
        // client actually posted are touched, leaves the rest alone.
        if (array_key_exists('age_min', $in)) {
            $v = $in['age_min'];
            $sets[] = "age_min = ?";
            $params[] = ($v === null || $v === '') ? null
                      : (((int) $v >= 18 && (int) $v <= 120) ? (int) $v : null);
        }
        if (array_key_exists('age_max', $in)) {
            $v = $in['age_max'];
            $sets[] = "age_max = ?";
            $params[] = ($v === null || $v === '') ? null
                      : (((int) $v >= 18 && (int) $v <= 120) ? (int) $v : null);
        }
        if (array_key_exists('pref_sex', $in)) {
            $sets[] = "pref_sex = ?";
            $params[] = in_array($in['pref_sex'], ['male', 'female'], true) ? $in['pref_sex'] : 'any';
        }

        if (isset($in['start_lat'], $in['start_lng'])) {
            $sets[] = "start_point = ST_GeomFromText(?, 4326)";
            $params[] = sprintf('POINT(%F %F)', (float) $in['start_lng'], (float) $in['start_lat']);
        }
        if (isset($in['end_lat'], $in['end_lng'])) {
            $sets[] = "end_point = ST_GeomFromText(?, 4326)";
            $params[] = sprintf('POINT(%F %F)', (float) $in['end_lng'], (float) $in['end_lat']);
        }
        if (array_key_exists('route_wkt', $in)) {
            self::validateRouteWkt($in['route_wkt']);
            if ($in['route_wkt'] === null || $in['route_wkt'] === '') {
                $sets[] = "route_line = NULL";
            } else {
                $sets[] = "route_line = ST_GeomFromText(?, 4326)";
                $params[] = (string) $in['route_wkt'];
            }
        }

        if (!$sets) return false;

        // Did the start or end pin actually move? Pins are stored as
        // POINT(lng lat) at ~7-decimal precision; any change of more than a
        // few centimetres counts as a real move (anything tinier is just
        // float noise from a re-save). 1e-5 degrees ≈ 1.1 m at this
        // latitude, well below "would the passenger care?".
        $startMoved = isset($in['start_lat'], $in['start_lng']) && (
            abs(((float) $in['start_lat']) - ((float) $existing['start_lat'])) > 1e-5 ||
            abs(((float) $in['start_lng']) - ((float) $existing['start_lng'])) > 1e-5
        );
        $endMoved = isset($in['end_lat'], $in['end_lng']) && (
            abs(((float) $in['end_lat']) - ((float) $existing['end_lat'])) > 1e-5 ||
            abs(((float) $in['end_lng']) - ((float) $existing['end_lng'])) > 1e-5
        );

        $params[] = $id;
        $params[] = $userId;
        $sql = "UPDATE journeys SET " . implode(', ', $sets) . " WHERE id = ? AND user_id = ?";
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute($params);
        $changed = $stmt->rowCount() > 0;

        if ($changed && ($startMoved || $endMoved)) {
            self::notifyConnectionsOfPinChange($id, $userId, (string) $existing['label'], $startMoved, $endMoved);
        }
        return $changed;
    }

    /**
     * <summary>
     * Send a system message to every accepted counterparty on this
     * journey telling them to re-check the pickup or dropoff after a
     * pin move.
     * </summary>
     * <param name="journeyId">The journey whose pin changed.</param>
     * <param name="actorUserId">The user who triggered the change (used as the sender id on the system message).</param>
     * <param name="journeyLabel">The journey's display label, included in the message body.</param>
     * <param name="startMoved">True when the start pin moved.</param>
     * <param name="endMoved">True when the end pin moved.</param>
     * <remarks>
     * The chat thread is the natural place: the passenger already
     * opens it to talk to the driver, so the notice surfaces without
     * a separate inbox.
     * </remarks>
     */
    private static function notifyConnectionsOfPinChange(int $journeyId, int $actorUserId, string $journeyLabel, bool $startMoved, bool $endMoved): void
    {
        $stmt = Db::pdo()->prepare(
            "SELECT lr.id AS lift_request_id
             FROM lift_requests lr
             WHERE lr.status = 'accepted'
               AND (lr.from_journey_id = ? OR lr.to_journey_id = ?)"
        );
        $stmt->execute([$journeyId, $journeyId]);
        $rows = $stmt->fetchAll();
        if (!$rows) return;

        $what = $startMoved && $endMoved ? 'pickup and dropoff'
              : ($startMoved             ? 'pickup'
              :                            'dropoff');
        $body = "Pins for \"$journeyLabel\" changed, the $what location moved. Please re-check the map and let each other know if it still works.";
        foreach ($rows as $r) {
            MessageRepo::sendSystem((int) $r['lift_request_id'], $actorUserId, $body);
        }
    }

    /**
     * <summary>
     * Find journeys that match the given journey on day-mask overlap,
     * start-time window, mutual filter acceptance and spatial
     * proximity of both endpoints.
     * </summary>
     * <param name="journeyId">The journey to match against.</param>
     * <param name="radiusMeters">Maximum great-circle distance, in metres, allowed between start points and between end points. Defaults to 500.</param>
     * <param name="timeWindowMin">Symmetric tolerance in minutes around the start_time. Defaults to 10.</param>
     * <returns>Up to 50 candidate match rows sorted by combined endpoint distance ascending.</returns>
     * <remarks>
     * Mutual age and sex filters are applied per journey: each side's
     * age_min, age_max and pref_sex are checked against the other
     * user. Tombstoned, banned and disabled users are filtered out
     * entirely. Blocks in either direction also exclude the row. The
     * time window uses the smaller of |a - b| and 86400 - |a - b| so
     * 23:55 and 00:10 read as 15 minutes apart, not 23h45m.
     * </remarks>
     */
    public static function findMatches(int $journeyId, int $radiusMeters = 500, int $timeWindowMin = 10): array
    {
        // Mutual age + sex filter is per-journey: each row's age_min/max +
        // pref_sex are checked against the other user. New journeys inherit
        // the user's profile defaults at create-time, then can be tweaked
        // per journey (e.g. school-run journey only women drivers, work
        // commute open to anyone).
        $sql = "
            SELECT j.id,
                   u.display_name, u.avatar_url, u.age,
                   u.car_make, u.car_colour, u.pref_smoking, u.pref_pets,
                   u.pref_music, u.detour_m,
                   j.label, j.seats,
                   (SELECT COUNT(*) FROM lift_requests lr
                    WHERE lr.status = 'accepted'
                      AND (lr.from_journey_id = j.id OR lr.to_journey_id = j.id)) AS seats_taken,
                   ST_Y(j.start_point) AS start_lat, ST_X(j.start_point) AS start_lng,
                   ST_Y(j.end_point)   AS end_lat,   ST_X(j.end_point)   AS end_lng,
                   ST_AsText(j.route_line) AS route_wkt,
                   TIME_FORMAT(j.start_time, '%H:%i') AS start_time,
                   j.days_mask, j.direction,
                   ST_Distance_Sphere(j.start_point, me.start_point) AS start_dist_m,
                   ST_Distance_Sphere(j.end_point,   me.end_point)   AS end_dist_m
            FROM journeys me
            JOIN users    u_me ON u_me.id = me.user_id
            JOIN journeys j    ON j.id    != me.id
            JOIN users    u    ON u.id    = j.user_id
            WHERE me.id = :mid
              AND j.is_active = 1
              AND j.user_id != me.user_id
              AND u.is_away  = 0
              -- Tombstoned / banned users vanish from match results.
              AND u.deleted_at IS NULL
              AND u.banned_at  IS NULL
              AND u.disabled_at IS NULL
              -- Only show actionable matches: a driver pairs with a passenger,
              -- not driver-to-driver or passenger-to-passenger.
              AND j.direction != me.direction
              -- Hide users I've blocked, or who have blocked me.
              AND NOT EXISTS (
                  SELECT 1 FROM user_blocks ub
                  WHERE (ub.blocker_id = me.user_id AND ub.blocked_id = j.user_id)
                     OR (ub.blocker_id = j.user_id  AND ub.blocked_id = me.user_id)
              )
              AND (j.days_mask & me.days_mask) > 0
              -- Time window is the smaller of |a - b| and 86400 - |a - b|
              -- so 23:55 and 00:10 are 15 min apart, not 23h45m.
              AND LEAST(
                    ABS(TIME_TO_SEC(TIMEDIFF(j.start_time, me.start_time))),
                    86400 - ABS(TIME_TO_SEC(TIMEDIFF(j.start_time, me.start_time)))
                  ) <= :winsec
              AND ST_Distance_Sphere(j.start_point, me.start_point) <= :radius_start
              AND ST_Distance_Sphere(j.end_point,   me.end_point)   <= :radius_end
              -- MY journey's filter must accept them (age + sex)
              AND (me.age_min  IS NULL OR (u.age    IS NOT NULL AND u.age    >= me.age_min))
              AND (me.age_max  IS NULL OR (u.age    IS NOT NULL AND u.age    <= me.age_max))
              -- pref_sex = 'any' (default) matches everyone; otherwise the
              -- other user's `sex` must equal the preference, which also
              -- excludes anyone with sex = NULL (= 'prefer not to say').
              AND (me.pref_sex = 'any' OR u.sex    = me.pref_sex)
              -- THEIR journey's filter must accept me (age + sex)
              AND (j.age_min   IS NULL OR (u_me.age IS NOT NULL AND u_me.age >= j.age_min))
              AND (j.age_max   IS NULL OR (u_me.age IS NOT NULL AND u_me.age <= j.age_max))
              AND (j.pref_sex  = 'any' OR u_me.sex = j.pref_sex)
            ORDER BY (start_dist_m + end_dist_m) ASC
            LIMIT 50";
        $stmt = Db::pdo()->prepare($sql);
        $stmt->execute([
            ':mid'          => $journeyId,
            ':winsec'       => $timeWindowMin * 60,
            ':radius_start' => $radiusMeters,
            ':radius_end'   => $radiusMeters,
        ]);
        return $stmt->fetchAll();
    }

    /**
     * <summary>
     * Normalise a raw journey row into typed, JSON-friendly values for
     * the API. Casts lat/lng to float, days_mask and seats to int, and
     * is_active to bool.
     * </summary>
     * <param name="row">A raw associative row as returned by PDO.</param>
     * <returns>The hydrated row.</returns>
     */
    private static function hydrate(array $row): array
    {
        $row['start_lat']  = (float) $row['start_lat'];
        $row['start_lng']  = (float) $row['start_lng'];
        $row['end_lat']    = (float) $row['end_lat'];
        $row['end_lng']    = (float) $row['end_lng'];
        $row['days_mask']  = (int)   $row['days_mask'];
        $row['is_active']  = (bool)  $row['is_active'];
        $row['id']         = (int)   $row['id'];
        if (array_key_exists('seats', $row))       $row['seats']       = (int) $row['seats'];
        if (array_key_exists('seats_taken', $row)) $row['seats_taken'] = (int) $row['seats_taken'];
        if (array_key_exists('radius_m', $row))    $row['radius_m']    = (int) $row['radius_m'];
        if (array_key_exists('window_min', $row))  $row['window_min']  = (int) $row['window_min'];
        if (array_key_exists('age_min', $row))     $row['age_min']     = $row['age_min'] === null ? null : (int) $row['age_min'];
        if (array_key_exists('age_max', $row))     $row['age_max']     = $row['age_max'] === null ? null : (int) $row['age_max'];
        if (array_key_exists('pref_sex', $row))    $row['pref_sex']    = (string) ($row['pref_sex'] ?? 'any');
        return $row;
    }

    /**
     * <summary>
     * Look up the owning user id, search radius and time window for a
     * journey, used by the matches endpoint to seed its query.
     * </summary>
     * <param name="journeyId">The journey id.</param>
     * <returns>An associative array with user_id, radius_m, window_min and direction, or null when the journey does not exist.</returns>
     */
    public static function findSearchDefaults(int $journeyId): ?array
    {
        $stmt = Db::pdo()->prepare("SELECT user_id, radius_m, window_min, direction FROM journeys WHERE id = ?");
        $stmt->execute([$journeyId]);
        $row = $stmt->fetch();
        return $row ? [
            'user_id'    => (int) $row['user_id'],
            'radius_m'   => (int) $row['radius_m'],
            'window_min' => (int) $row['window_min'],
            'direction'  => (string) $row['direction'],
        ] : null;
    }
}
