/**
 * <summary>
 * Minimum password length enforced server side by
 * src/PasswordSecurity.php (MIN_LENGTH = 10).
 * </summary>
 * <remarks>
 * Centralised on the client so every registration, reset and change
 * password form on the frontend agrees with the backend, rather than
 * having separate hardcoded eights and tens drift around the codebase.
 * </remarks>
 */
export const PASSWORD_MIN = 10;

/**
 * <summary>
 * The user's chosen visual theme. `'auto'` follows the operating
 * system preference at render time; the other two pin the theme.
 * </summary>
 */
export type Theme        = 'dark' | 'light' | 'auto';

/**
 * <summary>
 * Smoking preference advertised on a journey or profile. `'outside'`
 * sits between the binary yes and no for drivers who tolerate breaks
 * at stops but not in the cabin.
 * </summary>
 */
export type SmokingPref  = 'yes' | 'no' | 'outside';

/**
 * <summary>
 * Pets preference on a journey or profile. `'small_only'` permits
 * carriers and lap dogs while excluding larger animals.
 * </summary>
 */
export type PetsPref     = 'yes' | 'no' | 'small_only';

/**
 * <summary>
 * The currently authenticated user as returned by `/api/auth/me` and
 * `/api/profile`. Includes identity, profile copy, ride preferences,
 * car details and the email verification timeline state.
 * </summary>
 * <remarks>
 * The `verify_state` field drives the App level routing: `verified`
 * suppresses the banner, `remind` shows a soft yellow nag with a
 * deadline, `forced` hands the user over to VerifyGate, and
 * `disabled` is treated as a logged out state. The `verify_force_at`
 * and `verify_lock_at` timestamps mark the moments those transitions
 * are scheduled to happen and let the UI render countdown copy.
 * </remarks>
 */
export type User = {
  id: number;
  email: string;
  display_name: string;
  avatar_url: string | null;
  bio: string | null;
  age: number | null;
  age_min: number | null;
  age_max: number | null;
  /**
   * <summary>
   * Self-reported sex of the user. `null` means the user has chosen
   * "prefer not to say".
   * </summary>
   */
  sex: 'male' | 'female' | null;
  /**
   * <summary>
   * Which sex of counterparty the user wants surfaced by the matcher.
   * `'any'` is the default and disables this filter.
   * </summary>
   */
  pref_sex: 'any' | 'male' | 'female';
  is_away: boolean;
  theme: Theme;
  default_radius_m: number;
  default_window_min: number;
  car_make: string | null;
  car_colour: string | null;
  car_seats: number | null;
  pref_smoking: SmokingPref | null;
  pref_pets: PetsPref | null;
  pref_music: string | null;
  detour_m: number;
  /** Whether the user gets "a new journey matches yours" emails. */
  notify_matches: boolean;
  email_verified: boolean;
  email_verified_at?: string | null;
  created_at?: string;
  updated_at?: string;
  /**
   * <summary>
   * Email verification timeline state populated by `/api/auth/me`.
   * </summary>
   * <remarks>
   * `verified`: banner suppressed, nothing blocked.
   * `remind`: soft yellow banner shown with a "verify by date" deadline.
   * `forced`: the full screen Verify gate replaces the app; only the
   * profile and verify routes still work server side.
   * `disabled`: account is disabled. The user is logged out and cannot
   * get back in until an administrator reactivates them.
   * </remarks>
   */
  verify_state?: 'verified' | 'remind' | 'forced' | 'disabled';
  /**
   * <summary>
   * ISO 8601 timestamp at which `remind` is scheduled to flip to
   * `forced`. Used to render countdown copy in the soft reminder
   * banner.
   * </summary>
   */
  verify_force_at?: string | null;
  /**
   * <summary>
   * ISO 8601 timestamp at which `forced` is scheduled to flip to
   * `disabled`, locking the account out entirely.
   * </summary>
   */
  verify_lock_at?:  string | null;
};

/**
 * <summary>
 * Patch payload accepted by `PATCH /api/profile`. A partial slice of
 * the editable fields on User. Any subset may be supplied; omitted
 * keys are left unchanged on the server.
 * </summary>
 * <remarks>
 * Identity and verification fields (`id`, `email_verified`,
 * `verify_state`, timestamps) are deliberately not included; changes
 * to them flow through dedicated routes such as the email verify
 * confirmation endpoint.
 * </remarks>
 */
export type ProfileUpdate = Partial<
  Pick<User,
    'display_name' | 'email' | 'avatar_url' | 'bio' |
    'age' | 'age_min' | 'age_max' | 'sex' | 'pref_sex' |
    'is_away' | 'theme' | 'default_radius_m' | 'default_window_min' |
    'car_make' | 'car_colour' | 'car_seats' | 'pref_smoking' | 'pref_pets' |
    'pref_music' | 'detour_m' | 'notify_matches'>
>;

