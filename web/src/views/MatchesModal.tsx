import { useEffect, useState } from 'react';
import { api, Journey, LiftRequest, Match } from '../api';
import { Modal } from '../components/Modal';
import { Avatar } from '../components/Avatar';
import { Distance } from '../components/Distance';
import { VehicleInfo } from '../components/VehicleInfo';
import { fmtMinutes } from '../lib/units';

/**
 * <summary>
 * Props for <see cref="MatchesModal"/>. Controls which journey to search
 * around, the initial search radius and window, existing lift requests,
 * server side rate limits, and the various callbacks.
 * </summary>
 */
type Props = {
  /** The journey being matched against. Defines the centre points and
   *  the time of day used for the search. */
  journey: Journey;
  /** Starting radius in metres. The slider can widen or narrow it. */
  initialRadiusM: number;
  /** Starting time window in minutes. The slider can widen or narrow it. */
  initialWindowMin: number;
  /** Existing lift requests keyed by the OTHER side's journey id, used to
   *  show "Request sent" or "Connected" pills inline on matches that are
   *  already in flight or settled. */
  requestsByMatchJourney: Map<number, LiftRequest>;
  /** Server side daily request quota and message length limit. */
  requestLimits: { remaining_today: number; max_chars: number };
  /** Submits a new lift request. The optional message is the body the user
   *  typed in the inline composer. */
  onRequest: (matchJourneyId: number, message: string) => Promise<void> | void;
  /** Closes the modal. */
  onClose: () => void;
  /** True while the user has Away mode on. Mirrors the JourneyCard gate:
   *  swap the match list for an "you're away" notice and a shortcut into
   *  the Profile view where Away mode is toggled. */
  isAway: boolean;
  /** Open the Profile view from the away notice. Closes this modal first
   *  so the user lands directly on the profile form, not back on the map. */
  onOpenProfile: () => void;
};

/**
 * <summary>
 * Modal that lets the user widen the radius and time window for a single
 * journey and then send a lift request to any of the resulting matches.
 * </summary>
 * <param name="journey">The journey being matched against.</param>
 * <param name="initialRadiusM">Starting radius in metres.</param>
 * <param name="initialWindowMin">Starting time window in minutes.</param>
 * <param name="requestsByMatchJourney">Existing requests keyed by the
 * other journey id, used for inline status pills.</param>
 * <param name="requestLimits">Daily request quota and message length
 * limit from the server.</param>
 * <param name="onRequest">Submits a lift request with an optional message.</param>
 * <param name="onClose">Closes the modal.</param>
 * <param name="isAway">True while Away mode is on, in which case the
 * match list is hidden behind an "away" notice.</param>
 * <param name="onOpenProfile">Opens the Profile view from the away
 * notice.</param>
 * <remarks>
 * Re-queries the matches API whenever the sliders change. A 250ms debounce
 * keeps dragging smooth, and an <see cref="AbortController"/> on top of
 * the debounce makes sure only the latest result survives if an older
 * request finishes slower than a newer one.
 *
 * Away mode short circuits the fetch entirely, the user cannot be matched
 * against right now so showing a list would be misleading. Instead the
 * modal renders a notice with a shortcut into the Profile view.
 *
 * The inline composer enforces both the per message character cap and the
 * remaining daily quota at the button level. The same limits are
 * re-checked server side.
 * </remarks>
 */
