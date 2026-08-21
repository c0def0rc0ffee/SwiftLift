/**
 * <summary>
 * Build-time feature flags for things that aren't ready for unmoderated
 * public use. Flip a flag to toggle the feature across the whole app.
 * </summary>
 */

/**
 * <summary>
 * User-set avatar images. Disabled for now.
 * </summary>
 * <remarks>
 * Avatars were a free-text image URL with no moderation, an abuse
 * vector (arbitrary or offensive imagery, hotlinking, tracking pixels).
 * While this is false the app shows initials everywhere and hides the
 * avatar field in Profile; the server also ignores any avatar_url in a
 * profile update. Re-enable once there's an upload + approval/moderation
 * flow. The mirror server-side switch is in api/routes/profile.php.
 * </remarks>
 */
export const AVATARS_ENABLED = false;
