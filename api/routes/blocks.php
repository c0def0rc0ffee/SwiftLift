<?php
declare(strict_types=1);

use SwiftLift\{Http, Auth, BlockRepo};

/**
 * <summary>
 * /api/blocks router. Manages the signed in user's personal block list.
 * Accepts GET (list), POST (add) and DELETE /{id} (remove) at /api/blocks.
 * </summary>
 * <remarks>
 * All branches require an authenticated session. Blocking is mutual at
 * the data layer in BlockRepo: a block between A and B is enforced in
 * both directions so neither party sees the other across match results
 * or messages. The reason field is optional and only stored to give
 * the blocker a personal memory aid.
 * </remarks>
 */

$uid    = Auth::require();
$method = $_SERVER['REQUEST_METHOD'];
$tail   = $_GET['_tail'] ?? '';

/**
 * <summary>
 * GET /api/blocks. Returns the list of users this account has blocked.
 * </summary>
 * <remarks>
 * Includes joined display name and avatar so the settings screen can
 * render the list without follow up lookups.
 * </remarks>
 */
if ($method === 'GET' && $tail === '') {
    Http::json(['blocks' => BlockRepo::listForUser($uid)]);
}

/**
 * <summary>
 * POST /api/blocks. Adds a new block against the given target user id.
 * </summary>
 * <remarks>
 * Requires a positive user_id. The optional reason is a free text note
 * kept private to the blocker. BlockRepo throws on self block, double
 * block, or unknown target; the exception text is surfaced verbatim
 * because every case is a deliberate business error.
 * </remarks>
 */
if ($method === 'POST' && $tail === '') {
    $b      = Http::body();
    $target = (int) ($b['user_id'] ?? 0);
    $reason = isset($b['reason']) ? (string) $b['reason'] : null;
    if ($target <= 0) Http::error('user_id is required');
    try {
        BlockRepo::block($uid, $target, $reason);
        Http::json(['ok' => true]);
    } catch (\Throwable $e) {
        Http::error($e->getMessage());
    }
}

/**
 * <summary>
 * DELETE /api/blocks/{id}. Removes the block against the given user id.
 * </summary>
 * <remarks>
 * The path tail must be a positive integer or the request falls
 * through to the catch all 404. Returns unblocked=true on success
 * and unblocked=false if no matching row existed, which the SPA can
 * treat as already unblocked.
 * </remarks>
 */
if ($method === 'DELETE' && ctype_digit($tail)) {
    $ok = BlockRepo::unblock($uid, (int) $tail);
    Http::json(['unblocked' => $ok]);
}

Http::error('Not found', 404);
