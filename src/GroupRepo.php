<?php
declare(strict_types=1);

namespace SwiftLift;

/**
 * <summary>
 * Shared journeys (groups). Recurring trips where members rotate as
 * driver by weekday. The school run is the canonical example: Monday
 * Alice drives, Tuesday Bob, etc. Passengers meet at whoever's driving
 * that day's house, then head to the shared destination (the school).
 * </summary>
 * <remarks>
 * Each group carries a destination point, weekday mask, start time and
 * an invite code. Members hold their own home pin per group, and the
 * rotation table maps day-of-week to driver. Most write paths are
 * gated by creator-only checks. Banned and tombstoned drivers are
 * filtered out of dashboard reads so a removed account does not leak
 * its name or avatar.
 * </remarks>
 */
final class GroupRepo
{
    /**
     * <summary>
     * Return every group the user belongs to with today's and tomorrow's
     * driver pre-resolved so the dashboard card can render in a single
     * round-trip.
     * </summary>
     * <param name="userId">The viewer whose memberships to fetch.</param>
     * <returns>An array of group rows including dest, schedule, today and tomorrow snapshots.</returns>
     */
    public static function listForUser(int $userId): array
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            "SELECT g.id, g.name, g.type,
                    ST_Y(g.dest_point) AS dest_lat, ST_X(g.dest_point) AS dest_lng,
                    g.dest_label,
                    TIME_FORMAT(g.start_time, '%H:%i') AS start_time,
                    g.days_mask, g.creator_id, g.invite_code, g.created_at
             FROM journey_groups g
             JOIN journey_group_members m ON m.group_id = g.id
             WHERE m.user_id = ?
             ORDER BY g.created_at DESC"
        );
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll();

        return array_map(function (array $g) use ($userId): array {
            $g['id']         = (int) $g['id'];
            $g['creator_id'] = (int) $g['creator_id'];
            $g['days_mask']  = (int) $g['days_mask'];
            $g['dest_lat']   = $g['dest_lat'] !== null ? (float) $g['dest_lat'] : null;
            $g['dest_lng']   = $g['dest_lng'] !== null ? (float) $g['dest_lng'] : null;
            $g['is_creator'] = $g['creator_id'] === $userId;

            // Today + tomorrow snapshot for the dashboard card. UTC, not
            // server-local: Db pins the MySQL session to UTC and every other
            // date calculation in the app uses gmdate/'… UTC', so a host
            // whose PHP default timezone is not UTC would otherwise show the
            // wrong day's driver either side of midnight.
            $todayDow    = (int) gmdate('N');                              // 1..7
            $tomorrowDow = (int) gmdate('N', strtotime('+1 day UTC'));
            $g['today']    = self::resolveDay($g['id'], $todayDow);
            $g['tomorrow'] = self::resolveDay($g['id'], $tomorrowDow);
            return $g;
        }, $rows);
    }

    /**
     * <summary>
     * Return the full state of one group: members with home pins, the
     * rotation map keyed by day-of-week and the group metadata, plus a
     * flag for whether the caller is the creator.
     * </summary>
     * <param name="groupId">The group to inspect.</param>
     * <param name="userId">The viewer. Must already be a member.</param>
     * <returns>A combined associative array with id, schedule, members, rotation, and is_creator.</returns>
     * <exception cref="\RuntimeException">If the group does not exist or the viewer is not a member.</exception>
     * <remarks>
     * Members whose accounts are banned, disabled or tombstoned are
     * filtered out of the member list so a removed user does not appear
     * on other people's dashboards.
     * </remarks>
     */
    public static function detail(int $groupId, int $userId): array
    {
        $pdo = Db::pdo();

        $stmt = $pdo->prepare(
            "SELECT g.id, g.name, g.type,
                    ST_Y(g.dest_point) AS dest_lat, ST_X(g.dest_point) AS dest_lng,
                    g.dest_label,
                    TIME_FORMAT(g.start_time, '%H:%i') AS start_time,
                    g.days_mask, g.creator_id, g.invite_code, g.created_at
             FROM journey_groups g
             WHERE g.id = ?"
        );
        $stmt->execute([$groupId]);
        $g = $stmt->fetch();
        if (!$g) throw new \RuntimeException('Group not found');

        // Membership check.
        $check = $pdo->prepare("SELECT 1 FROM journey_group_members WHERE group_id = ? AND user_id = ?");
        $check->execute([$groupId, $userId]);
        if (!$check->fetch()) throw new \RuntimeException('Not a member of this group');

        // Members with home pins.
        $mStmt = $pdo->prepare(
            "SELECT u.id, u.display_name, u.avatar_url,
                    ST_Y(m.home_point) AS home_lat, ST_X(m.home_point) AS home_lng,
                    m.home_label, m.joined_at
             FROM journey_group_members m
             JOIN users u ON u.id = m.user_id
             WHERE m.group_id = ? AND u.deleted_at IS NULL AND u.banned_at IS NULL AND u.disabled_at IS NULL
             ORDER BY m.joined_at"
        );
        $mStmt->execute([$groupId]);
        $members = array_map(static function (array $r): array {
            return [
                'id'           => (int) $r['id'],
                'display_name' => $r['display_name'],
                'avatar_url'   => $r['avatar_url'],
                'home_lat'     => $r['home_lat'] !== null ? (float) $r['home_lat'] : null,
                'home_lng'     => $r['home_lng'] !== null ? (float) $r['home_lng'] : null,
                'home_label'   => $r['home_label'],
                'joined_at'    => $r['joined_at'],
            ];
        }, $mStmt->fetchAll());

        // Rotation map.
        $rStmt = $pdo->prepare(
            "SELECT day_of_week, driver_user_id FROM journey_group_rotation WHERE group_id = ?"
        );
        $rStmt->execute([$groupId]);
        $rotation = [];
        foreach ($rStmt->fetchAll() as $r) {
            $rotation[(int) $r['day_of_week']] = (int) $r['driver_user_id'];
        }

        return [
            'id'         => (int) $g['id'],
            'name'       => $g['name'],
            'type'       => $g['type'],
            'dest_lat'   => $g['dest_lat'] !== null ? (float) $g['dest_lat'] : null,
            'dest_lng'   => $g['dest_lng'] !== null ? (float) $g['dest_lng'] : null,
            'dest_label' => $g['dest_label'],
            'start_time' => $g['start_time'],
            'days_mask'  => (int) $g['days_mask'],
            'creator_id' => (int) $g['creator_id'],
            'invite_code'=> $g['invite_code'],
            'created_at' => $g['created_at'],
            'is_creator' => ((int) $g['creator_id']) === $userId,
            'members'    => $members,
            'rotation'   => $rotation,
        ];
    }

    /**
     * <summary>
     * Create a group. The creator is auto-added as the only initial
     * member and is the default driver for every active weekday in
     * days_mask.
     * </summary>
     * <param name="creatorId">The user creating the group; becomes the first member.</param>
     * <param name="in">Group payload with name, optional type, start_time, days_mask, dest_lat / dest_lng / dest_label, and the creator's optional home_lat / home_lng / home_label for this group.</param>
     * <returns>The new group id.</returns>
     * <exception cref="\RuntimeException">If any required field is missing or malformed (name length, start_time format, days_mask zero or out of range).</exception>
     * <remarks>
     * Wrapped in a transaction so an INSERT failure on any step rolls
     * back the partially created group. The rotation is editable
     * afterwards via setRotation().
     * </remarks>
     */
    public static function create(int $creatorId, array $in): int
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 120) {
            throw new \RuntimeException('Name is required (max 120 chars)');
        }
        $type = ($in['type'] ?? 'school_run') === 'other' ? 'other' : 'school_run';
        if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', (string) ($in['start_time'] ?? ''))) {
            throw new \RuntimeException('Invalid start_time');
        }
        $daysMask = (int) ($in['days_mask'] ?? 0);
        if ($daysMask < 0 || ($daysMask & ~0x7F)) throw new \RuntimeException('Invalid days_mask');
        if ($daysMask === 0) throw new \RuntimeException('Pick at least one day');

        $destLat   = isset($in['dest_lat']) ? (float) $in['dest_lat'] : null;
        $destLng   = isset($in['dest_lng']) ? (float) $in['dest_lng'] : null;
        $destLabel = isset($in['dest_label']) && $in['dest_label'] !== '' ? mb_substr((string) $in['dest_label'], 0, 160) : null;

        // Per-creator home pin for this group, if supplied.
        $homeLat   = isset($in['home_lat']) ? (float) $in['home_lat'] : null;
        $homeLng   = isset($in['home_lng']) ? (float) $in['home_lng'] : null;
        $homeLabel = isset($in['home_label']) && $in['home_label'] !== '' ? mb_substr((string) $in['home_label'], 0, 160) : null;

        $code = self::randomInviteCode();

        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $sql = "INSERT INTO journey_groups
                      (name, type, dest_point, dest_label, start_time, days_mask, creator_id, invite_code)
                    VALUES (?, ?, "
                  . ($destLat !== null && $destLng !== null ? "ST_GeomFromText(?, 4326)" : "NULL")
                  . ", ?, ?, ?, ?, ?)";
            $params = [$name, $type];
            if ($destLat !== null && $destLng !== null) {
                $params[] = sprintf('POINT(%F %F)', $destLng, $destLat);
            }
            array_push($params, $destLabel, (string) $in['start_time'], $daysMask, $creatorId, $code);
            $pdo->prepare($sql)->execute($params);
            $groupId = (int) $pdo->lastInsertId();

            // Add creator as first member.
            self::insertMember($pdo, $groupId, $creatorId, $homeLat, $homeLng, $homeLabel);

            // Auto-fill rotation: creator drives every active day. Editable after.
            $rotStmt = $pdo->prepare(
                "INSERT INTO journey_group_rotation (group_id, day_of_week, driver_user_id) VALUES (?, ?, ?)"
            );
            for ($dow = 1; $dow <= 7; $dow++) {
                $bit = $dow - 1;
                if ($daysMask & (1 << $bit)) {
                    $rotStmt->execute([$groupId, $dow, $creatorId]);
                }
            }

            $pdo->commit();
            return $groupId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * <summary>
     * Delete a group. Only the creator can delete; everyone else must
     * leave instead.
     * </summary>
     * <param name="userId">The acting user. Must be the creator.</param>
     * <param name="groupId">The group to delete.</param>
     * <returns>True if a row was deleted, false if there was nothing to delete (e.g. non-creator caller or unknown id).</returns>
     */
    public static function delete(int $userId, int $groupId): bool
    {
        $stmt = Db::pdo()->prepare(
            "DELETE FROM journey_groups WHERE id = ? AND creator_id = ?"
        );
        $stmt->execute([$groupId, $userId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * <summary>
     * Replace the rotation map for a group. Creator-only. Each assigned
     * driver must be a current active member; days outside days_mask
     * are silently skipped.
     * </summary>
     * <param name="userId">The acting user. Must be the creator.</param>
     * <param name="groupId">The group whose rotation to set.</param>
     * <param name="rotation">Associative array of day_of_week (1..7) to driver user id.</param>
     * <exception cref="\RuntimeException">If the group is missing, the caller is not the creator, day-of-week is out of range, or an assigned driver is not a member.</exception>
     * <remarks>
     * Tombstoned and banned members are filtered before validation so
     * the API matches what the UI dropdown actually shows. The whole
     * replacement runs inside a transaction so a partial failure cannot
     * leave the rotation half wiped. Bad day numbers throw loudly, so a
     * malformed payload cannot silently zero out the existing rotation.
     * </remarks>
     */
    public static function setRotation(int $userId, int $groupId, array $rotation): void
    {
        $pdo = Db::pdo();
        $g = $pdo->prepare("SELECT creator_id, days_mask FROM journey_groups WHERE id = ?");
        $g->execute([$groupId]);
        $row = $g->fetch();
        if (!$row) throw new \RuntimeException('Group not found');
        if ((int) $row['creator_id'] !== $userId) throw new \RuntimeException('Only the group creator can edit the rotation');
        $daysMask = (int) $row['days_mask'];

        // Resolve current member set for validation. Filter tombstoned/banned
        // members so the API matches what the UI actually shows in the
        // dropdown, a banned user can't be assigned a driving day even by
        // direct API call.
        $mStmt = $pdo->prepare(
            "SELECT m.user_id
             FROM journey_group_members m
             JOIN users u ON u.id = m.user_id
             WHERE m.group_id = ?
               AND u.deleted_at IS NULL
               AND u.banned_at  IS NULL
               AND u.disabled_at IS NULL"
        );
        $mStmt->execute([$groupId]);
        $memberIds = array_map(static fn ($r) => (int) $r['user_id'], $mStmt->fetchAll());
        $memberSet = array_flip($memberIds);

        // Validate every entry BEFORE touching the DB. Bail out loudly on a
        // bad day_of_week so a malformed payload can't sneak past and silently
        // wipe the existing rotation via DELETE-then-zero-INSERTs. Days that
        // sit outside the group's active days_mask are still silently skipped,
        // useful for UIs that send all 7 day slots and let the backend drop
        // the inactive ones.
        $validated = [];
        foreach ($rotation as $dowRaw => $driverRaw) {
            $dow    = (int) $dowRaw;
            $driver = (int) $driverRaw;
            if ($dow < 1 || $dow > 7) {
                throw new \RuntimeException("Invalid day_of_week: $dow (must be 1-7)");
            }
            if (($daysMask & (1 << ($dow - 1))) === 0) continue;
            if (!isset($memberSet[$driver])) {
                throw new \RuntimeException("Driver $driver is not a member of this group");
            }
            $validated[$dow] = $driver;
        }

        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare("DELETE FROM journey_group_rotation WHERE group_id = ?");
            $del->execute([$groupId]);

            $ins = $pdo->prepare(
                "INSERT INTO journey_group_rotation (group_id, day_of_week, driver_user_id) VALUES (?, ?, ?)"
            );
            foreach ($validated as $dow => $driver) {
                $ins->execute([$groupId, $dow, $driver]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * <summary>
     * Update the caller's own home pin and label for a group.
     * </summary>
     * <param name="userId">The acting user. Must be a current member.</param>
     * <param name="groupId">The group on which to update membership.</param>
     * <param name="in">Optional home_lat, home_lng and home_label values. Null pair clears the pin.</param>
     * <exception cref="\RuntimeException">If the caller is not a member of the group.</exception>
     */
    public static function updateMyMembership(int $userId, int $groupId, array $in): void
    {
        $pdo = Db::pdo();
        $check = $pdo->prepare("SELECT 1 FROM journey_group_members WHERE group_id = ? AND user_id = ?");
        $check->execute([$groupId, $userId]);
        if (!$check->fetch()) throw new \RuntimeException('Not a member');

        $sets = [];
        $params = [];
        if (array_key_exists('home_lat', $in) && array_key_exists('home_lng', $in)) {
            if ($in['home_lat'] === null || $in['home_lng'] === null) {
                $sets[] = "home_point = NULL";
            } else {
                $sets[] = "home_point = ST_GeomFromText(?, 4326)";
                $params[] = sprintf('POINT(%F %F)', (float) $in['home_lng'], (float) $in['home_lat']);
            }
        }
        if (array_key_exists('home_label', $in)) {
            $sets[] = "home_label = ?";
            $params[] = $in['home_label'] === null || $in['home_label'] === '' ? null : mb_substr((string) $in['home_label'], 0, 160);
        }
        if (!$sets) return;

        $params[] = $groupId;
        $params[] = $userId;
        $pdo->prepare(
            "UPDATE journey_group_members SET " . implode(', ', $sets)
            . " WHERE group_id = ? AND user_id = ?"
        )->execute($params);
    }

    /**
     * <summary>
     * Update group metadata (name, start_time, days_mask, destination
     * point and label). Creator-only.
     * </summary>
     * <param name="userId">The acting user. Must be the creator.</param>
     * <param name="groupId">The group to update.</param>
     * <param name="in">Any subset of name, start_time, days_mask, dest_lat / dest_lng, dest_label.</param>
     * <exception cref="\RuntimeException">If the group is missing, the caller is not the creator, or any provided value fails validation.</exception>
     */
    public static function updateGroup(int $userId, int $groupId, array $in): void
    {
        $pdo = Db::pdo();
        $g = $pdo->prepare("SELECT creator_id FROM journey_groups WHERE id = ?");
        $g->execute([$groupId]);
        $row = $g->fetch();
        if (!$row) throw new \RuntimeException('Group not found');
        if ((int) $row['creator_id'] !== $userId) throw new \RuntimeException('Only the creator can edit the group');

        $sets = []; $params = [];
        if (isset($in['name'])) {
            $name = trim((string) $in['name']);
            if ($name === '' || mb_strlen($name) > 120) throw new \RuntimeException('Invalid name');
            $sets[] = "name = ?"; $params[] = $name;
        }
        if (isset($in['start_time'])) {
            if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', (string) $in['start_time'])) throw new \RuntimeException('Invalid time');
            $sets[] = "start_time = ?"; $params[] = (string) $in['start_time'];
        }
        if (isset($in['days_mask'])) {
            $dm = (int) $in['days_mask'];
            if ($dm < 0 || ($dm & ~0x7F)) throw new \RuntimeException('Invalid days_mask');
            if ($dm === 0) throw new \RuntimeException('Pick at least one day');
            $sets[] = "days_mask = ?"; $params[] = $dm;
        }
        if (array_key_exists('dest_label', $in)) {
            $sets[] = "dest_label = ?";
            $params[] = $in['dest_label'] === null || $in['dest_label'] === '' ? null : mb_substr((string) $in['dest_label'], 0, 160);
        }
        if (array_key_exists('dest_lat', $in) && array_key_exists('dest_lng', $in)) {
            if ($in['dest_lat'] === null || $in['dest_lng'] === null) {
                $sets[] = "dest_point = NULL";
            } else {
                $sets[] = "dest_point = ST_GeomFromText(?, 4326)";
                $params[] = sprintf('POINT(%F %F)', (float) $in['dest_lng'], (float) $in['dest_lat']);
            }
        }
        if (!$sets) return;

        $params[] = $groupId;
        $pdo->prepare("UPDATE journey_groups SET " . implode(', ', $sets) . " WHERE id = ?")->execute($params);
    }

    /**
     * <summary>
     * Add the caller as a member of a group resolved by invite code.
     * Idempotent: returns the same group id if the caller is already a
     * member.
     * </summary>
     * <param name="userId">The user joining the group.</param>
     * <param name="code">The invite code printed on a group's settings page.</param>
     * <returns>The id of the resolved group.</returns>
     * <exception cref="\RuntimeException">If the code is blank or unknown.</exception>
     */
    public static function joinByCode(int $userId, string $code): int
    {
        $code = trim($code);
        if ($code === '') throw new \RuntimeException('Missing invite code');

        $pdo = Db::pdo();
        $stmt = $pdo->prepare("SELECT id FROM journey_groups WHERE invite_code = ?");
        $stmt->execute([$code]);
        $row = $stmt->fetch();
        if (!$row) throw new \RuntimeException('Invite not found');
        $groupId = (int) $row['id'];

        self::insertMember($pdo, $groupId, $userId, null, null, null);
        return $groupId;
    }

    /**
     * <summary>
     * Remove the caller from a group, including any rotation slots they
     * were assigned to. Creators cannot leave; they must delete the
     * group instead.
     * </summary>
     * <param name="userId">The user leaving the group.</param>
     * <param name="groupId">The group to leave.</param>
     * <exception cref="\RuntimeException">If the group is missing, the caller is the creator, or the caller is not actually a member.</exception>
     * <remarks>
     * Both deletes run inside a transaction so a half-leave never
     * happens. The explicit membership check guards against the inner
     * DELETEs being no-ops, which used to mislead the caller into
     * thinking they had been removed from something they were not part
     * of.
     * </remarks>
     */
    public static function leave(int $userId, int $groupId): void
    {
        $pdo = Db::pdo();
        $g = $pdo->prepare("SELECT creator_id FROM journey_groups WHERE id = ?");
        $g->execute([$groupId]);
        $row = $g->fetch();
        if (!$row) throw new \RuntimeException('Group not found');
        if ((int) $row['creator_id'] === $userId) {
            throw new \RuntimeException('The creator cannot leave. Delete the group instead.');
        }

        // Guard against the DELETEs being a no-op on a non-member, which used
        // to return {"left":true} and mislead the caller into thinking they
        // were removed from something they weren't part of.
        $check = $pdo->prepare("SELECT 1 FROM journey_group_members WHERE group_id = ? AND user_id = ?");
        $check->execute([$groupId, $userId]);
        if (!$check->fetch()) throw new \RuntimeException('Not a member of this group');

        $pdo->beginTransaction();
        try {
            // Drop any rotation slots assigned to this user; creator can re-fill.
            $pdo->prepare(
                "DELETE FROM journey_group_rotation WHERE group_id = ? AND driver_user_id = ?"
            )->execute([$groupId, $userId]);
            $pdo->prepare(
                "DELETE FROM journey_group_members WHERE group_id = ? AND user_id = ?"
            )->execute([$groupId, $userId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * <summary>
     * Resolve a single weekday for a group, returning the driver's
     * identity, meeting pin and start time, or null if the group does
     * not run that day.
     * </summary>
     * <param name="groupId">The group to resolve against.</param>
     * <param name="dayOfWeek">ISO weekday, 1 (Mon) through 7 (Sun).</param>
     * <returns>Either null (group inactive on this day) or an associative array with day_of_week, start_time and a driver block. The driver block is null when the rotation slot is empty or points at a removed account.</returns>
     * <remarks>
     * Banned, disabled and tombstoned drivers are stripped at the JOIN
     * level so a removed account shows as "no driver" on the dashboard
     * card rather than leaking its name and avatar.
     * </remarks>
     */
    private static function resolveDay(int $groupId, int $dayOfWeek): ?array
    {
        $pdo = Db::pdo();
        // Filter tombstoned/banned drivers out at the JOIN level so a driver
        // who deletes their account or gets banned shows as "no driver" on
        // the dashboard card instead of leaking their name + avatar.
        $stmt = $pdo->prepare(
            "SELECT g.days_mask,
                    TIME_FORMAT(g.start_time, '%H:%i') AS start_time,
                    r.driver_user_id,
                    u.display_name,
                    u.avatar_url,
                    ST_Y(m.home_point) AS home_lat, ST_X(m.home_point) AS home_lng,
                    m.home_label
             FROM journey_groups g
             LEFT JOIN journey_group_rotation r
                ON r.group_id = g.id AND r.day_of_week = ?
             LEFT JOIN users u
                ON u.id = r.driver_user_id
                AND u.deleted_at IS NULL
                AND u.banned_at  IS NULL
                AND u.disabled_at IS NULL
             LEFT JOIN journey_group_members m       ON m.group_id = g.id AND m.user_id = r.driver_user_id
             WHERE g.id = ?"
        );
        $stmt->execute([$dayOfWeek, $groupId]);
        $r = $stmt->fetch();
        if (!$r) return null;

        $bit = $dayOfWeek - 1;
        if (((int) $r['days_mask'] & (1 << $bit)) === 0) return null;
        // If the rotation row points at a tombstoned/banned user, the LEFT
        // JOIN above strips the user fields → treat as "no driver assigned".
        if (!$r['driver_user_id'] || !$r['display_name']) {
            return ['day_of_week' => $dayOfWeek, 'start_time' => $r['start_time'], 'driver' => null];
        }

        return [
            'day_of_week' => $dayOfWeek,
            'start_time'  => $r['start_time'],
            'driver'      => [
                'id'           => (int) $r['driver_user_id'],
                'display_name' => $r['display_name'],
                'avatar_url'   => $r['avatar_url'],
                'home_lat'     => $r['home_lat'] !== null ? (float) $r['home_lat'] : null,
                'home_lng'     => $r['home_lng'] !== null ? (float) $r['home_lng'] : null,
                'home_label'   => $r['home_label'],
            ],
        ];
    }

    /**
     * <summary>
     * Add or refresh a member row for a group. Idempotent: an existing
     * membership has its home label and point coalesced so an empty
     * supplied value does not wipe the existing one.
     * </summary>
     * <param name="pdo">Active PDO handle (passed in so the caller can run this inside a transaction).</param>
     * <param name="groupId">The group to attach the member to.</param>
     * <param name="userId">The user to add or refresh.</param>
     * <param name="homeLat">Optional home latitude.</param>
     * <param name="homeLng">Optional home longitude.</param>
     * <param name="homeLabel">Optional human-readable home label.</param>
     */
    private static function insertMember(\PDO $pdo, int $groupId, int $userId, ?float $homeLat, ?float $homeLng, ?string $homeLabel): void
    {
        $hasPin = $homeLat !== null && $homeLng !== null;
        $sql = "INSERT INTO journey_group_members (group_id, user_id, home_point, home_label)
                VALUES (?, ?, "
              . ($hasPin ? "ST_GeomFromText(?, 4326)" : "NULL")
              . ", ?)
                ON DUPLICATE KEY UPDATE
                    home_label = COALESCE(VALUES(home_label), home_label),
                    home_point = COALESCE(VALUES(home_point), home_point)";
        $params = [$groupId, $userId];
        if ($hasPin) $params[] = sprintf('POINT(%F %F)', $homeLng, $homeLat);
        $params[] = $homeLabel;
        $pdo->prepare($sql)->execute($params);
    }

    /**
     * <summary>
     * Generate a 16-character URL-safe invite code.
     * </summary>
     * <returns>A randomly chosen 16-character string drawn from a human-shareable alphabet.</returns>
     * <remarks>
     * Visually ambiguous characters (0, O, 1, l, I) are excluded so the
     * code can be read aloud or copied off a screen without confusion.
     * </remarks>
     */
    private static function randomInviteCode(): string
    {
        // 16-char URL-safe code. Avoid 0/O/1/l/I to make the code human-shareable.
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 16; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }
}
