<?php
declare(strict_types=1);

use SwiftLift\{Http, Auth, MessageRepo};

/**
 * <summary>
 * /api/messages router. Reads and sends messages exchanged on lift requests,
 * and serves the unread plus last message summary used by the inbox view.
 * </summary>
 * <remarks>
 * Messages are scoped to lift requests, not to user pairs, so every
 * conversation is anchored to a specific journey pairing. The
 * remaining_today and max_chars fields are returned alongside the
 * payload so the SPA can configure its composer without further round
 * trips. MessageRepo enforces participation: only the sender and
 * receiver of the lift request can read or post on its thread.
 * </remarks>
 */

$uid    = Auth::require();
$method = $_SERVER['REQUEST_METHOD'];
$tail   = $_GET['_tail'] ?? '';

/**
 * <summary>
 * GET /api/messages?lift_request_id=N. Lists every message in a single thread.
 * </summary>
 * <remarks>
 * The lift request id is mandatory and must be a positive integer.
 * MessageRepo::listForLiftRequest verifies the caller is one of the
 * two parties on the underlying request before returning anything.
 * Side carries the remaining daily send quota and the message length
 * cap so the composer can render its hint text without another call.
 * </remarks>
 */
if ($method === 'GET' && $tail === '') {
    $lr = (int) ($_GET['lift_request_id'] ?? 0);
    if ($lr <= 0) Http::error('lift_request_id is required');
    try {
        Http::json([
            'messages'        => MessageRepo::listForLiftRequest($uid, $lr),
            'remaining_today' => MessageRepo::dailyRemaining($uid),
            'max_chars'       => MessageRepo::MAX_BODY,
        ]);
    } catch (\Throwable $e) {
        Http::error($e->getMessage());
    }
}

/**
 * <summary>
 * POST /api/messages. Sends a message on the given lift request thread.
 * </summary>
 * <remarks>
 * lift_request_id is required; body is the message text and is
 * length checked against MAX_BODY inside the repo. Daily quotas
 * apply and a 201 is returned on success with the newly inserted
 * row so the SPA can append without a follow up list call.
 * </remarks>
 */
if ($method === 'POST' && $tail === '') {
    $b    = Http::body();
    $lr   = (int) ($b['lift_request_id'] ?? 0);
    $body = (string) ($b['body'] ?? '');
    if ($lr <= 0) Http::error('lift_request_id is required');
    try {
        Http::json(['message' => MessageRepo::send($uid, $lr, $body)], 201);
    } catch (\Throwable $e) {
        Http::error($e->getMessage());
    }
}

/**
 * <summary>
 * GET /api/messages/summary. Returns the unread plus last message summary
 * across all of the user's lift request connections.
 * </summary>
 * <remarks>
 * Used by the inbox screen and the top bar badge counter. Includes
 * the remaining daily quota and the message length cap so the SPA
 * can prime its composer without a follow up call when the user
 * picks a thread.
 * </remarks>
 */
if ($method === 'GET' && $tail === 'summary') {
    Http::json([
        'summary'         => MessageRepo::summaryForUser($uid),
        'remaining_today' => MessageRepo::dailyRemaining($uid),
        'max_chars'       => MessageRepo::MAX_BODY,
    ]);
}

Http::error('Not found', 404);