/**
 * <summary>
 * Driver context attached to a passenger journey once a lift request
 * has been accepted. Provides the driver's pickup, dropoff, route and
 * departure time so the UI can show "be here, at this time" instead
 * of the passenger's original pins.
 * </summary>
 * <remarks>
 * The passenger's own coordinates remain on the parent Journey; this
 * is purely a display swap. `route_wkt` carries the driver's OSRM
 * road route if one was computed, and `days_mask` repeats the
 * driver's recurrence so the UI can render "next occurrence" copy
 * keyed off the driver's schedule.
 * </remarks>
 */
export type ConnectedDriver = {
  driver_journey_id: number;
  driver_user_id: number;
  driver_name: string;
  driver_avatar: string | null;
  start_lat: number;
  start_lng: number;
  end_lat: number;
  end_lng: number;
  start_time: string;
  route_wkt: string | null;
  days_mask: number;
};

/**
 * <summary>
 * A row from `/api/issues`. Represents one entry on the public
 * changelog and roadmap surfaced on the About page.
 * </summary>
 * <remarks>
 * Operator managed through the Issues tab of `/admin.php`. `kind`
 * classifies the entry for icon and grouping purposes; `status` and
 * `severity` drive the colour and ordering on the About page.
 * `target_version` and `fixed_in_version` track the release the work
 * is aimed at and the release it shipped in respectively.
 * </remarks>
 */
export type Issue = {
  id: number;
  kind: 'feature' | 'improvement' | 'bug';
  title: string;
  description: string | null;
  status: 'planned' | 'in_progress' | 'fixed' | 'rejected';
  severity: 'low' | 'medium' | 'high' | 'critical' | null;
  target_version: string | null;
  fixed_in_version: string | null;
  sort_order: number;
  created_at: string;
  updated_at: string;
};

/**
 * <summary>
 * A single recurring journey owned by the current user, either an
 * `offer` (driver) or a `request` (passenger). Carries pickup and
 * dropoff coordinates, the OSRM road route when computed, the
 * weekly recurrence and time, and the matcher tuning knobs.
 * </summary>
 * <remarks>
 * `days_mask` is a seven bit bitmask where bit 0 is Monday and bit 6
 * is Sunday. `radius_m` and `window_min` widen the match search
 * geographically and in time around the journey's pins and start
 * time. `route_wkt` is a WKT LINESTRING in lon lat order; null until
 * a route has been fetched from OSRM.
 * </remarks>
 */
export type Journey = {
  id: number;
  label: string;
  start_lat: number;
  start_lng: number;
  end_lat: number;
  end_lng: number;
  route_wkt: string | null;
  start_time: string;
  days_mask: number;
  direction: 'offer' | 'request';
  seats: number;
  seats_taken?: number;
  radius_m: number;
  window_min: number;
  is_active: boolean;
  /**
   * <summary>
   * Per journey age and sex filter on counterparties. Seeded from
   * the user's profile defaults at create time and then editable
   * per journey.
   * </summary>
   * <remarks>
   * `null` for age means "no lower or upper bound on this side".
   * </remarks>
   */
  age_min: number | null;
  age_max: number | null;
  pref_sex: 'any' | 'male' | 'female';
  /**
   * <summary>
   * Populated only for passenger journeys that have an accepted lift
   * connection. See ConnectedDriver for the display swap semantics.
   * </summary>
   */
  connected_driver?: ConnectedDriver | null;
};

/**
 * <summary>
 * A single counterparty match for a given journey, returned by
 * `/api/matches/{journeyId}`. Combines the candidate user's public
 * profile fields, their journey shape and the geographic distance
 * between the two journeys' endpoints.
 * </summary>
 * <remarks>
 * `start_dist_m` and `end_dist_m` are the great circle distances in
 * metres between the requesting journey's pins and this candidate's
 * pins. The matcher already filters by the journey's `radius_m`
 * before returning, so values here are guaranteed to be inside the
 * requested radius.
 * </remarks>
 */
export type Match = {
  id: number;
  display_name: string;
  avatar_url: string | null;
  age: number | null;
  car_make: string | null;
  car_colour: string | null;
  pref_smoking: SmokingPref | null;
  pref_pets: PetsPref | null;
  pref_music: string | null;
  detour_m: number;
  seats: number;
  seats_taken: number;
  route_wkt: string | null;
  label: string;
  start_lat: number;
  start_lng: number;
  end_lat: number;
  end_lng: number;
  start_time: string;
  days_mask: number;
  direction: 'offer' | 'request';
  start_dist_m: number;
  end_dist_m: number;
};

// Route via index.php?p=... rather than /api/auth/register to avoid any
// .htaccess rewrite shenanigans on shared hosting (some configs drop POST
// bodies through internal rewrites).
/**
 * <summary>
 * Module level flag set to true by `request()` the moment the
 * backend signals it is in maintenance (503 with
 * `{"maintenance": true}`).
 * </summary>
 * <remarks>
 * The SPA top level subscribes through `Maintenance.onChange` and
 * swaps in MaintenancePage when the flag flips. Cleared only by a
 * manual reload; the maintenance page itself auto reloads
 * periodically so when the operator turns maintenance off the SPA
 * recovers without user action. Old comment referencing a 30 second
 * auto reload is preserved here for the original rationale.
 * </remarks>
 */
