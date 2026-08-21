<?php
declare(strict_types=1);

use SwiftLift\{Http, Auth, JourneyRepo};

/**
 * <summary>
 * /api/journeys router. CRUD for the signed in user's commute journeys.
 * Accepts GET (list), POST (create), PATCH /{id} (edit), and DELETE /{id} (remove).
 * </summary>
 * <remarks>
 * A journey describes a recurring start point, end point, time window,
 * and weekday mask. Each journey is either an offer (driver) or a
 * request (passenger), with that choice now made per journey rather
 * than per user account. Per journey filters for age range and
 * preferred sex layer on top of the user's profile defaults so the
 * SPA's New Journey form actually sticks.
 * </remarks>
 */

$uid = Auth::require();
$method = $_SERVER['REQUEST_METHOD'];
$tail = $_GET['_tail'] ?? '';

/**
 * <summary>
 * GET /api/journeys. Returns every journey the calling user owns.
 * </summary>
 * <remarks>
 * Ordering, geometry decoding, and is_active filtering happen in
 * JourneyRepo::listForUser so the SPA can render the journeys list
 * directly.
 * </remarks>
 */
if ($method === 'GET' && $tail === '') {
    Http::json(['journeys' => JourneyRepo::listForUser($uid)]);
}

/**
 * <summary>
 * POST /api/journeys. Creates a new journey from the validated payload.
 * </summary>
 * <remarks>
 * All location, time, days mask, and direction fields are required and
 * each is validated before any DB work. days_mask is a 7 bit bitfield
 * (Mon=1, Tue=2, ... Sun=64); anything outside the low 7 bits is
 * rejected. Per journey filters (age_min, age_max, pref_sex) are
 * passed through to the repo via array_key_exists rather than isset so
 * intentional nulls survive ("I want no minimum on this journey, even
 * though my profile has one"). The earlier version whitelisted only
 * the location and time fields, silently dropping the age filter the
 * user had set on the New Journey form. JourneyRepo::clampFilters
 * does the 18..120 plus 'male'/'female'/'any' validation and falls
 * back to the user's profile defaults for any key that is absent.
 * </remarks>
 */
if ($method === 'POST' && $tail === '') {
    $b = Http::body();
    foreach (['label','start_lat','start_lng','end_lat','end_lng','start_time','days_mask','direction'] as $f) {
        if (!isset($b[$f])) Http::error("Missing field: $f");
    }
    if (!in_array($b['direction'], ['offer','request'], true)) Http::error('Invalid direction');
    if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', (string)$b['start_time'])) Http::error('Invalid time');
    $daysMask = (int) $b['days_mask'];
    if ($daysMask < 0 || ($daysMask & ~0x7F)) Http::error('Invalid days_mask');

    $payload = [
        'label'      => (string)$b['label'],
        'start_lat'  => (float)$b['start_lat'],
        'start_lng'  => (float)$b['start_lng'],
        'end_lat'    => (float)$b['end_lat'],
        'end_lng'    => (float)$b['end_lng'],
        'route_wkt'  => $b['route_wkt'] ?? null,
        'start_time' => (string)$b['start_time'],
        'days_mask'  => $daysMask,
        'direction'  => $b['direction'],
        'seats'      => isset($b['seats']) ? (int) $b['seats'] : 1,
        'radius_m'   => isset($b['radius_m'])   ? (int) $b['radius_m']   : null,
        'window_min' => isset($b['window_min']) ? (int) $b['window_min'] : null,
    ];
    foreach (['age_min', 'age_max', 'pref_sex'] as $f) {
        if (array_key_exists($f, $b)) $payload[$f] = $b[$f];
    }
    $id = JourneyRepo::create($uid, $payload);

    // Best-effort: email anyone whose existing journey this new one now
    // matches. Never let a mail hiccup fail the create.
    try {
        \SwiftLift\MatchNotifier::notifyNewJourney($id);
    } catch (\Throwable $e) {
        error_log('[SwiftLift] match-notify failed for journey ' . $id . ': ' . $e->getMessage());
    }

    Http::json(['id' => $id], 201);
}

/**
 * <summary>
 * PATCH /api/journeys/{id}. Partial update of an existing journey.
 * </summary>
 * <remarks>
 * Only the supplied fields are touched; everything else is left as is.
 * Time, direction, and days_mask all have the same validation rules
 * as the create path. Ownership is enforced inside
 * JourneyRepo::update which throws if the journey id does not belong
 * to the calling user.
 * </remarks>
 */
if ($method === 'PATCH' && ctype_digit($tail)) {
    $b = Http::body();
    if (isset($b['start_time']) && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', (string)$b['start_time'])) {
        Http::error('Invalid time');
    }
    if (isset($b['direction']) && !in_array($b['direction'], ['offer','request'], true)) {
        Http::error('Invalid direction');
    }
    if (isset($b['days_mask'])) {
        $dm = (int) $b['days_mask'];
        if ($dm < 0 || ($dm & ~0x7F)) Http::error('Invalid days_mask');
    }
    try {
        $ok = JourneyRepo::update($uid, (int) $tail, $b);
        Http::json(['updated' => $ok]);
    } catch (\Throwable $e) {
        Http::error($e->getMessage());
    }
}

/**
 * <summary>
 * DELETE /api/journeys/{id}. Removes a journey owned by the calling user.
 * </summary>
 * <remarks>
 * Returns deleted=true on success, deleted=false if no row matched
 * the (uid, id) pair, which keeps the response shape symmetric across
 * already deleted and never owned cases.
 * </remarks>
 */
if ($method === 'DELETE' && ctype_digit($tail)) {
    $ok = JourneyRepo::delete($uid, (int)$tail);
    Http::json(['deleted' => $ok]);
}

Http::error('Not found', 404);
