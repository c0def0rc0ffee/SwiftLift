<?php
declare(strict_types=1);

namespace SwiftLift;

/**
 * <summary>
 * User-to-user blocks and abuse reports. Blocks hide matches in both
 * directions and cancel any active lift requests between the two
 * parties; reports are admin-facing only.
 * </summary>
 * <remarks>
 * Block records use ON DUPLICATE KEY UPDATE so re-blocking the same
 * person just refreshes the reason and timestamp. Self-blocks and
 * self-reports are rejected. Reason strings are length-clamped at the
 * repository level to keep DB rows tidy without trusting the client.
 * </remarks>
 */
final class BlockRepo
{
    /**
     * <summary>
     * Add or refresh a block from one user against another and cancel any
     * pending or accepted lift requests between them.
     * </summary>
     * <param name="blockerId">The user doing the blocking.</param>
     * <param name="blockedId">The user being blocked.</param>
     * <param name="reason">Optional free-text reason. Clamped to 280 characters.</param>
     * <exception cref="\RuntimeException">If the user tries to block themselves.</exception>
     * <remarks>
     * The cancellation step is what actually severs the connection.
     * Without it, matches stop showing but the existing accepted lift
     * request would keep the messaging thread alive. Both writes run in
     * one transaction so a partial block is not possible.
     * </remarks>
     */
    public static function block(int $blockerId, int $blockedId, ?string $reason = null): void
    {
        if ($blockerId === $blockedId) throw new \RuntimeException('You cannot block yourself');

        $pdo = Db::pdo();

        // Both statements or neither. A failure between them would leave
        // the block recorded while an already-accepted lift request (and
        // therefore its messaging thread) survived, exactly the state the
        // cancellation step exists to prevent.
        $ownTransaction = !$pdo->inTransaction();
        if ($ownTransaction) $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO user_blocks (blocker_id, blocked_id, reason)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE reason = VALUES(reason), created_at = CURRENT_TIMESTAMP"
            );
            $stmt->execute([$blockerId, $blockedId, $reason !== null && $reason !== '' ? mb_substr($reason, 0, 280) : null]);

            // Cancel any active lift requests between the two users so the
            // connection actually severs (matches stop showing them; messaging
            // is gated on accepted state).
            $cancel = $pdo->prepare(
                "UPDATE lift_requests lr
                 JOIN journeys fj ON fj.id = lr.from_journey_id
                 JOIN journeys tj ON tj.id = lr.to_journey_id
                 SET lr.status = 'cancelled', lr.responded_at = CURRENT_TIMESTAMP
                 WHERE lr.status IN ('pending','accepted')
                   AND ((fj.user_id = ? AND tj.user_id = ?)
                     OR (fj.user_id = ? AND tj.user_id = ?))"
            );
            $cancel->execute([$blockerId, $blockedId, $blockedId, $blockerId]);

            if ($ownTransaction) $pdo->commit();
        } catch (\Throwable $e) {
            if ($ownTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * <summary>
     * Remove a block from blocker to blocked.
     * </summary>
     * <param name="blockerId">The user who placed the block.</param>
     * <param name="blockedId">The user who was blocked.</param>
     * <returns>True if a block row was removed, false if there was nothing to unblock.</returns>
     */
    public static function unblock(int $blockerId, int $blockedId): bool
    {
        $stmt = Db::pdo()->prepare(
            "DELETE FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?"
        );
        $stmt->execute([$blockerId, $blockedId]);
        return $stmt->rowCount() > 0;
    }

    /**
     * <summary>
     * List the users that the given user has blocked, newest first, with
     * display name and avatar for the settings page.
     * </summary>
     * <param name="userId">The blocker whose list to fetch.</param>
     * <returns>An array of associative rows with id, display_name, avatar_url, reason and created_at.</returns>
     */
    public static function listForUser(int $userId): array
    {
        $stmt = Db::pdo()->prepare(
            "SELECT b.blocked_id AS id, u.display_name, u.avatar_url, b.reason, b.created_at
             FROM user_blocks b
             JOIN users u ON u.id = b.blocked_id
             WHERE b.blocker_id = ?
             ORDER BY b.created_at DESC"
        );
        $stmt->execute([$userId]);
        return array_map(static function (array $r): array {
            return [
                'id'           => (int) $r['id'],
                'display_name' => $r['display_name'],
                'avatar_url'   => $r['avatar_url'],
                'reason'       => $r['reason'],
                'created_at'   => $r['created_at'],
            ];
        }, $stmt->fetchAll());
    }

    /**
     * <summary>
     * File an abuse report against another user. Used by moderators only;
     * does not by itself block or take action against the target.
     * </summary>
     * <param name="reporterId">The user filing the report.</param>
     * <param name="targetId">The user being reported.</param>
     * <param name="reason">One of the allowed buckets: spam, harassment, impersonation, unsafe, scam, other.</param>
     * <param name="detail">Optional free-text detail. Clamped to 1000 characters.</param>
     * <returns>The new report row id.</returns>
     * <exception cref="\RuntimeException">If the user reports themselves or supplies an unknown reason.</exception>
     */
    public static function report(int $reporterId, int $targetId, string $reason, ?string $detail): int
    {
        if ($reporterId === $targetId) throw new \RuntimeException('You cannot report yourself');
        $valid = ['spam','harassment','impersonation','unsafe','scam','other'];
        if (!in_array($reason, $valid, true)) throw new \RuntimeException('Invalid reason');

        $pdo = Db::pdo();
        $stmt = $pdo->prepare(
            "INSERT INTO user_reports (reporter_id, target_id, reason, detail) VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$reporterId, $targetId, $reason, $detail !== null && $detail !== '' ? mb_substr($detail, 0, 1000) : null]);
        return (int) $pdo->lastInsertId();
    }
}