let maintenanceFlag = false;
let maintenanceMessage = '';
let maintenanceListener: (() => void) | null = null;

/**
 * <summary>
 * Pub sub singleton exposing the maintenance flag and the operator
 * supplied message. The top level App component reads this on every
 * render and subscribes to changes.
 * </summary>
 * <remarks>
 * Implemented as a plain object with three methods rather than a
 * full event emitter because only a single subscriber (the App
 * component) ever cares; replacing the listener simply overwrites
 * the slot. The returned `dispose` from `onChange` guards against
 * stale callbacks if the App component is somehow remounted.
 * </remarks>
 */
export const Maintenance = {
  /**
   * <summary>
   * Returns whether the maintenance flag is currently set.
   * </summary>
   * <returns>True when the backend has signalled maintenance.</returns>
   */
  isOn(): boolean { return maintenanceFlag; },
  /**
   * <summary>
   * Returns the operator supplied maintenance message captured the
   * first time the flag flipped on, or an empty string if no message
   * was provided.
   * </summary>
   * <returns>Maintenance copy for display, or empty string.</returns>
   */
  message(): string { return maintenanceMessage; },
  /**
   * <summary>
   * Registers a callback that fires the moment we first see a 503
   * with `maintenance: true`.
   * </summary>
   * <param name="cb">Callback invoked when the flag flips on.</param>
   * <returns>A disposer that unsubscribes the callback.</returns>
   * <remarks>
   * Only the most recent registration is retained because the only
   * subscriber is the App component. The disposer is no op if a
   * different callback has since taken the slot.
   * </remarks>
   */
  onChange(cb: () => void): () => void {
    maintenanceListener = cb;
    return () => { if (maintenanceListener === cb) maintenanceListener = null; };
  },
};

/**
 * <summary>
 * Internal helper that performs an authenticated JSON fetch against
 * the PHP backend. Routes through `/api/index.php?p=...` rather than
 * pretty URLs, sends cookies, and parses the JSON response.
 * </summary>
 * <param name="path">
 * Logical route such as `auth/me` or `journeys/123`. May include a
 * query string after a `?`. The route part is moved into the `p`
 * query parameter and any extra query string is preserved.
 * </param>
 * <param name="init">
 * Optional fetch init. Headers are merged with the default
 * `Content-Type: application/json`. `credentials: 'include'` is
 * always set so the PHP session cookie travels with the request.
 * </param>
 * <returns>
 * The parsed JSON body typed as `T`.
 * </returns>
 * <remarks>
 * Error handling unwraps a `{error: string}` body when present and
 * otherwise reports the status code plus a short prefix of the
 * response text. On a 503 with `{"maintenance": true}` it flips the
 * module level maintenance flag and notifies the subscriber; the
 * call itself still throws so individual call sites do not need to
 * special case it. Routing via `index.php?p=...` instead of pretty
 * URLs sidesteps `.htaccess` rewrite quirks on shared hosting where
 * some configurations silently drop POST bodies through internal
 * rewrites.
 * </remarks>
 */
async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const [routePart, queryPart] = path.split('?');
  const qs = new URLSearchParams(queryPart ?? '');
  qs.set('p', routePart);
  const res = await fetch(`/api/index.php?${qs.toString()}`, {
    credentials: 'include',
    // `...init` first, then headers: spreading init last would let an
    // `init.headers` replace the merged object wholesale and silently drop
    // Content-Type from every POST that passed one.
    ...init,
    headers: { 'Content-Type': 'application/json', ...(init.headers ?? {}) },
  });
  if (!res.ok) {
    const text = await res.text();
    let msg = `HTTP ${res.status}`;
    let body: any = null;
    try { body = JSON.parse(text); } catch { /* not JSON */ }
    if (body?.error) msg = body.error;
    else if (text.length > 0 && text.length < 500) msg = `HTTP ${res.status}: ${text.slice(0, 200)}`;

    // Maintenance: server is asking us to back off entirely. Set the
    // global flag so the top-level App switches to MaintenancePage.
    if (res.status === 503 && body?.maintenance === true) {
      if (!maintenanceFlag) {
        maintenanceFlag = true;
        maintenanceMessage = (body.message as string) || '';
        try { maintenanceListener?.(); } catch { /* ignore */ }
      }
    }
    throw new Error(msg);
  }
  return res.json();
}

/**
 * <summary>
 * Lifecycle status of a lift request. `pending` is the initial state
 * after creation; `accepted` and `declined` are terminal responses
 * from the recipient; `cancelled` is the sender or recipient
 * cancelling an accepted booking.
 * </summary>
 */
export type LiftRequestStatus = 'pending' | 'accepted' | 'declined' | 'cancelled';

/**
 * <summary>
 * A single lift request shown on the Activity tab. Bundles the
 * request lifecycle, the two journeys involved and a snapshot of the
 * counterparty's public profile so the row can render without
 * additional fetches.
 * </summary>
 * <remarks>
 * `direction` is relative to the current user: `sent` means the user
 * initiated the request, `received` means another rider sent it to
 * one of the user's journeys. `my_journey` and `their_journey` are
 * always oriented around that perspective. `responded_at` is set
 * once the request leaves `pending`.
 * </remarks>
 */
