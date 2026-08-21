<?php
declare(strict_types=1);

use SwiftLift\{Http, Auth, JourneyRepo};

/**
 * <summary>
 * GET /api/matches/{journey_id}. Returns the list of compatible journeys for
 * the given journey, applying the matches modal's radius and window overrides.
 * </summary>
 * <remarks>
 * Requires the caller to own the source journey; ownership is enforced
 * by comparing the journey row's user_id against the session user.
 * The radius and time window come from the journey's stored defaults
 * but can be overridden per request via ?radius=&window= when the SPA's
 * sliders move. Both override values are clamped to sensible ranges
 * (radius 100..2000m, window 5..240 minutes) so a hand crafted URL
 * cannot trigger a runaway spatial query.
 * </remarks>
 */

$uid = Auth::require();
Http::requireMethod('GET');

$tail = $_GET['_tail'] ?? '';
if (!ctype_digit($tail)) Http::error('Journey id required in path');
$journeyId = (int)$tail;

$defaults = JourneyRepo::findSearchDefaults($journeyId);
if ($defaults === null || $defaults['user_id'] !== $uid) Http::error('Not found', 404);

/**
 * <summary>
 * Resolve the effective radius and window from the journey defaults and the URL overrides.
 * </summary>
 * <remarks>
 * The journey row stores the user's chosen per journey radius and
 * window. The matches modal in the SPA exposes sliders that override
 * those temporarily; the override is passed in the query string and
 * clamped here so a deliberately wild value cannot widen the search
 * beyond what the database can serve quickly.
 * </remarks>
 */
$radius = isset($_GET['radius']) ? max(100, min(2000,  (int) $_GET['radius'])) : $defaults['radius_m'];
$window = isset($_GET['window']) ? max(5,   min(240,   (int) $_GET['window'])) : $defaults['window_min'];

$matches = JourneyRepo::findMatches($journeyId, $radius, $window);

/**
 * <summary>
 * Drivers (offer journeys) get an anonymised demand signal only, a
 * count, never the passenger rows. Passengers (request journeys) get the
 * full browsable list as before.
 * </summary>
 * <remarks>
 * In this app only the person seeking a lift initiates a request; the
 * driver receives requests and accepts or declines them. So a driver has
 * no need to see who is looking, exposing names, pins or contact would
 * just be a harassment surface, made worse by the age / sex preference
 * filters. We still tell them HOW MANY people match, so they know whether
 * keeping the offer active is worthwhile. The empty matches array is the
 * server-side guarantee: even a buggy client cannot render passenger
 * identities for a driver, because they are never sent.
 * </remarks>
 */
if ($defaults['direction'] === 'offer') {
    Http::json(['matches' => [], 'demand_count' => count($matches)]);
} else {
    Http::json(['matches' => $matches, 'demand_count' => count($matches)]);
}
