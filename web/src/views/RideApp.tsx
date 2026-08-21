import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { api, Journey, LiftRequest, Match, MessageSummary, User } from '../api';
import { JourneyMap } from '../components/JourneyMap';
import { JourneyEditor, DraftJourney } from '../components/JourneyEditor';
import { JourneyCard } from '../components/JourneyCard';
import { AppBar } from '../components/AppBar';
import { VerifyBanner } from '../components/VerifyBanner';
import { JourneySkeletonRow } from '../components/Skeleton';
import { ProfileView } from './ProfileView';
import { GroupsView } from './GroupsView';
import { MessagesModal } from './MessagesModal';
import { MatchesModal } from './MatchesModal';
import { LegalView } from './LegalView';
import { AboutView } from './AboutView';
import { AppFooter } from '../components/AppFooter';
import { ConfirmModal } from '../components/ConfirmModal';
import { fetchRoute } from '../lib/osrm';

/**
 * <summary>
 * What the user has currently selected in the sidebar and on the map.
 * </summary>
 * <remarks>
 * "journey" means an existing saved journey is highlighted and its
 * matches and pins are on the map. "editor" means the journey editor is
 * open with a draft (either new or edited). null means nothing is
 * selected and the map shows only the unselected journey overview.
 * </remarks>
 */
type Selection = { type: 'journey'; id: number } | { type: 'editor' } | null;

/**
 * <summary>
 * Which top level view is rendered inside the app shell. Mutually
 * exclusive: only one of these renders at a time.
 * </summary>
 */
type View      = 'map' | 'profile' | 'groups';

/**
 * <summary>
 * Main application shell for signed in users. Renders the AppBar across
 * the top, the journey list on the left, the map and journey editor on
 * the right, plus every modal and confirmation dialog the logged in
 * experience needs. Hosts the bulk of the app's interactive state.
 * </summary>
 * <param name="user">The signed in user, used to seed local state.</param>
 * <param name="onLogout">Called after the user signs out so the parent
 * App can swap back to the auth screen.</param>
 * <param name="onUserUpdated">Optional callback invoked whenever the
 * local user state changes (e.g. after a profile save). Lets the parent
 * App keep its top level user copy in sync.</param>
 * <remarks>
 * Owns the journeys, matches, lift requests, and message summary state,
 * polling all of them on a 30 second interval while the tab is visible.
 * Polling is paused when the tab is hidden to keep mobile data and
 * battery usage reasonable.
 *
 * Match queries use <see cref="AbortController"/> so that quickly
 * switching journeys or dragging the radius slider does not let an older
 * fetch overwrite a newer one when it lands second.
 *
 * Mutation handlers (request, accept, decline, cancel) dedupe by id via
 * <see cref="useRef"/> so rapid clicks do not queue duplicate API calls.
 *
 * Pin moves on a journey with accepted connections are gated by a
 * confirmation dialog because every counterparty receives a "pins moved"
 * note in their chat thread.
 * </remarks>
 */