export type LiftRequest = {
  id: number;
  status: LiftRequestStatus;
  direction: 'sent' | 'received';
  message: string | null;
  created_at: string;
  responded_at: string | null;
  my_journey:    { id: number; label: string };
  their_journey: { id: number; label: string; start_time: string; days_mask: number };
  other_user: {
    id: number;
    display_name: string;
    avatar_url: string | null;
    age: number | null;
    bio: string | null;
    car_make: string | null;
    car_colour: string | null;
    pref_smoking: SmokingPref | null;
    pref_pets: PetsPref | null;
    pref_music: string | null;
    detour_m: number;
  };
};

/**
 * <summary>
 * A single message in a lift request chat thread. Either a user typed
 * line or a system generated notice surfaced inline (for example
 * "driver moved their pins").
 * </summary>
 * <remarks>
 * `kind = 'system'` rows are rendered centred and italic in the
 * thread with no bubble; `kind = 'user'` rows are rendered as normal
 * chat bubbles owned by `sender_id`. `read_at` is the timestamp at
 * which the recipient marked the message as read; null while
 * unread.
 * </remarks>
 */
export type Message = {
  id: number;
  sender_id: number;
  body: string;
  /**
   * <summary>
   * Message kind. `'user'` is typed in chat by `sender_id`;
   * `'system'` is an auto generated notice such as a pin move,
   * rendered centred and italic with no bubble.
   * </summary>
   */
  kind: 'user' | 'system';
  created_at: string;
  read_at: string | null;
};

/**
 * <summary>
 * Per lift request unread message counts and last activity, keyed by
 * `lift_request_id`. Returned by `messagesSummary` so the Activity
 * tab can render badges and previews without one fetch per thread.
 * </summary>
 * <remarks>
 * `unread` is the count of unread messages addressed to the current
 * user. `last_at`, `last_body` and `last_sender` describe the most
 * recent message of any kind in the thread; all three are null for
 * threads with no messages.
 * </remarks>
 */
export type MessageSummary = {
  [liftRequestId: number]: {
    unread: number;
    last_at: string | null;
    last_body: string | null;
    last_sender: number | null;
  };
};

/**
 * <summary>
 * Flags from `/auth/oauth/providers` describing which third party
 * sign in buttons the backend has credentials for. Buttons for
 * disabled providers are hidden on the Login screen.
 * </summary>
 */
export type OauthProviders = { facebook: boolean; google: boolean };

/**
 * <summary>
 * One day of a group rotation. Pairs a weekday with the driver
 * scheduled for that slot and their home pickup details.
 * </summary>
 * <remarks>
 * `day_of_week` runs 1 to 7 with Monday as 1. `driver` is null if no
 * one is assigned to that day yet.
 * </remarks>
 */
export type GroupDriverDay = {
  day_of_week: number;
  start_time: string;
  driver: {
    id: number;
    display_name: string;
    avatar_url: string | null;
    home_lat: number | null;
    home_lng: number | null;
    home_label: string | null;
  } | null;
};

/**
 * <summary>
 * Compact summary of a group as returned by `/api/groups`. Suitable
 * for rendering a card without fetching the full member list.
 * </summary>
 * <remarks>
 * `today` and `tomorrow` are precomputed rotation slots for the
 * next two relevant days so the dashboard card can show "you're
 * driving today" or "Alice is driving tomorrow" without a follow up
 * call. `is_creator` is true for the user who created the group and
 * gates destructive admin actions client side.
 * </remarks>
 */
export type GroupSummary = {
  id: number;
  name: string;
  type: 'school_run' | 'other';
  dest_lat: number | null;
  dest_lng: number | null;
  dest_label: string | null;
  start_time: string;
  days_mask: number;
  creator_id: number;
  invite_code: string;
  is_creator: boolean;
  created_at: string;
  today: GroupDriverDay | null;
  tomorrow: GroupDriverDay | null;
};

/**
 * <summary>
 * One row in the membership list of a group. Carries the member's
 * public profile plus their home pickup point, which is the location
 * the group rotation uses when they are scheduled to drive.
 * </summary>
 */
export type GroupMember = {
  id: number;
  display_name: string;
  avatar_url: string | null;
  home_lat: number | null;
  home_lng: number | null;
  home_label: string | null;
  joined_at: string;
};

/**
 * <summary>
 * Full detail of a group as returned by `/api/groups/{id}`. Extends
 * the summary with the member list and the current rotation.
 * </summary>
 * <remarks>
 * `rotation` is a record keyed by day of week (1 is Monday, 7 is
 * Sunday) mapping to the `user_id` of the driver scheduled for that
 * slot. Missing keys mean that day has no driver assigned.
 * </remarks>
 */
