<?php
declare(strict_types=1);

use SwiftLift\{Http, IssueRepo, Version};

/**
 * <summary>
 * /api/issues router. Exposes the public changelog and roadmap entries.
 * </summary>
 * <remarks>
 * No authentication is required. Only rows with is_public=1 are
 * returned, so internal triage items stay private. The current app
 * version is included alongside the list so the About page in the SPA
 * can render both with a single request.
 * </remarks>
 */

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$tail   = $_GET['_tail'] ?? '';

/**
 * <summary>
 * GET /api/issues. Returns the current version and the public issue list.
 * </summary>
 * <remarks>
 * The About page renders this as the "Improvements" section. Ordering
 * and filtering happens in IssueRepo::listPublic.
 * </remarks>
 */
if ($method === 'GET' && $tail === '') {
    Http::json([
        'version' => Version::current(),
        'issues'  => IssueRepo::listPublic(),
    ]);
}

Http::error('Not found', 404);
