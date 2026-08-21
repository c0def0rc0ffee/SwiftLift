<?php
declare(strict_types=1);

use SwiftLift\{Http, Auth};

/**
 * <summary>
 * POST /api/auth/logout. Destroys the current session and acknowledges.
 * </summary>
 * <remarks>
 * Method restricted to POST so that an idle GET cannot be used as a
 * CSRF style logout vector. Always returns ok regardless of whether a
 * session was active, since the desired end state is the same either way.
 * </remarks>
 */

Http::requireMethod('POST');
Auth::logout();
Http::json(['ok' => true]);
