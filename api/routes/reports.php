<?php
declare(strict_types=1);

use SwiftLift\{Http, Auth, BlockRepo};

/**
 * <summary>
 * /api/reports router. Accepts POSTs that file a misconduct report against
 * another user. Read of the reports table is intentionally not exposed
 * here. Triage happens out of band by the operator.
 * </summary>
 * <remarks>
 * The reports table is separate from blocks: a report is a signal to
 * the operator about behaviour, where a block is the reporter's own
 * "I never want to see this person again" toggle. The routes share a
 * repo because both originate from the same context menu on a
 * counter party profile. Requires an authenticated session for every
 * branch.
 * </remarks>
 */

$uid    = Auth::require();
$method = $_SERVER['REQUEST_METHOD'];
$tail   = $_GET['_tail'] ?? '';

/**
 * <summary>
 * POST /api/reports. Files a new misconduct report against the given user id.
 * </summary>
 * <remarks>
 * user_id is mandatory and must be positive; reason is a short
 * category string (the SPA picks from a fixed list), and detail is
 * the optional free text body. BlockRepo::report validates all three
 * before insert and throws on a self report or unknown target. The
 * exception text is surfaced verbatim because every failure case is
 * a deliberate business error.
 * </remarks>
 */
if ($method === 'POST' && $tail === '') {
    $b      = Http::body();
    $target = (int) ($b['user_id'] ?? 0);
    $reason = (string) ($b['reason'] ?? '');
    $detail = isset($b['detail']) ? (string) $b['detail'] : null;
    if ($target <= 0) Http::error('user_id is required');
    try {
        $id = BlockRepo::report($uid, $target, $reason, $detail);
        Http::json(['id' => $id], 201);
    } catch (\Throwable $e) {
        Http::error($e->getMessage());
    }
}

Http::error('Not found', 404);