export function RideApp({ user: initialUser, onLogout, onUserUpdated }: { user: User; onLogout: () => void; onUserUpdated?: (u: User) => void }) {
  const [user, _setUser]                    = useState<User>(initialUser);
  /**
   * <summary>
   * Local setter that mirrors the user state up to the parent App via
   * <see cref="onUserUpdated"/> so the top level copy stays in sync.
   * </summary>
   * <param name="u">The new user record.</param>
   */
  const setUser = (u: User) => { _setUser(u); onUserUpdated?.(u); };
  const [view, setView]                     = useState<View>('map');
  // Deep-link target inside the profile page. Set when the user clicks the
  // "Open Profile" button on an Away-mode notice so ProfileView can scroll
  // them straight to the Away checkbox instead of dumping them at the top.
  // Cleared once consumed (and on any other navigation) so a later top-level
  // profile click doesn't accidentally re-focus the away control.
  const [profileFocus, setProfileFocus] = useState<'away' | null>(null);
  /**
   * <summary>
   * Switches to the Profile view, optionally requesting a deep link
   * focus so a specific control gets scrolled to and pulsed on mount.
   * </summary>
   * <param name="focus">Which control to highlight. Defaults to null for
   * a normal profile open.</param>
   */
  function openProfile(focus: 'away' | null = null) {
    setProfileFocus(focus);
    setView('profile');
  }
  // Transient "Journey saved" confirmation. Set after a successful save,
  // auto-clears via the effect below. Same .toast style as the verify-email
  // banner so all transient OK/error feedback in the app looks the same.
  const [saveToast, setSaveToast] = useState<string | null>(null);
  useEffect(() => {
    if (!saveToast) return;
    const t = setTimeout(() => setSaveToast(null), 2200);
    return () => clearTimeout(t);
  }, [saveToast]);
  const [journeys, setJourneys]             = useState<Journey[]>([]);
  const [journeysLoading, setJourneysLoading] = useState(true);
  // Non-null when the last journey fetch failed. Distinguishes "you have no
  // journeys" from "we couldn't find out whether you have journeys".
  const [journeysError, setJourneysError]   = useState<string | null>(null);
  const [selection, setSelection]           = useState<Selection>(null);
  const [matches, setMatches]               = useState<Match[]>([]);
  // For a driver (offer) journey the matches list is intentionally empty;
  // this holds the anonymised count of people looking for a lift instead.
  const [demandCount, setDemandCount]       = useState(0);
  const [matchesLoading, setMatchesLoading] = useState(false);
  const [matchesError, setMatchesError]     = useState<string | null>(null);
  const [matchOverride, setMatchOverride]   = useState<{ radius_m: number; window_min: number } | null>(null);
  const [draft, setDraft]                   = useState<DraftJourney | null>(null);
  // Camera target the editor can request (e.g. "centre on the Start pin").
  // Stored as a tagged tuple so passing the same coords twice still re-fires
  // the FlyTo effect downstream, bumping the tick on each call is the trick.
  const [editorFocus, setEditorFocus]       = useState<{ ll: [number, number]; tick: number } | null>(null);
  const [sidebarOpen, setSidebarOpen]       = useState(() => window.innerWidth >= 768);
  const [messagesOpen, setMessagesOpen]     = useState<{ liftRequestId?: number } | null>(null);
  const [matchesModal, setMatchesModal]     = useState<{ journey: Journey; radiusM: number; windowMin: number } | null>(null);
  const [legalPage, setLegalPage]           = useState<'terms' | 'privacy' | null>(null);
  const [aboutOpen, setAboutOpen]           = useState(false);
  const [confirmLogout, setConfirmLogout]   = useState(false);
  // When the user moves a pin on a journey that has accepted connections,
  // we hold the draft here until they confirm, they need to know that
  // every counterparty will get a "pins moved" notice in their chat.
  const [pendingPinChange, setPendingPinChange] = useState<{ draft: DraftJourney; passengerCount: number } | null>(null);
  const [requests, setRequests]             = useState<LiftRequest[]>([]);
  const [requestLimits, setRequestLimits]   = useState<{ remaining_today: number; max_chars: number }>({ remaining_today: 20, max_chars: 280 });
  const [messageSummary, setMessageSummary] = useState<MessageSummary>({});
  const routeAbortRef = useRef<AbortController | null>(null);

  /**
   * <summary>
   * Re-fetches the user's journey list and replaces the local state.
   * Toggles the loading skeleton around the fetch.
   * </summary>
   */
  const refresh = useCallback(async () => {
    setJourneysLoading(true);
    try {
      const r = await api.listJourneys();
      setJourneys(r.journeys);
      setJourneysError(null);
    } catch (e) {
      // Record it rather than throwing on. An uncaught rejection here left
      // the sidebar rendering "No journeys yet" to a user who has journeys,
      // with nothing to click and no hint that the fetch had failed.
      setJourneysError((e as Error)?.message || 'Could not load your journeys.');
    } finally {
      setJourneysLoading(false);
    }
  }, []);

  /**
   * <summary>
   * Re-fetches the user's lift requests plus the current daily quota
   * and message length limit. Silently ignores errors so the polling
   * timer cannot trip an error banner.
   * </summary>
   */
  const refreshRequests = useCallback(async () => {
    try {
      const r = await api.listLiftRequests();
      setRequests(r.requests);
      setRequestLimits({ remaining_today: r.remaining_today, max_chars: r.max_chars });
    } catch { /* ignore */ }
  }, []);

  /**
   * <summary>
   * Re-fetches the message summary (latest body and unread counts per
   * thread). Silently ignores errors so polling failures stay quiet.
   * </summary>
   */
  const refreshMessages = useCallback(async () => {
    try {
      const r = await api.messagesSummary();
      setMessageSummary(r.summary);
    } catch { /* ignore */ }
  }, []);

  useEffect(() => { refresh(); refreshRequests(); refreshMessages(); }, [refresh, refreshRequests, refreshMessages]);

  // Light polling for new messages while the app is open. Skip when tab is hidden.
  useEffect(() => {
    const id = setInterval(() => {
      if (document.visibilityState === 'visible') {
        refreshMessages();
        refreshRequests();
      }
    }, 30_000);
    return () => clearInterval(id);
  }, [refreshMessages, refreshRequests]);

  // Index pending/accepted requests by the OTHER journey id, so MatchList can
  // tell at a glance whether each match already has a request in flight.
  const requestsByMatchJourney = useMemo(() => {
    const m = new Map<number, LiftRequest>();
    for (const r of requests) {
      if (r.status === 'pending' || r.status === 'accepted' || r.status === 'declined') {
        m.set(r.their_journey.id, r);
      }
    }
    return m;
  }, [requests]);

  const pendingReceived = requests.filter(r => r.direction === 'received' && r.status === 'pending').length;

  // Reset radius override when the selected journey changes.
  useEffect(() => { setMatchOverride(null); }, [selection]);

  useEffect(() => {
    if (selection?.type !== 'journey') { setMatches([]); setDemandCount(0); setMatchesError(null); return; }
    setMatchesLoading(true);
    setMatchesError(null);
    const sel = journeys.find(j => j.id === selection.id);
    const radius = matchOverride?.radius_m   ?? sel?.radius_m   ?? user.default_radius_m;
    const window = matchOverride?.window_min ?? sel?.window_min ?? user.default_window_min;
    // AbortController guards against an older fetch landing AFTER a
    // newer one. Without this, quickly switching between journeys or
    // dragging the radius slider can show stale results from a slower
    // request that finished second.
    const ctrl = new AbortController();
    api.matches(selection.id, radius, window, ctrl.signal)
      .then(r => { if (!ctrl.signal.aborted) { setMatches(r.matches); setDemandCount(r.demand_count ?? 0); } })
      .catch(e => {
        if (ctrl.signal.aborted) return;
        setMatches([]);
        setDemandCount(0);
        setMatchesError((e as Error).message || 'Could not load matches');
      })
      .finally(() => { if (!ctrl.signal.aborted) setMatchesLoading(false); });
    return () => ctrl.abort();
  }, [selection, journeys, user.default_radius_m, user.default_window_min, matchOverride]);

  // Apply the user's theme preference to the document root so it persists across reloads.
  useEffect(() => {
    const resolved = user.theme === 'auto'
      ? (matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark')
      : user.theme;
    document.documentElement.dataset.theme = resolved;
  }, [user.theme]);

  // Whenever draft has both pins AND routing is enabled, (re)fetch a driving
  // route from OSRM. Also re-runs when the user drags a pin.
  useEffect(() => {
    if (!draft || !draft.start || !draft.end) return;
    routeAbortRef.current?.abort();

    if (!draft.use_routing) {
      setDraft(d => d ? { ...d, route_wkt: null, route_distance_m: null, route_duration_s: null } : d);
      return;
    }

    const ctrl = new AbortController();
    routeAbortRef.current = ctrl;
    setDraft(d => d ? { ...d, route_wkt: undefined, route_distance_m: null, route_duration_s: null } : d);

    fetchRoute(draft.start, draft.end, ctrl.signal)
      .then(r => {
        if (ctrl.signal.aborted) return;
        setDraft(d => {
          if (!d || !d.start || !d.end) return d;
          return r
            ? { ...d, route_wkt: r.wkt, route_distance_m: r.distance_m, route_duration_s: r.duration_s }
            : { ...d, route_wkt: null, route_distance_m: null, route_duration_s: null };
        });
      })
      .catch(err => {
        if (err?.name === 'AbortError') return;
        setDraft(d => d ? { ...d, route_wkt: null, route_distance_m: null, route_duration_s: null } : d);
      });
  }, [draft?.use_routing, draft?.start?.lat, draft?.start?.lng, draft?.end?.lat, draft?.end?.lng]);

  /**
   * <summary>
   * Posts to the logout endpoint, then hands control back to the parent
   * App which clears the session and shows the auth screen.
   * </summary>
   */
  async function logout() {
    // Clear client state even if the POST fails (offline, 500). The
    // cookie may survive server-side, but stranding the user on a
    // screen they've asked to leave is the worse outcome, and the
    // session is re-validated on next load anyway.
    try {
      await api.logout();
    } catch (e) {
      console.error('[SwiftLift] logout request failed', e);
    } finally {
      onLogout();
    }
  }

  /**
   * <summary>
   * Opens the journey editor with a fresh draft seeded from the user's
   * profile defaults (radius, time window, age and sex filter), the
   * canonical 08:00 weekday start, and the offer-direction default.
   * </summary>
   * <remarks>
   * Defaults to 1 spare seat regardless of how many seats the user's
   * car has. Sharing the whole car is the exception, not the rule, and
   * the Spare seats field is right there in the editor for anyone who
   * wants to bump it.
   *
   * On mobile, closes the sidebar so the map is fully visible while the
   * user drops pins.
   * </remarks>
   */
  function newJourney() {
    setDraft({
      label: '', start: null, end: null,
      start_time: '08:00', days_mask: 0b0011111, direction: 'offer',
      // Default a new journey to 1 spare seat regardless of how many seats
      // the user's car has, sharing the whole car is the exception, not the
      // rule. The Spare seats field is right there in the editor for anyone
      // who wants to bump it.
      seats: 1,
      radius_m:   user.default_radius_m,
      window_min: user.default_window_min,
      use_routing: false,
      // Seed age + sex filter from the user's profile defaults.
      age_min:    user.age_min,
      age_max:    user.age_max,
      pref_sex:   user.pref_sex,
    });
    setSelection({ type: 'editor' });
    setView('map');
    setSidebarOpen(false);
  }

  /**
   * <summary>
   * Opens the journey editor on an existing journey, copying every field
   * into a draft so the user can edit without immediately mutating the
   * saved record.
   * </summary>
   * <param name="j">The journey to edit.</param>
   * <remarks>
   * The <c>use_routing</c> flag is reconstructed from whether the journey
   * has a saved route WKT, since the API does not persist the toggle
   * itself.
   * </remarks>
   */
  function editJourney(j: Journey) {
    setDraft({
      id: j.id,
      label: '',
      start: { lat: j.start_lat, lng: j.start_lng },
      end:   { lat: j.end_lat,   lng: j.end_lng },
      start_time: j.start_time,
      days_mask: j.days_mask,
      direction: j.direction,
      seats: j.seats || 1,
      radius_m:   j.radius_m,
      window_min: j.window_min,
      use_routing: !!j.route_wkt,
      route_wkt: j.route_wkt,
      // Per-journey age + sex filter, load whatever was saved on this row.
      age_min:    j.age_min,
      age_max:    j.age_max,
      pref_sex:   j.pref_sex,
    });
    setSelection({ type: 'editor' });
    setView('map');
    setSidebarOpen(false);
  }

  /**
   * <summary>
   * Returns the number of accepted connections that would be notified if
   * the given draft is saved, because the start or end pin has actually
   * moved. New (unsaved) journeys always return 0 since they have no
   * connections yet.
   * </summary>
   * <param name="d">The draft about to be saved.</param>
   * <returns>The accepted seat count from the original journey, or 0 if
   * the pins did not move enough to count.</returns>
   * <remarks>
   * The pin moved threshold is roughly 1e-5 degrees in either direction,
   * which is small enough to ignore floating point round trips but large
   * enough to trigger on any real drag.
   * </remarks>
   */
  function pinMoveNotificationCount(d: DraftJourney): number {
    if (d.id === undefined || !d.start || !d.end) return 0;
    const original = journeys.find(j => j.id === d.id);
    if (!original) return 0;
    const startMoved =
      Math.abs(d.start.lat - original.start_lat) > 1e-5 ||
      Math.abs(d.start.lng - original.start_lng) > 1e-5;
    const endMoved =
      Math.abs(d.end.lat   - original.end_lat)   > 1e-5 ||
      Math.abs(d.end.lng   - original.end_lng)   > 1e-5;
    if (!startMoved && !endMoved) return 0;
    return original.seats_taken ?? 0;
  }

  /**
   * <summary>
   * Persists a draft journey, either as a new record or an update. After
   * a successful save the journey list is refreshed, the saved journey
   * is re-selected, and a transient "saved" toast is shown.
   * </summary>
   * <param name="d">The draft to save. Must have a non empty label and
   * both pins set, otherwise the call is a no op.</param>
   * <remarks>
   * If this is an edit that would move pins on a journey with accepted
   * connections, the save is held until the user confirms via the
   * <see cref="pendingPinChange"/> dialog. They get a clear "X people
   * will be notified" message, which is computed locally for instant
   * feedback. The server re-checks and only sends notifications if the
   * pins genuinely moved.
   *
   * Payload values are clamped server side too, but the local clamp on
   * seats, radius and window keeps obvious bad input from ever
   * reaching the wire.
   *
   * The refresh runs before the re-select so the new or updated journey
   * is in the list when <see cref="JourneyCard"/> tries to render it.
   * Without that ordering the user would briefly see an empty state
   * for one tick.
   * </remarks>
   */
  async function saveDraft(d: DraftJourney) {
    // Guard: both pins must be set. Label was previously required here too,
    // but the field has been removed from the UI; an empty label string is
    // accepted by the API and stored verbatim.
    if (!d.start || !d.end) return;
    // If this is an edit that would relocate pins for users who've already
    // accepted a lift, hold the save until the driver confirms. They get
    // a clear "X people will be notified" message, done client-side so
    // the prompt is local + instant; the server still re-checks and only
    // sends notifications if the pins genuinely moved.
    const notifyCount = pinMoveNotificationCount(d);
    if (notifyCount > 0 && !pendingPinChange) {
      setPendingPinChange({ draft: d, passengerCount: notifyCount });
      return;
    }
    const payload = {
      // The free-text label field was removed from the UI; journeys are
      // identified by their start time + days + direction instead. Send
      // an empty string so the API still accepts the column and so any
      // existing label on the row gets wiped on the next save.
      label: '',
      start_lat: d.start.lat, start_lng: d.start.lng,
      end_lat:   d.end.lat,   end_lng:   d.end.lng,
      start_time: d.start_time,
      days_mask: d.days_mask,
      direction: d.direction,
      seats: d.direction === 'offer' ? Math.max(1, Math.min(8, d.seats || 1)) : 1,
      radius_m:   Math.max(100, Math.min(2000,  d.radius_m)),
      window_min: Math.max(5,   Math.min(240,   d.window_min)),
      route_wkt: d.route_wkt ?? null,
      age_min:    d.age_min,
      age_max:    d.age_max,
      pref_sex:   d.pref_sex,
    };
    // Save and capture the id of the journey we just touched so we can
    // re-select it after refresh. For an edit we already know it; for a
    // create we read it from the API response.
    let savedId: number;
    try {
      if (d.id !== undefined) {
        await api.updateJourney(d.id, payload);
        savedId = d.id;
      } else {
        const r = await api.createJourney(payload);
        savedId = r.id;
      }
    } catch (e) {
      // Keep the editor open with the user's input intact so they can
      // retry. Previously this rejected unhandled: the editor stayed put,
      // nothing appeared, and Save looked simply dead.
      showMutationError(d.id !== undefined ? 'update this journey' : 'save this journey', e);
      return;
    }
    setDraft(null);
    setPendingPinChange(null);
    // Refresh BEFORE selecting so the new/updated journey is in `journeys`
    // when JourneyCard tries to render it, otherwise selecting an id that
    // isn't in the list yet would render nothing for a tick and the user
    // would briefly see the empty state.
    await refresh();
    // Re-select the journey we just saved so the pins (and route line) stay
    // visible on the map. Previously we cleared selection here, which
    // dropped the editor, and the pins with it, leaving the user to
    // hunt for their newly-saved card and click it again to see anything.
    setSelection({ type: 'journey', id: savedId });
    setSaveToast(d.id !== undefined ? 'Journey updated' : 'Journey saved');
  }

  /**
   * <summary>
   * Deletes a journey, clears the selection if the deleted one was
   * selected, and refreshes both the journey list and lift requests so
   * any request rows tied to it disappear from the UI.
   * </summary>
   * <param name="id">The journey id to delete.</param>
   */
  async function remove(id: number) {
    try {
      await api.deleteJourney(id);
    } catch (e) {
      showMutationError('delete this journey', e);
      return;
    }
    if (selection?.type === 'journey' && selection.id === id) setSelection(null);
    await refresh();
    await refreshRequests();
  }

  // Track in-flight mutations so rapid clicks don't queue duplicate API calls
  // (e.g. spamming Accept while the first one is still in flight). The ref
  // dedupes per-request-id; a parallel set holds the journey ids currently
  // mid-createLiftRequest so the same journey can't be requested twice.
  const inflightStatusIds = useRef<Set<number>>(new Set());
  const inflightRequestJourneys = useRef<Set<number>>(new Set());

  /**
   * <summary>
   * Surfaces a mutation failure to the user as a native alert.
   * </summary>
   * <param name="action">Verb phrase describing what failed, e.g.
   * "accept request".</param>
   * <param name="e">The thrown error, may be undefined or non Error.</param>
   * <remarks>
   * Native alert is a deliberately blunt tool here. These are rare,
   * irrecoverable failures (network down, server 500) where forcing a
   * dismiss is preferable to a banner the user might miss.
   * </remarks>
   */
  function showMutationError(action: string, e: unknown) {
    const msg = (e as Error)?.message || 'Unknown error';
    alert(`Couldn't ${action}: ${msg}`);
  }

  /**
   * <summary>
   * Sends a lift request from the currently selected journey to the
   * given match journey, then refreshes the request list and journey
   * list so seats taken counters and inline status pills update.
   * </summary>
   * <param name="matchJourneyId">The OTHER journey being requested.</param>
   * <param name="message">Optional message body. Empty string is treated
   * as no message at all.</param>
   * <remarks>
   * Dedupe is by match journey id so spamming the Request button while
   * the first call is in flight is safe. The ref based set means the
   * dedupe survives re renders without bringing them on.
   * </remarks>
   */
  async function requestLift(matchJourneyId: number, message: string) {
    if (selection?.type !== 'journey') return;
    if (inflightRequestJourneys.current.has(matchJourneyId)) return;
    inflightRequestJourneys.current.add(matchJourneyId);
    try {
      await api.createLiftRequest(selection.id, matchJourneyId, message || undefined);
      await Promise.all([refreshRequests(), refresh()]);
    } catch (e) {
      showMutationError('send request', e);
    } finally {
      inflightRequestJourneys.current.delete(matchJourneyId);
    }
  }
  /**
   * <summary>
   * Updates the status of an existing lift request. Shared backbone for
   * <see cref="acceptRequest"/>, <see cref="declineRequest"/> and
   * <see cref="cancelRequest"/>.
   * </summary>
   * <param name="id">Lift request id.</param>
   * <param name="status">The new status to write.</param>
   * <param name="label">Human readable verb phrase used in any error
   * alert (e.g. "accept request").</param>
   */
  async function setRequestStatus(id: number, status: 'accepted' | 'declined' | 'cancelled', label: string) {
    if (inflightStatusIds.current.has(id)) return;
    inflightStatusIds.current.add(id);
    try {
      await api.updateLiftRequest(id, status);
      await Promise.all([refreshRequests(), refresh()]);
    } catch (e) {
      showMutationError(label, e);
    } finally {
      inflightStatusIds.current.delete(id);
    }
  }
  /**
   * <summary>Accepts a pending lift request.</summary>
   * <param name="id">Lift request id.</param>
   */
  const acceptRequest  = (id: number) => setRequestStatus(id, 'accepted',  'accept request');
  /**
   * <summary>Declines a pending lift request.</summary>
   * <param name="id">Lift request id.</param>
   */
  const declineRequest = (id: number) => setRequestStatus(id, 'declined',  'decline request');
  /**
   * <summary>Cancels a lift request the current user sent.</summary>
   * <param name="id">Lift request id.</param>
   */
  const cancelRequest  = (id: number) => setRequestStatus(id, 'cancelled', 'cancel request');
  /**
   * <summary>
   * Callback fired after the user blocks someone from within a journey
   * card or thread. Refreshes the request list, message summary, and
   * the matches for the currently selected journey so the blocked user
   * disappears immediately.
   * </summary>
   */
  async function onUserBlocked() {
    await Promise.all([refreshRequests(), refreshMessages()]);
    if (selection?.type === 'journey') {
      // Refetch with the parameters the list is *currently* showing: any
      // expanded-search override first, else the journey's own saved
      // radius/window. Using the profile defaults here silently resized
      // the result set, a 2 km journey would snap back to a 500 m
      // default the moment the user blocked somebody.
      const j = journeys.find(x => x.id === selection.id) ?? null;
      const radiusM   = matchOverride?.radius_m   ?? j?.radius_m   ?? user.default_radius_m;
      const windowMin = matchOverride?.window_min ?? j?.window_min ?? user.default_window_min;
      try {
        const r = await api.matches(selection.id, radiusM, windowMin);
        setMatches(r.matches);
        setDemandCount(r.demand_count ?? 0);
      } catch { /* the periodic match refresh will retry */ }
    }
  }

  const selectedJourney = selection?.type === 'journey'
    ? journeys.find(j => j.id === selection.id) ?? null
    : null;

  /**
   * <summary>
   * Selects the given journey so its pins, route, and matches show on
   * the map. On mobile, also closes the sidebar so the map is visible
   * once the journey is picked.
   * </summary>
   * <param name="id">The journey to select.</param>
   */
  function selectJourney(id: number) {
    setSelection({ type: 'journey', id });
    if (window.innerWidth < 768) setSidebarOpen(false);
  }

  /**
   * <summary>
   * Handler for the notifications dropdown. Jumps the user to wherever
   * they most likely want to act on the given lift request.
   * </summary>
   * <param name="liftRequestId">The id of the request the user clicked.</param>
   * <remarks>
   * Accepted requests open the Messages modal scrolled to that thread,
   * since the next action is almost always to send a message. Pending
   * received requests instead select the relevant journey on the map
   * and open the sidebar so the user can accept or decline from the
   * card. Unknown ids are a silent no op.
   * </remarks>
   */
  function jumpToRequest(liftRequestId: number) {
    const r = requests.find(x => x.id === liftRequestId);
    if (!r) return;
    if (r.status === 'accepted') {
      setMessagesOpen({ liftRequestId });
      return;
    }
    setView('map');
    selectJourney(r.my_journey.id);
    setSidebarOpen(true);
  }

  return (
    <div className={`app ${sidebarOpen ? 'sidebar-open' : ''} view-${view}`}>
      <AppBar
        user={user}
        view={view}
        requests={requests}
        messageSummary={messageSummary}
        sidebarOpen={sidebarOpen}
        onToggleSidebar={() => setSidebarOpen(v => !v)}
        onOpenProfile={() => openProfile()}
        onOpenMap={() => setView('map')}
        onOpenGroups={() => setView('groups')}
        onOpenMessages={() => setMessagesOpen({})}
        onLogout={() => setConfirmLogout(true)}
        onJumpToRequest={jumpToRequest}
      />

      {legalPage ? (
        <LegalView which={legalPage} onClose={() => setLegalPage(null)} />
      ) : aboutOpen ? (
        <AboutView
          onClose={() => setAboutOpen(false)}
          onShowLegal={page => setLegalPage(page)}
        />
      ) : view === 'profile' ? (
        <ProfileView
          user={user}
          focus={profileFocus}
          onClose={() => setView('map')}
          onUpdated={u => setUser(u)}
          onDeleted={onLogout}
          // Must go through the same confirm + api.logout() flow as the
          // AppBar. Handing ProfileView the parent's raw onLogout only
          // cleared client state and left the session cookie valid, so a
          // reload signed the "signed out" user straight back in.
          onLogout={() => setConfirmLogout(true)}
        />
      ) : view === 'groups' ? (
        <GroupsView myUserId={user.id} onClose={() => setView('map')} />
      ) : (
        <div className="app-body">
          <aside className="sidebar" aria-hidden={!sidebarOpen}>
            {!user.email_verified && <VerifyBanner email={user.email} forceAt={user.verify_force_at} />}
            <button className="primary" onClick={newJourney}>+ New journey</button>

            {pendingReceived > 0 && (
              <div className="inbox-banner">
                {pendingReceived} new lift request{pendingReceived === 1 ? '' : 's'} waiting for you
              </div>
            )}

            {journeysLoading && journeys.length === 0 && (
              <div className="journey-cards">
                <JourneySkeletonRow /><JourneySkeletonRow />
              </div>
            )}

            {!journeysLoading && journeysError && journeys.length === 0 && (
              <div className="empty">
                <div className="empty-headline">Couldn't load your journeys</div>
                <div className="empty-body">
                  {journeysError}
                  <div style={{ marginTop: '.6rem' }}>
                    <button type="button" className="small" onClick={() => { void refresh(); }}>
                      Try again
                    </button>
                  </div>
                </div>
              </div>
            )}

            {!journeysLoading && !journeysError && journeys.length === 0 && (
              <div className="empty">
                <div className="empty-headline">No journeys yet</div>
                <div className="empty-body">
                  Tap <b>+ New journey</b> and drop a pin where you start your day,
                  then another for where you're heading.
                </div>
              </div>
            )}

            <div className="journey-cards">
              {journeys.map(j => {
                const isSelected     = selection?.type === 'journey' && selection.id === j.id;
                const journeyMatches = isSelected ? matches : [];
                const journeyDemand  = isSelected ? demandCount : 0;
                const journeyReqs    = requests.filter(r => r.my_journey.id === j.id);
                return (
                  <JourneyCard
                    key={j.id}
                    journey={j}
                    selected={isSelected}
                    matches={journeyMatches}
                    demandCount={journeyDemand}
                    matchesLoading={isSelected && matchesLoading}
                    matchesError={isSelected ? matchesError : null}
                    searchRadiusM={matchOverride?.radius_m   ?? j.radius_m}
                    searchWindowMin={matchOverride?.window_min ?? j.window_min}
                    onExpandSearch={() => setMatchesModal({
                      journey: j,
                      radiusM:   matchOverride?.radius_m   ?? j.radius_m,
                      windowMin: matchOverride?.window_min ?? j.window_min,
                    })}
                    requests={journeyReqs}
                    requestsByMatchJourney={requestsByMatchJourney}
                    messageSummary={messageSummary}
                    requestLimits={requestLimits}
                    onToggle={() => isSelected ? setSelection(null) : selectJourney(j.id)}
                    onDelete={() => remove(j.id)}
                    onEdit={() => editJourney(j)}
                    onToggleHidden={async () => {
                      try {
                        await api.updateJourney(j.id, { is_active: !j.is_active });
                      } catch (e) {
                        showMutationError(j.is_active ? 'hide this journey' : 'show this journey', e);
                        return;
                      }
                      await refresh();
                    }}
                    onRequest={requestLift}
                    onAccept={acceptRequest}
                    onDecline={declineRequest}
                    onCancel={cancelRequest}
                    onOpenThread={liftRequestId => setMessagesOpen({ liftRequestId })}
                    onUserBlocked={onUserBlocked}
                    isAway={user.is_away}
                    onOpenProfile={() => openProfile('away')}
                  />
                );
              })}
            </div>
          </aside>

          <main className={`map-wrap ${selection?.type === 'editor' ? 'editing' : ''}`} onClick={() => { if (window.innerWidth < 768 && sidebarOpen) setSidebarOpen(false); }}>
            <JourneyMap
              journeys={journeys}
              selected={selectedJourney}
              draft={selection?.type === 'editor' ? draft : null}
              onDraftChange={setDraft}
              flyTo={editorFocus}
              initialTheme={
                user.theme === 'auto'
                  ? (matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark')
                  : user.theme
              }
            />
            {selection?.type === 'editor' && draft && (
              <JourneyEditor
                draft={draft}
                onChange={setDraft}
                onSave={saveDraft}
                onCancel={() => { setDraft(null); setSelection(null); }}
                onFocusPin={ll => setEditorFocus(prev => ({ ll: [ll.lat, ll.lng], tick: (prev?.tick ?? 0) + 1 }))}
              />
            )}
          </main>
        </div>
      )}

      {messagesOpen && (
        <MessagesModal
          requests={requests}
          messageSummary={messageSummary}
          myUserId={user.id}
          initialLiftRequestId={messagesOpen.liftRequestId ?? null}
          onClose={() => setMessagesOpen(null)}
          onMessagesRead={refreshMessages}
        />
      )}

      {matchesModal && (
        <MatchesModal
          journey={matchesModal.journey}
          initialRadiusM={matchesModal.radiusM}
          initialWindowMin={matchesModal.windowMin}
          requestsByMatchJourney={requestsByMatchJourney}
          requestLimits={requestLimits}
          onRequest={async (matchJourneyId, message) => {
            // MatchesModal deliberately doesn't catch, it documents that
            // the parent surfaces errors. It has to actually do so, or a
            // failed send leaves the compose box open with no feedback.
            try {
              await api.createLiftRequest(matchesModal.journey.id, matchJourneyId, message || undefined);
            } catch (e) {
              showMutationError('send that lift request', e);
              return;
            }
            // Mirror the inline request flow: refresh both lists so badges
            // and seats_taken update without a page reload.
            await Promise.all([refreshRequests(), refresh()]);
          }}
          onClose={() => setMatchesModal(null)}
          isAway={user.is_away}
          onOpenProfile={() => openProfile('away')}
        />
      )}

      {confirmLogout && (
        <ConfirmModal
          title="Sign out?"
          message="You'll need to sign back in to see your journeys, requests, and messages."
          confirmLabel="Sign out"
          cancelLabel="Stay signed in"
          tone="danger"
          onConfirm={logout}
          onClose={() => setConfirmLogout(false)}
        />
      )}

      {pendingPinChange && (
        <ConfirmModal
          title="Notify the people you're connected to?"
          message={
            <>
              You've moved a pin on this journey. Saving will send an
              automatic note to {pendingPinChange.passengerCount === 1
                ? 'the 1 person'
                : <>the {pendingPinChange.passengerCount} people</>}{' '}
              currently connected, asking them to re-check the pickup or
              dropoff. Continue?
            </>
          }
          confirmLabel="Save and notify"
          cancelLabel="Keep editing"
          tone="primary"
          onConfirm={() => {
            const d = pendingPinChange.draft;
            // Clear the gate first so saveDraft falls through to the
            // actual API call instead of re-opening this modal.
            setPendingPinChange(null);
            void saveDraft(d);
          }}
          onClose={() => setPendingPinChange(null)}
        />
      )}

      {/* Transient "saved" feedback. Auto-dismisses via the useEffect that
          watches saveToast; click to dismiss early. Sits above the map and
          uses the existing .toast styles so it matches the email-verify
          confirmation toast in App.tsx. */}
      {saveToast && (
        <div
          className="toast ok save-toast"
          role="status"
          aria-live="polite"
          onClick={() => setSaveToast(null)}
        >
          {saveToast}
        </div>
      )}

      <AppFooter onShowAbout={() => setAboutOpen(true)} />
    </div>
  );
}
