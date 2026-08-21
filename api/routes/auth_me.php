<?php
declare(strict_types=1);

use SwiftLift\{Http, Auth, UserRepo};

/**
 * <summary>
 * GET /api/auth/me. Returns the current user or null when no session is active.
 * </summary>
 * <remarks>
 * The SPA hits this on boot to decide whether to render the signed in
 * shell or the marketing front page. Verification metadata is added to
 * the user payload so the SPA can pick between the normal app view, a
 * gentle reminder banner, and the hard forced gate without an extra
 * round trip. The front controller still enforces verification server
 * side, so the SPA's choice is purely cosmetic.
 * </remarks>
 */

$uid = Auth::userId();
if ($uid === null) Http::json(['user' => null]);

$user = UserRepo::findById($uid);

/**
 * <summary>
 * Decorate the user payload with verification timeline metadata.
 * </summary>
 * <remarks>
 * verify_state is one of "verified", "remind", "forced", or "disabled";
 * verify_force_at and verify_lock_at are the timestamps at which the
 * SPA should escalate the prompt. The front controller is still the
 * authority on what is allowed; this is hint data for the client view.
 * </remarks>
 */
if ($user !== null) {
    $user['verify_state']    = UserRepo::verifyState($user);
    $user['verify_force_at'] = UserRepo::verifyForcedAt($user);
    $user['verify_lock_at']  = UserRepo::verifyDisabledAt($user);
}

Http::json(['user' => $user]);