export function MatchesModal({ journey, initialRadiusM, initialWindowMin, requestsByMatchJourney, requestLimits, onRequest, onClose, isAway, onOpenProfile }: Props) {
  const [radiusKm, setRadiusKm]     = useState(initialRadiusM / 1000);
  const [windowMin, setWindowMin]   = useState(initialWindowMin);
  const [matches, setMatches]       = useState<Match[]>([]);
  const [loading, setLoading]       = useState(true);
  const [error, setError]           = useState<string | null>(null);
  const [composeFor, setComposeFor] = useState<number | null>(null);
  const [draftMsg, setDraftMsg]     = useState('');
  const [busy, setBusy]             = useState(false);

  // Re-query whenever the sliders change (with a debounce so dragging is smooth).
  // AbortController on top of the debounce: the debounce stops us firing
  // a request per-tick, but an in-flight fetch can still finish after a
  // newer one starts if the first is slow. Aborting on cleanup means
  // only the latest matching state survives.
  //
  // Away mode short-circuits: skip the fetch entirely. The user can't
  // be matched against right now, so listing matches would be misleading.
  useEffect(() => {
    if (isAway) { setLoading(false); setMatches([]); setError(null); return; }
    setLoading(true);
    setError(null);
    const ctrl = new AbortController();
    const t = setTimeout(async () => {
      try {
        const r = await api.matches(journey.id, Math.round(radiusKm * 1000), windowMin, ctrl.signal);
        if (!ctrl.signal.aborted) setMatches(r.matches);
      } catch (e) {
        if (ctrl.signal.aborted) return;
        setMatches([]);
        setError((e as Error).message || 'Could not load matches');
      } finally {
        if (!ctrl.signal.aborted) setLoading(false);
      }
    }, 250);
    return () => { clearTimeout(t); ctrl.abort(); };
  }, [journey.id, radiusKm, windowMin, isAway]);

  /**
   * <summary>
   * Sends the drafted lift request to the given match journey, then
   * clears the compose state on success.
   * </summary>
   * <param name="matchJourneyId">The other journey being requested.</param>
   * <remarks>
   * The body comes from <c>draftMsg</c>, trimmed. Busy state is flipped on
   * for the duration so the Send button cannot be double clicked. Errors
   * are intentionally not caught here, the parent handler is expected to
   * surface them.
   * </remarks>
   */
  async function send(matchJourneyId: number) {
    setBusy(true);
    try {
      await onRequest(matchJourneyId, draftMsg.trim());
      setComposeFor(null);
      setDraftMsg('');
    } finally {
      setBusy(false);
    }
  }

  if (isAway) {
    return (
      <Modal
        title="Matches"
        subtitle="Away mode is on"
        onClose={onClose}
        size="lg"
      >
        <div className="matches-modal">
          <div className="away-notice">
            <span className="away-pill">AWAY</span>
            <div className="away-notice-body">
              <strong>You're in Away mode</strong>
              <p className="muted small">
                While Away is on, you don't appear in other people's matches,
                and matches are hidden here too. Turn it off in your profile
                to start seeing matches again.
              </p>
              <button
                className="small primary"
                onClick={() => { onClose(); onOpenProfile(); }}
              >
                Open Profile
              </button>
            </div>
          </div>
        </div>
      </Modal>
    );
  }

  return (
    <Modal
      title="Matches"
      subtitle={loading ? 'Searching…' : `${matches.length} ${matches.length === 1 ? 'match' : 'matches'}`}
      onClose={onClose}
      size="lg"
    >
      <div className="matches-modal">
        <div className="matches-controls">
          <label>
            Search radius: <strong><Distance m={Math.min(radiusKm, 2) * 1000} /></strong>
            <input type="range" min={0.1} max={2} step={0.1}
                   value={Math.min(radiusKm, 2)} onChange={e => setRadiusKm(Number(e.target.value))} />
          </label>
          <label>
            Time window: <strong>±{fmtMinutes(windowMin)}</strong>
            <input type="range" min={5} max={240} step={5}
                   value={windowMin} onChange={e => setWindowMin(Number(e.target.value))} />
          </label>
        </div>

        <div className="matches-list">
          {!loading && error && (
            <div className="error small">Couldn't load matches: {error}</div>
          )}

          {!loading && !error && matches.length === 0 && (
            <div className="empty">
              <div className="empty-headline">Nothing in that range yet</div>
              <div className="empty-body">Try widening the radius or the time window above.</div>
            </div>
          )}

          {matches.map(m => {
            const existing       = requestsByMatchJourney.get(m.id);
            const alreadyHandled = existing && existing.status !== 'declined' && existing.status !== 'cancelled';
            const matchIsDriver    = m.direction === 'offer';
            const matchIsPassenger = m.direction === 'request';
            const seatsFull        = matchIsDriver && m.seats_taken >= m.seats;
            const actionLabel      = matchIsPassenger ? 'Offer lift' : 'Request lift';
            const composeHint      = matchIsPassenger
              ? "Optional message (e.g. \"happy to pick you up on Tuesdays\")"
              : "Optional message (e.g. \"I work nearby, can usually grab a Tuesday lift\")";

            return (
              <div key={m.id} className="match-card-large">
                <Avatar name={m.display_name} url={m.avatar_url} size={44} />
                <div className="match-body">
                  <div className="row-top">
                    <strong>
                      {m.display_name}
                      {m.age != null && <span className="age-chip"> · {m.age}</span>}
                    </strong>
                    <span className={`pill ${m.direction}`}>{matchIsDriver ? 'Driver' : 'Passenger'}</span>
                  </div>
                  <div className="muted small">leaves {m.start_time}</div>
                  <div className="muted small">start <Distance m={m.start_dist_m} /> · end <Distance m={m.end_dist_m} /></div>
                  {matchIsDriver && (
                    <div className="muted small">
                      {m.seats_taken}/{m.seats} seats taken{seatsFull && <> · <b className="error-text">full</b></>}
                    </div>
                  )}
                  <VehicleInfo
                    car_make={m.car_make} car_colour={m.car_colour}
                    pref_smoking={m.pref_smoking} pref_pets={m.pref_pets}
                    pref_music={m.pref_music} detour_m={m.detour_m}
                  />

                  {alreadyHandled && existing && (
                    <div className="match-action">
                      <span className={`pill ${existing.status}`}>
                        {existing.status === 'pending' ? 'Request sent' : existing.status === 'accepted' ? 'Connected' : existing.status}
                      </span>
                    </div>
                  )}

                  {!existing && composeFor !== m.id && (
                    <div className="match-action">
                      <button
                        className="small primary"
                        disabled={seatsFull || requestLimits.remaining_today <= 0}
                        title={
                          seatsFull ? 'No spare seats on this journey'
                          : requestLimits.remaining_today <= 0 ? 'Daily request limit reached'
                          : undefined
                        }
                        onClick={() => { setComposeFor(m.id); setDraftMsg(''); }}
                      >
                        {seatsFull ? 'No seats' : requestLimits.remaining_today <= 0 ? 'Limit reached' : actionLabel}
                      </button>
                    </div>
                  )}

                  {composeFor === m.id && (
                    <div className="compose">
                      <textarea
                        rows={2}
                        maxLength={requestLimits.max_chars}
                        value={draftMsg}
                        onChange={e => setDraftMsg(e.target.value)}
                        placeholder={composeHint}
                      />
                      <div className="compose-actions">
                        <span className="muted small">
                          {draftMsg.length}/{requestLimits.max_chars} · {requestLimits.remaining_today} left today
                        </span>
                        <button className="small primary" disabled={busy || requestLimits.remaining_today <= 0} onClick={() => send(m.id)}>Send</button>
                        <button className="small" disabled={busy} onClick={() => setComposeFor(null)}>Cancel</button>
                      </div>
                    </div>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      </div>
    </Modal>
  );
}
