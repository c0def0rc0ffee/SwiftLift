<?php
declare(strict_types=1);

use SwiftLift\{Http, Auth, GroupRepo};

/**
 * <summary>
 * /api/groups router. CRUD for the signed in user's carpool groups along
 * with membership management and the per group driver rotation.
 * </summary>
 * <remarks>
 * Groups are the persistent counterpart to one off journey matches. A
 * group has a fixed set of members, a destination, a meeting time, a
 * days mask, and a rotation that maps Mon to Sun to the member who is
 * driving that day. The "join by code" path lets a member share a
 * short invite code rather than wiring up a full invite table. Every
 * branch requires an authenticated session; permission checks live in
 * GroupRepo so this file is purely routing and validation.
 * </remarks>
 */

$uid    = Auth::require();
$method = $_SERVER['REQUEST_METHOD'];
$tail   = $_GET['_tail'] ?? '';

/**
 * <summary>
 * GET /api/groups. Lists my groups with today's and tomorrow's drivers resolved.
 * </summary>
 * <remarks>
 * The "who is driving today" lookup is done server side so the SPA
 * can render the group cards without computing day of week or
 * resolving the rotation itself.
 * </remarks>
 */
if ($method === 'GET' && $tail === '') {
    Http::json(['groups' => GroupRepo::listForUser($uid)]);
}

/**
 * <summary>
 * POST /api/groups. Creates a new group with the calling user as the creator.
 * </summary>
 * <remarks>
 * GroupRepo::create handles validation of the destination, time, days
 * mask, and member home pins. The new group's id is returned with a
 * 201 so the SPA can navigate to its detail view immediately.
 * </remarks>
 */
if ($method === 'POST' && $tail === '') {
    $b = Http::body();
    try {
        $id = GroupRepo::create($uid, $b);
        Http::json(['id' => $id], 201);
    } catch (\Throwable $e) {
        Http::error($e->getMessage());
    }
}

/**
 * <summary>
 * POST /api/groups/join. Joins an existing group using the shared invite code.
 * </summary>
 * <remarks>
 * GroupRepo::joinByCode validates the code, refuses duplicates, and
 * is idempotent for members who have already joined. Returns the joined group's id
 * on success so the SPA can navigate to it.
 * </remarks>
 */
if ($method === 'POST' && $tail === 'join') {
    $b    = Http::body();
    $code = (string) ($b['code'] ?? '');
    try {
        $id = GroupRepo::joinByCode($uid, $code);
        Http::json(['id' => $id]);
    } catch (\Throwable $e) {
        Http::error($e->getMessage());
    }
}

/**
 * <summary>
 * /api/groups/{id}/... sub router. Splits on the numeric id and routes the suffix.
 * </summary>
 * <remarks>
 * Wrapping the per group operations behind a regex keeps the file
 * compact. The sub branches below cover detail, edit, delete,
 * rotation, and self membership update or leave.
 * </remarks>
 */
if (preg_match('#^(\d+)(?:/(.*))?$#', $tail, $m)) {
    $groupId = (int) $m[1];
    $sub     = $m[2] ?? '';

    /**
     * <summary>
     * GET /api/groups/{id}. Returns the full group state visible to the caller.
     * </summary>
     * <remarks>
     * Throws 404 via the catch block when the group does not exist or
     * the caller is not a member, so we never reveal the existence of
     * groups the user is not part of.
     * </remarks>
     */
    if ($method === 'GET' && $sub === '') {
        try {
            Http::json(GroupRepo::detail($groupId, $uid));
        } catch (\Throwable $e) {
            Http::error($e->getMessage(), 404);
        }
    }

    /**
     * <summary>
     * PATCH /api/groups/{id}. Edits the group's name, time, days, or destination.
     * </summary>
     * <remarks>
     * Permission gating (creator only, or admin only depending on the
     * field) lives inside GroupRepo::updateGroup. The full detail
     * payload is echoed back so the SPA can swap state without a
     * follow up GET.
     * </remarks>
     */
    if ($method === 'PATCH' && $sub === '') {
        $b = Http::body();
        try {
            GroupRepo::updateGroup($uid, $groupId, $b);
            Http::json(GroupRepo::detail($groupId, $uid));
        } catch (\Throwable $e) {
            Http::error($e->getMessage());
        }
    }

    /**
     * <summary>
     * DELETE /api/groups/{id}. Removes the group. Restricted to its creator.
     * </summary>
     * <remarks>
     * Cascades inside GroupRepo: memberships, rotation rows, and the
     * group row itself are removed in a single transaction.
     * </remarks>
     */
    if ($method === 'DELETE' && $sub === '') {
        $ok = GroupRepo::delete($uid, $groupId);
        Http::json(['deleted' => $ok]);
    }

    /**
     * <summary>
     * PUT /api/groups/{id}/rotation. Replaces the Mon to Sun driver map for the group.
     * </summary>
     * <remarks>
     * The rotation payload is a sparse map of weekday index to member
     * user id. setRotation validates that every nominated driver is a
     * current member; absent days mean "no driver assigned yet" and
     * the SPA renders them as gaps.
     * </remarks>
     */
    if ($method === 'PUT' && $sub === 'rotation') {
        $b = Http::body();
        $rotation = is_array($b['rotation'] ?? null) ? $b['rotation'] : [];
        try {
            GroupRepo::setRotation($uid, $groupId, $rotation);
            Http::json(GroupRepo::detail($groupId, $uid));
        } catch (\Throwable $e) {
            Http::error($e->getMessage());
        }
    }

    /**
     * <summary>
     * PATCH /api/groups/{id}/me. Updates my own pick up pin or label for this group.
     * </summary>
     * <remarks>
     * Each member has their own home location within a group, so the
     * driver knows where to collect them. This route only touches the
     * caller's membership row, never anyone else's.
     * </remarks>
     */
    if ($method === 'PATCH' && $sub === 'me') {
        $b = Http::body();
        try {
            GroupRepo::updateMyMembership($uid, $groupId, $b);
            Http::json(GroupRepo::detail($groupId, $uid));
        } catch (\Throwable $e) {
            Http::error($e->getMessage());
        }
    }

    /**
     * <summary>
     * DELETE /api/groups/{id}/me. Removes the caller from the group.
     * </summary>
     * <remarks>
     * GroupRepo::leave handles the edge cases: a sole remaining
     * creator triggers a soft handover, and the rotation map is
     * scrubbed of the leaving member so no day is left pointing at a
     * non member.
     * </remarks>
     */
    if ($method === 'DELETE' && $sub === 'me') {
        try {
            GroupRepo::leave($uid, $groupId);
            Http::json(['left' => true]);
        } catch (\Throwable $e) {
            Http::error($e->getMessage());
        }
    }
}

Http::error('Not found', 404);
