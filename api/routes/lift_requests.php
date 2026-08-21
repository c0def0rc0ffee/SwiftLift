<?php
declare(strict_types=1);

use SwiftLift\{Http, Auth, LiftRequestRepo};

/**
 * <summary>
 * /api/lift-requests router. Manages cross user lift requests, the records
 * that link one journey to another and carry their acceptance status and
 * any first message exchanged.
 * </summary>
 * <remarks>
 * A lift request is an offer or ask: "my journey J1 would like to share
 * with your journey J2". The receiving side accepts, declines, or
 * cancels, and once accepted both sides can exchange messages on the
 * request. Daily quotas are enforced inside LiftRequestRepo and the
 * remaining_today figure is returned with the list so the SPA can
 * gate the new request button.
 * </remarks>
 */

$uid    = Auth::require();
$method = $_SERVER['REQUEST_METHOD'];
$tail   = $_GET['_tail'] ?? '';

/**
 * <summary>
 * GET /api/lift-requests. Lists the calling user's lift requests in both directions.
 * </summary>
 * <remarks>
 * The response includes remaining_today (the per user create quota
 * left for today) and max_chars (the message length cap) so the SPA
 * does not need extra round trips to configure its UI.
 * </remarks>
 */
if ($method === 'GET' && $tail === '') {
    Http::json([
        'requests'        => LiftRequestRepo::listForUser($uid),
        'remaining_today' => LiftRequestRepo::dailyRemaining($uid),
        'max_chars'       => LiftRequestRepo::MAX_MESSAGE,
    ]);
}

/**
 * <summary>
 * POST /api/lift-requests. Creates a new lift request between two journeys.
 * </summary>
 * <remarks>
 * Both from_journey_id and to_journey_id must be positive integers.
 * LiftRequestRepo::create enforces ownership of the "from" journey,
 * checks block status in both directions, applies the daily quota,
 * and validates the optional first message. Returns 201 with the new
 * request id on success.
 * </remarks>
 */
if ($method === 'POST' && $tail === '') {
    $b = Http::body();
    $from = (int) ($b['from_journey_id'] ?? 0);
    $to   = (int) ($b['to_journey_id']   ?? 0);
    $msg  = isset($b['message']) ? (string) $b['message'] : null;
    if ($from <= 0 || $to <= 0) Http::error('from_journey_id and to_journey_id are required');

    try {
        $id = LiftRequestRepo::create($uid, $from, $to, $msg);
    } catch (\Throwable $e) {
        Http::error($e->getMessage());
    }
    Http::json(['id' => $id], 201);
}

/**
 * <summary>
 * PATCH /api/lift-requests/{id}. Updates the status of a lift request.
 * </summary>
 * <remarks>
 * Valid transitions are enforced inside LiftRequestRepo::setStatus.
 * Only the receiver can accept or decline; only the sender can
 * cancel. The full updated row is echoed back so the SPA can swap
 * state without a follow up GET.
 * </remarks>
 */
if ($method === 'PATCH' && ctype_digit($tail)) {
    $b = Http::body();
    $newStatus = (string) ($b['status'] ?? '');
    try {
        $row = LiftRequestRepo::setStatus($uid, (int) $tail, $newStatus);
    } catch (\Throwable $e) {
        Http::error($e->getMessage());
    }
    Http::json(['request' => $row]);
}

Http::error('Not found', 404);