export type GroupDetail = {
  id: number;
  name: string;
  type: 'school_run' | 'other';
  dest_lat: number | null;
  dest_lng: number | null;
  dest_label: string | null;
  start_time: string;
  days_mask: number;
  creator_id: number;
  invite_code: string;
  is_creator: boolean;
  created_at: string;
  members: GroupMember[];
  /**
   * <summary>
   * Map keyed by day of week (1 is Monday and 7 is Sunday) to
   * driver `user_id`.
   * </summary>
   */
  rotation: Record<number, number>;
};

/**
 * <summary>
 * Façade over the SwiftLift HTTP backend. Each property is a thin
 * call to `request()` that types the response and binds the route.
 * </summary>
 * <remarks>
 * All calls go through `/api/index.php?p=...` and include cookies, so
 * authentication is implicit once the user has logged in. Calls that
 * fail throw an Error whose message is either the server's `error`
 * field, the prefix of the response body, or `HTTP {status}` as a
 * fallback. Maintenance 503s flip the global Maintenance flag in
 * addition to throwing.
 * </remarks>
 */
export const api = {
  /**
   * <summary>
   * Returns the currently authenticated user, or null if no session.
   * </summary>
   * <returns>Object with `user` set to the User or null.</returns>
   * <remarks>
   * Also populates `user.verify_state` and the verify timeline
   * timestamps used by the App level routing.
   * </remarks>
   */
  me:       () => request<{ user: User | null }>('auth/me'),
  /**
   * <summary>
   * Authenticates a user with email and password and returns the
   * resulting User row.
   * </summary>
   * <param name="email">Email address as entered by the user.</param>
   * <param name="password">Plain text password.</param>
   * <returns>The authenticated User.</returns>
   * <remarks>
   * On success the backend establishes a PHP session cookie that
   * subsequent calls carry implicitly. On failure throws with the
   * server's error message ("Invalid email or password" etc.).
   * </remarks>
   */
  login:    (email: string, password: string) =>
              request<User>('auth/login', { method: 'POST', body: JSON.stringify({ email, password }) }),
  /**
   * <summary>
   * Registers a new account. Does not log the user in.
   * </summary>
   * <param name="email">Desired email address.</param>
   * <param name="password">Plain text password (minimum length is PASSWORD_MIN).</param>
   * <param name="display_name">Display name shown to other riders.</param>
   * <param name="confirm_adult">Must be true; confirms the user is at least 18.</param>
   * <returns>`{ ok: true, check_email: true }` regardless of outcome.</returns>
   * <remarks>
   * The response shape is identical whether the email is new or
   * already in use, so an attacker cannot probe the user table by
   * watching responses. Registration is finalised when the user
   * clicks the verify email link, which calls `confirmVerifyEmail`
   * and only then establishes a session.
   * </remarks>
   */
  register: (email: string, password: string, display_name: string, confirm_adult: boolean) =>
              request<{ ok: true; check_email: true }>('auth/register', { method: 'POST', body: JSON.stringify({ email, password, display_name, confirm_adult }) }),
  /**
   * <summary>
   * Ends the current session on the server.
   * </summary>
   * <returns>`{ ok: true }` on success.</returns>
   */
  logout:   () => request<{ ok: true }>('auth/logout', { method: 'POST' }),

  /**
   * <summary>
   * Sends or resends the email verification link to the current
   * user's email address.
   * </summary>
   * <returns>
   * `{ ok: true }`, with `already: true` if the email was already
   * verified and no message was sent.
   * </returns>
   * <remarks>
   * Requires authentication. Rate limited server side to discourage
   * abuse.
   * </remarks>
   */
  sendVerifyEmail:  () => request<{ ok: true; already?: boolean }>('auth/verify/send', { method: 'POST' }),
  /**
   * <summary>
   * Confirms an email verification token from a `?verify=TOKEN` link
   * and logs the user in as a side effect.
   * </summary>
   * <param name="token">The verify token taken from the email link.</param>
   * <returns>The freshly verified User.</returns>
   * <remarks>
   * Throws if the token is expired or has already been consumed.
   * </remarks>
   */
  confirmVerifyEmail: (token: string) =>
    request<User>('auth/verify/confirm', { method: 'POST', body: JSON.stringify({ token }) }),
  /**
   * <summary>
   * Starts a password reset flow for the given email address.
   * </summary>
   * <param name="email">Email address claimed by the user.</param>
   * <returns>`{ ok: true }` regardless of whether the email exists.</returns>
   * <remarks>
   * Returns the same shape whether or not the email is known so the
   * endpoint cannot be used to enumerate accounts.
   * </remarks>
   */
  forgotPassword: (email: string) =>
    request<{ ok: true }>('auth/forgot', { method: 'POST', body: JSON.stringify({ email }) }),
  /**
   * <summary>
   * Completes a password reset using a token issued by
   * `forgotPassword`.
   * </summary>
   * <param name="token">Reset token from the email link.</param>
   * <param name="new_password">New password (minimum length is PASSWORD_MIN).</param>
   * <returns>`{ ok: true }` on success.</returns>
   */
  resetPassword: (token: string, new_password: string) =>
    request<{ ok: true }>('auth/reset', { method: 'POST', body: JSON.stringify({ token, new_password }) }),
  /**
   * <summary>
   * Reports which OAuth sign in buttons should be shown on Login.
   * </summary>
   * <returns>Flags for Facebook and Google.</returns>
   */
  oauthProviders: () =>
    request<OauthProviders>('auth/oauth/providers'),

  /**
   * <summary>
   * Fetches the full profile for the current user, including every
   * editable preference field.
   * </summary>
   * <returns>`{ user: User }` wrapping the row.</returns>
   * <remarks>Requires authentication.</remarks>
   */
  getProfile:    () => request<{ user: User }>('profile'),
  /**
   * <summary>
   * Applies a partial patch to the current user's profile.
   * </summary>
   * <param name="patch">Subset of editable User fields to update.</param>
   * <returns>The updated user row.</returns>
   * <remarks>
   * Sent as `PATCH`. Omitted keys are left untouched server side.
   * Email changes typically trigger a re verification flow.
   * </remarks>
   */
  updateProfile: (patch: ProfileUpdate) =>
                   request<{ user: User }>('profile', { method: 'PATCH', body: JSON.stringify(patch) }),
  /**
   * <summary>
   * Changes the user's password after verifying the current one.
   * </summary>
   * <param name="current_password">The user's existing password for confirmation.</param>
   * <param name="new_password">Replacement password (minimum length is PASSWORD_MIN).</param>
   * <returns>`{ ok: true }` on success.</returns>
   * <remarks>Existing sessions for the user may be revoked server side.</remarks>
   */
  changePassword: (current_password: string, new_password: string) =>
                   request<{ ok: true }>('profile/password', { method: 'POST', body: JSON.stringify({ current_password, new_password }) }),
  /**
   * <summary>
   * Deletes the current user's account.
   * </summary>
   * <returns>`{ deleted: true }` on success.</returns>
   * <remarks>
   * Irreversible. The session is invalidated as part of the call;
   * the next request will see `me()` return `{ user: null }`.
   * </remarks>
   */
  deleteAccount: () =>
                   request<{ deleted: true }>('profile', { method: 'DELETE' }),

  /**
   * <summary>
   * Lists all journeys owned by the current user.
   * </summary>
   * <returns>`{ journeys: Journey[] }` ordered by the server.</returns>
   */
  listJourneys:  () => request<{ journeys: Journey[] }>('journeys'),
  /**
   * <summary>
   * Creates a new journey for the current user.
   * </summary>
   * <param name="j">
   * Journey fields excluding server assigned `id`, `is_active`, and
   * `route_wkt`. A `route_wkt` may optionally be provided when the
   * caller has already fetched a road route from OSRM.
   * </param>
   * <returns>`{ id }` of the newly created journey.</returns>
   */
  createJourney: (j: Omit<Journey, 'id' | 'is_active' | 'route_wkt'> & { route_wkt?: string | null }) =>
                   request<{ id: number }>('journeys', { method: 'POST', body: JSON.stringify(j) }),
  /**
   * <summary>
   * Patches an existing journey.
   * </summary>
   * <param name="id">Journey id.</param>
   * <param name="patch">Partial journey fields excluding `id`.</param>
   * <returns>`{ updated: boolean }` true if any row was modified.</returns>
   */
  updateJourney: (id: number, patch: Partial<Omit<Journey, 'id'>>) =>
                   request<{ updated: boolean }>(`journeys/${id}`, { method: 'PATCH', body: JSON.stringify(patch) }),
  /**
   * <summary>
   * Deletes a journey.
   * </summary>
   * <param name="id">Journey id.</param>
   * <returns>`{ deleted: boolean }`.</returns>
   * <remarks>
   * Deletes the journey row and any pending lift requests tied to
   * it; accepted lift requests typically remain visible to the
   * counterparty server side for transparency.
   * </remarks>
   */
  deleteJourney: (id: number) =>
                   request<{ deleted: boolean }>(`journeys/${id}`, { method: 'DELETE' }),

  /**
   * <summary>
   * Returns candidate matches for the given journey.
   * </summary>
   * <param name="journeyId">Id of the journey to match against.</param>
   * <param name="radius">Search radius in metres around the start and end pins. Defaults to 2000.</param>
   * <param name="window">Time window in minutes either side of the journey's start time. Defaults to 30.</param>
   * <param name="signal">Optional AbortSignal to cancel the call (the matcher can be slow).</param>
   * <returns>
   * `{ matches, demand_count }`. For a passenger (request) journey `matches`
   * is the browsable list of drivers. For a driver (offer) journey `matches`
   * is always empty and `demand_count` is the number of people looking for a
   * lift on that route, drivers get a count, never identities.
   * </returns>
   * <remarks>
   * Server side this can be the slowest endpoint in the API; callers
   * should pass an AbortSignal so a fresh search aborts the
   * previous in flight one.
   * </remarks>
   */
  matches: (journeyId: number, radius = 2000, window = 30, signal?: AbortSignal) =>
    request<{ matches: Match[]; demand_count?: number }>(`matches/${journeyId}?radius=${radius}&window=${window}`, { signal }),

  /**
   * <summary>
   * Lists all lift requests the user is involved in, sent and
   * received.
   * </summary>
   * <returns>
   * The list together with the per user `remaining_today` quota and
   * the maximum allowed message length.
   * </returns>
   */
  listLiftRequests: () =>
    request<{ requests: LiftRequest[]; remaining_today: number; max_chars: number }>('lift-requests'),
  /**
   * <summary>
   * Creates a new lift request from one journey to another.
   * </summary>
   * <param name="from_journey_id">The current user's journey to ride or drive.</param>
   * <param name="to_journey_id">The counterparty's journey being requested.</param>
   * <param name="message">Optional introductory message attached to the request.</param>
   * <returns>`{ id }` of the new lift request.</returns>
   * <remarks>
   * Subject to a daily quota visible via `listLiftRequests`. Throws
   * if the user has been blocked by the counterparty or if either
   * journey is inactive.
   * </remarks>
   */
  createLiftRequest: (from_journey_id: number, to_journey_id: number, message?: string) =>
    request<{ id: number }>('lift-requests', { method: 'POST', body: JSON.stringify({ from_journey_id, to_journey_id, message }) }),
  /**
   * <summary>
   * Transitions a lift request to a new status.
   * </summary>
   * <param name="id">Lift request id.</param>
   * <param name="status">Target status: `accepted`, `declined` or `cancelled`.</param>
   * <returns>The updated lift request row.</returns>
   * <remarks>
   * The server enforces who is allowed to make which transition;
   * for instance only the recipient can accept, and only either
   * party can cancel an accepted booking.
   * </remarks>
   */
  updateLiftRequest: (id: number, status: 'accepted' | 'declined' | 'cancelled') =>
    request<{ request: LiftRequest }>(`lift-requests/${id}`, { method: 'PATCH', body: JSON.stringify({ status }) }),

  /**
   * <summary>
   * Lists messages in a single lift request chat thread.
   * </summary>
   * <param name="liftRequestId">Id of the parent lift request.</param>
   * <returns>
   * The list of messages, the user's remaining daily send quota and
   * the maximum allowed message body length.
   * </returns>
   */
  listMessages: (liftRequestId: number) =>
    request<{ messages: Message[]; remaining_today: number; max_chars: number }>(
      `messages?lift_request_id=${liftRequestId}`
    ),
  /**
   * <summary>
   * Sends a chat message in a lift request thread.
   * </summary>
   * <param name="liftRequestId">Id of the parent lift request.</param>
   * <param name="body">Plain text message body. Must not exceed `max_chars`.</param>
   * <returns>
   * The created Message plus the updated `remaining_today` quota.
   * </returns>
   * <remarks>
   * Subject to a daily send quota. Throws when the quota is exhausted
   * or when the lift request is in a status that disallows chat.
   * </remarks>
   */
  sendMessage: (liftRequestId: number, body: string) =>
    request<{ message: Message & { remaining_today: number } }>('messages', {
      method: 'POST', body: JSON.stringify({ lift_request_id: liftRequestId, body }),
    }),
  /**
   * <summary>
   * Returns per thread unread counts and last activity for every
   * lift request the user is part of.
   * </summary>
   * <returns>The MessageSummary map plus the user's quota fields.</returns>
   * <remarks>
   * Used by the Activity tab to render badges without one fetch per
   * thread.
   * </remarks>
   */
  messagesSummary: () =>
    request<{ summary: MessageSummary; remaining_today: number; max_chars: number }>('messages/summary'),

  /**
   * <summary>
   * Lists the users blocked by the current user.
   * </summary>
   * <returns>An array of blocked user rows including the reason and timestamp.</returns>
   */
  listBlocks: () =>
    request<{ blocks: Array<{ id: number; display_name: string; avatar_url: string | null; reason: string | null; created_at: string }> }>('blocks'),
  /**
   * <summary>
   * Blocks another user. Hides their journeys from the matcher and
   * stops them being able to message or request lifts.
   * </summary>
   * <param name="user_id">User id of the person to block.</param>
   * <param name="reason">Optional free text reason recorded on the block.</param>
   * <returns>`{ ok: true }` on success.</returns>
   */
  blockUser: (user_id: number, reason?: string) =>
    request<{ ok: true }>('blocks', { method: 'POST', body: JSON.stringify({ user_id, reason }) }),
  /**
   * <summary>
   * Removes an existing block.
   * </summary>
   * <param name="user_id">User id previously blocked.</param>
   * <returns>`{ unblocked: boolean }`.</returns>
   */
  unblockUser: (user_id: number) =>
    request<{ unblocked: boolean }>(`blocks/${user_id}`, { method: 'DELETE' }),
  /**
   * <summary>
   * Reports another user to the operators for moderation.
   * </summary>
   * <param name="user_id">User id being reported.</param>
   * <param name="reason">Short reason code or category.</param>
   * <param name="detail">Optional free text detail.</param>
   * <returns>`{ id }` of the created report row.</returns>
   */
  reportUser: (user_id: number, reason: string, detail?: string) =>
    request<{ id: number }>('reports', { method: 'POST', body: JSON.stringify({ user_id, reason, detail }) }),

  /**
   * <summary>
   * Lists every group the current user belongs to.
   * </summary>
   * <returns>Compact GroupSummary rows including today and tomorrow's rotation slots.</returns>
   */
  listGroups: () => request<{ groups: GroupSummary[] }>('groups'),
  /**
   * <summary>
   * Fetches the full detail for a group, including members and the
   * weekly rotation.
   * </summary>
   * <param name="id">Group id.</param>
   * <returns>A GroupDetail row.</returns>
   */
  getGroup:   (id: number) => request<GroupDetail>(`groups/${id}`),
  /**
   * <summary>
   * Creates a new group with the current user as the only member
   * and creator.
   * </summary>
   * <param name="g">
   * Group fields: name, type, schedule, destination and the
   * creator's home pickup pin.
   * </param>
   * <returns>`{ id }` of the new group.</returns>
   */
  createGroup: (g: {
    name: string;
    type?: 'school_run' | 'other';
    start_time: string;
    days_mask: number;
    dest_lat?: number | null;
    dest_lng?: number | null;
    dest_label?: string | null;
    home_lat?: number | null;
    home_lng?: number | null;
    home_label?: string | null;
  }) => request<{ id: number }>('groups', { method: 'POST', body: JSON.stringify(g) }),
  /**
   * <summary>
   * Patches the editable fields of a group. Creator only.
   * </summary>
   * <param name="id">Group id.</param>
   * <param name="patch">Partial group settings: name, schedule, destination.</param>
   * <returns>The updated GroupDetail row.</returns>
   */
  updateGroup: (id: number, patch: Partial<{
    name: string;
    start_time: string;
    days_mask: number;
    dest_lat: number | null;
    dest_lng: number | null;
    dest_label: string | null;
  }>) => request<GroupDetail>(`groups/${id}`, { method: 'PATCH', body: JSON.stringify(patch) }),
  /**
   * <summary>
   * Deletes a group. Creator only.
   * </summary>
   * <param name="id">Group id.</param>
   * <returns>`{ deleted: boolean }`.</returns>
   */
  deleteGroup: (id: number) =>
    request<{ deleted: boolean }>(`groups/${id}`, { method: 'DELETE' }),
  /**
   * <summary>
   * Replaces the group's weekly rotation in a single call.
   * </summary>
   * <param name="id">Group id.</param>
   * <param name="rotation">Day of week (1 Monday to 7 Sunday) to driver `user_id`.</param>
   * <returns>The updated GroupDetail row.</returns>
   * <remarks>
   * Sent as `PUT` because the call is a full replace, not a patch.
   * Drivers referenced in `rotation` must currently be members of
   * the group.
   * </remarks>
   */
  setGroupRotation: (id: number, rotation: Record<number, number>) =>
    request<GroupDetail>(`groups/${id}/rotation`, { method: 'PUT', body: JSON.stringify({ rotation }) }),
  /**
   * <summary>
   * Updates the current user's per group membership fields, such as
   * their home pickup pin for that group.
   * </summary>
   * <param name="id">Group id.</param>
   * <param name="patch">Home pickup coordinates and label.</param>
   * <returns>The updated GroupDetail row.</returns>
   */
  updateMyGroupMembership: (id: number, patch: { home_lat?: number | null; home_lng?: number | null; home_label?: string | null }) =>
    request<GroupDetail>(`groups/${id}/me`, { method: 'PATCH', body: JSON.stringify(patch) }),
  /**
   * <summary>
   * Removes the current user from a group.
   * </summary>
   * <param name="id">Group id.</param>
   * <returns>`{ left: boolean }`.</returns>
   * <remarks>
   * The creator cannot leave their own group; they must delete it or
   * transfer ownership (the latter is not yet exposed).
   * </remarks>
   */
  leaveGroup: (id: number) =>
    request<{ left: boolean }>(`groups/${id}/me`, { method: 'DELETE' }),
  /**
   * <summary>
   * Joins a group using its invite code.
   * </summary>
   * <param name="code">Invite code, typically taken from a `?join=CODE` URL.</param>
   * <returns>`{ id }` of the joined group.</returns>
   * <remarks>
   * Consumed by `App.tsx` after login to honour an invite link the
   * user clicked before signing in.
   * </remarks>
   */
  joinGroupByCode: (code: string) =>
    request<{ id: number }>('groups/join', { method: 'POST', body: JSON.stringify({ code }) }),

  /**
   * <summary>
   * Fetches the public changelog and roadmap surfaced on the About
   * page.
   * </summary>
   * <returns>Current product version and the list of public Issue rows.</returns>
   * <remarks>
   * Unauthenticated. Only rows with `is_public=1` are returned. The
   * About page's Improvements section consumes this endpoint.
   * </remarks>
   */
  listIssues: () =>
    request<{ version: string; issues: Issue[] }>('issues'),
};
