import { useState } from 'react';
import { Journey, LiftRequest, Match, MessageSummary } from '../api';
import { Avatar } from './Avatar';
import { BlockReportModal } from './BlockReportModal';
import { ConfirmModal } from './ConfirmModal';
import { PrefsDropdown } from './PrefsDropdown';
import { ConfirmButton } from './ConfirmButton';
import { MatchSkeletonRow } from './Skeleton';
import { Distance } from './Distance';
import { fmtNextOccurrence, nextOccurrence } from '../lib/nextOccurrence';
import { effectiveJourney } from '../lib/effectiveJourney';
import { fmtMinutes } from '../lib/units';

/**
 * <summary>
 * One letter per weekday starting Monday, matching the bit order of
 * <c>days_mask</c>.
 * </summary>
 */
const DAY_LETTERS = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];

/**
 * <summary>
 * Renders a days mask as a 7 character compact string where days that
 * are on appear as their letter and days off appear as a centre dot.
 * </summary>
 * <param name="m">7 bit days mask. Bit 0 is Monday.</param>
 * <returns>A 7 character string, e.g. <c>"MTWT·SS"</c>.</returns>
 */
const daysToString = (m: number) =>
  DAY_LETTERS.map((l, i) => (m & (1 << i)) ? l : '·').join('');

/**
 * <summary>
 * Short weekday names in <c>days_mask</c> bit order (Monday = bit 0).
 * </summary>
 */
const DAY_NAMES = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

/**
 * <summary>
 * Renders a days mask as a comma-separated list of short day names,
 * e.g. <c>"Mon, Tue"</c>. Returns <c>"none"</c> for an empty mask.
 * </summary>
 * <param name="m">7 bit days mask. Bit 0 is Monday.</param>
 */
const maskToDayNames = (m: number) =>
  DAY_NAMES.filter((_, i) => m & (1 << i)).join(', ') || 'none';


/**
 * <summary>
 * Props for <see cref="JourneyCard"/>.
 * </summary>
 */
type Props = {
  /** The journey this card represents (the user's own row). */
  journey: Journey;
  /** Whether the card is currently expanded. */
  selected: boolean;
  /** Match candidates returned by the matcher for this journey. Always empty for a driver (offer) journey. */
  matches: Match[];
  /**
   * For a driver (offer) journey: how many people are looking for a lift on
   * this route. Drivers see this anonymised count instead of a browsable
   * list, they receive requests rather than initiating them.
   */
  demandCount?: number;
  /** True while matches are being fetched (drives the skeleton). */
  matchesLoading?: boolean;
  /** Most recent matches fetch error, if any. */
  matchesError?: string | null;
  /** Current search radius in metres. Used in the header sub line. */
  searchRadiusM?: number;
  /** Current time window in minutes. Used in the header sub line. */
  searchWindowMin?: number;
  /** Optional handler that widens the match search. */
  onExpandSearch?: () => void;
  /**
   * True while the user has Away mode on in their profile. Match results
   * are hidden because others can't see them anyway, so showing matches
   * here would only invite requests they can't actually receive.
   */
  isAway: boolean;
  /**
   * Jump straight to the Profile view (where Away mode lives). Used by
   * the inline "Open Profile" shortcut on the away mode notice.
   */
  onOpenProfile: () => void;
  /** Lift requests that involve this journey (either direction). */
  requests: LiftRequest[];
  /** Lookup from match journey id to any existing request between us and them. */
  requestsByMatchJourney: Map<number, LiftRequest>;
  /** Per thread unread message counts, used for the chat row badge. */
  messageSummary: MessageSummary;
  /** Server enforced limits on outbound requests (used to gate the compose button). */
  requestLimits: { remaining_today: number; max_chars: number };
  /** Toggle the card open or shut. */
  onToggle: () => void;
  /** Delete the journey (after the inline confirm). */
  onDelete: () => void;
  /** Open the editor on this journey. */
  onEdit: () => void;
  /** Send a lift request to the given match journey. */
  onRequest: (matchJourneyId: number, message: string) => Promise<void> | void;
  /** Accept an incoming pending lift request. */
  onAccept: (id: number) => void;
  /** Decline an incoming pending lift request. */
  onDecline: (id: number) => void;
  /** Cancel a request or disconnect from an accepted one. */
  onCancel: (id: number) => void;
  /** Open the chat thread for a given lift request. */
  onOpenThread: (liftRequestId: number) => void;
  /** Fired after the user blocks someone via the Block and Report modal, so the parent can refresh. */
  onUserBlocked: () => void;
  /** Toggle the journey's hidden (is_active) state. */
  onToggleHidden: () => void;
};

/**
 * <summary>
 * Collapsible card for one of the user's own journeys. Shows the journey
 * summary, any inbound lift requests, accepted connections (with chat),
 * outbound pending requests, and a filtered list of opposite direction
 * matches with inline compose for requesting a lift.
 * </summary>
 * <param name="p">Props bag (see <see cref="Props"/>).</param>
 * <remarks>
 * For a connected passenger, the displayed start time and pickup label
 * come from the driver's row (via <c>effectiveJourney</c>). Edit and
 * delete still operate on the user's own underlying row.
 *
 * All destructive confirmations route through the centred ConfirmModal
 * (same look as the sign out dialog), not the inline
 * <see cref="ConfirmButton"/>.
 *
 * Away mode short circuits the entire matches UI. With Away on, others
 * cannot see this user in their matches either, so listing matches here
 * would only invite requests that go nowhere.
 * </remarks>
 */
export function JourneyCard(p: Props) {
  const { journey, selected, matches, requests, requestsByMatchJourney } = p;
  // For a connected passenger, *display* uses the driver's time and label,
  // "be at this pickup at this time". Edit/delete/etc still operate on the
  // user's own row.
  const eff = effectiveJourney(journey);
  // Drivers (offer) don't browse or message passengers, they get an
  // anonymised demand count and receive requests. Passengers (request)
  // browse drivers and initiate.
  const isDriver = journey.direction === 'offer';
  const demandCount = p.demandCount ?? 0;

  const pendingReceived = requests.filter(r => r.direction === 'received' && r.status === 'pending');
  const pendingSent     = requests.filter(r => r.direction === 'sent'     && r.status === 'pending');
  const accepted        = requests.filter(r => r.status === 'accepted');
  const activityCount   = pendingReceived.length + pendingSent.length + accepted.length;
  const totalUnread     = accepted.reduce((sum, r) => sum + (p.messageSummary[r.id]?.unread ?? 0), 0);
  // Matches that aren't already shown above as a request/connection, i.e. the
  // count the user actually sees in the inline list (and in the "Show matches"
  // button label on mobile).
  const visibleMatchCount = matches.filter(m => {
    const e = requestsByMatchJourney.get(m.id);
    return !(e && e.status !== 'declined' && e.status !== 'cancelled');
  }).length;

  const [composeFor, setComposeFor] = useState<number | null>(null);
  const [draftMsg, setDraftMsg]     = useState('');
  const [busy, setBusy]             = useState(false);
  // All destructive confirmations route through the centred ConfirmModal
  // (same look as the sign-out dialog), not inline ConfirmButton.
  const [confirmDelete, setConfirmDelete]           = useState(false);
  const [confirmDisconnect, setConfirmDisconnect]   = useState<LiftRequest | null>(null);
  const [confirmBlockReport, setConfirmBlockReport] = useState<{ userId: number; name: string } | null>(null);

  /**
   * <summary>
   * Awaits <c>onRequest</c> with the current draft message, then resets
   * the inline compose state.
   * </summary>
   * <param name="matchJourneyId">Id of the match journey to direct the request at.</param>
   */
  async function send(matchJourneyId: number) {
    setBusy(true);
    try {
      await p.onRequest(matchJourneyId, draftMsg.trim());
      setComposeFor(null);
      setDraftMsg('');
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className={`journey-card ${selected ? 'open' : ''} ${pendingReceived.length ? 'has-attention' : ''} ${!journey.is_active ? 'hidden-journey' : ''}`}>
      <button className="journey-card-head" onClick={p.onToggle}>
        <div className="journey-card-title">
          <div className="journey-card-next">
            {fmtNextOccurrence(nextOccurrence(journey.days_mask, eff.start_time))}
          </div>
          <div className="journey-card-meta">
            <code>{daysToString(journey.days_mask)}</code>
          </div>
        </div>
        <div className="journey-card-side">
          {pendingReceived.length > 0 && (
            <span className="badge attention" title="New lift requests">{pendingReceived.length}</span>
          )}
          {totalUnread > 0 && (
            <span className="badge attention" title="Unread messages">💬 {totalUnread}</span>
          )}
          {!isDriver && matches.length > 0 && (
            <span className="badge muted-badge" title="Matches">{matches.length}</span>
          )}
          {isDriver && demandCount > 0 && (
            <span className="badge muted-badge" title="People looking for a lift on this route">{demandCount}</span>
          )}
          {!journey.is_active && <span className="pill hidden-pill">Hidden</span>}
          <span className={`pill ${journey.direction}`}>{journey.direction === 'offer' ? 'Driver' : 'Passenger'}</span>
          <span className={`chev ${selected ? 'open' : ''}`} aria-hidden="true">▾</span>
        </div>
      </button>

      {selected && (
        <div className="journey-card-body">
          {/* Info row: when-you-leave and (driver only) seat-fill state. */}
          <div className="journey-card-tags">
            {eff.connected_to ? (
              <span className="muted small">
                pickup {eff.start_time} with <b>{eff.connected_to}</b>
              </span>
            ) : (
              <span className="muted small">leaves {journey.start_time}</span>
            )}
            {journey.direction === 'offer' && (
              <span className={`pill seats ${(journey.seats_taken ?? 0) >= journey.seats ? 'full' : ''}`}>
                {(journey.seats_taken ?? 0)}/{journey.seats} seats taken
              </span>
            )}
          </div>

          {/* Owner-action row: lives on its own line so the layout is the
              same whether the info row above is short (passenger) or wraps
              (driver with the seats pill). */}
          <div className="journey-card-actions">
            <button className="small" onClick={p.onEdit} title="Edit this journey">Edit</button>
            <button className="small" onClick={p.onToggleHidden} title={journey.is_active ? 'Hide from matches' : 'Show in matches'}>
              {journey.is_active ? 'Hide' : 'Show'}
            </button>
            <button className="small danger-link" onClick={() => setConfirmDelete(true)} title="Delete this journey">
              Delete
            </button>
          </div>

          {pendingReceived.length > 0 && (
            <section className="card-section">
              <h4>Lift requests for you ({pendingReceived.length})</h4>
              {pendingReceived.map(r => (
                <div className="request-row" key={r.id}>
                  <Avatar name={r.other_user.display_name} url={r.other_user.avatar_url} size={32} />
                  <div className="request-body">
                    <div className="row-top">
                      <PrefsDropdown
                        name={r.other_user.display_name} age={r.other_user.age} bio={r.other_user.bio}
                        car_make={r.other_user.car_make} car_colour={r.other_user.car_colour}
                        pref_smoking={r.other_user.pref_smoking} pref_pets={r.other_user.pref_pets}
                        pref_music={r.other_user.pref_music} detour_m={r.other_user.detour_m}
                      />
                    </div>
                    <div className="muted small">
                      leaves {r.their_journey.start_time} ·{' '}
                      <code>{daysToString(r.their_journey.days_mask)}</code>
                    </div>
                    {r.message && <div className="request-msg">&ldquo;{r.message}&rdquo;</div>}
                    <div className="request-actions">
                      <button className="small primary" onClick={() => p.onAccept(r.id)}>Accept</button>
                      <button className="small" onClick={() => p.onDecline(r.id)}>Decline</button>
                    </div>
                  </div>
                </div>
              ))}
            </section>
          )}

          {accepted.length > 0 && (
            <section className="card-section">
              <h4>Connected</h4>
              {accepted.map(r => {
                const summary = p.messageSummary[r.id];
                const unread  = summary?.unread ?? 0;
                return (
                  <div className="request-row" key={r.id}>
                    <Avatar name={r.other_user.display_name} url={r.other_user.avatar_url} size={32} />
                    <div className="request-body">
                      <div className="row-top">
                        <PrefsDropdown
                          name={r.other_user.display_name} age={r.other_user.age} bio={r.other_user.bio}
                          car_make={r.other_user.car_make} car_colour={r.other_user.car_colour}
                          pref_smoking={r.other_user.pref_smoking} pref_pets={r.other_user.pref_pets}
                          pref_music={r.other_user.pref_music} detour_m={r.other_user.detour_m}
                        />
                        <span className="pill accepted">Accepted</span>
                      </div>
                      {/* Journey-direction line + last-message snippet were here.
                          Both removed to keep the connected card compact,
                          journey context is in Messages, snippet in the chat. */}

                      {/* Chat row, primary action, gets its own line so it
                          reads as the main thing you do with a connection. */}
                      <div className="request-actions chat-row">
                        <button
                          className={`icon-btn chat-btn ${unread > 0 ? 'has-unread' : ''}`}
                          onClick={() => p.onOpenThread(r.id)}
                          title={unread > 0 ? `Open chat (${unread} unread)` : 'Open chat'}
                          aria-label="Open chat"
                        >
                          <svg viewBox="0 0 24 24" aria-hidden="true">
                            <path
                              d="M5 5h14a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-7l-4 3v-3H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"
                              fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinejoin="round"
                            />
                            <circle cx="9"  cy="11" r="1.1" fill="currentColor" />
                            <circle cx="12" cy="11" r="1.1" fill="currentColor" />
                            <circle cx="15" cy="11" r="1.1" fill="currentColor" />
                          </svg>
                          <span>Chat</span>
                          {unread > 0 && <span className="chat-badge">{unread}</span>}
                        </button>
                      </div>
                      {/* Secondary actions on their own row, both fire centred
                          confirmation modals to match the Sign-out dialog. */}
                      <div className="request-actions danger-row">
                        <button className="small" onClick={() => setConfirmDisconnect(r)}>
                          Disconnect
                        </button>
                        <button
                          className="small danger-link"
                          onClick={() => setConfirmBlockReport({ userId: r.other_user.id, name: r.other_user.display_name })}
                        >
                          Block &amp; Report
                        </button>
                      </div>
                    </div>
                  </div>
                );
              })}
            </section>
          )}

          {pendingSent.length > 0 && (
            <section className="card-section">
              <h4>You've requested ({pendingSent.length})</h4>
              {pendingSent.map(r => (
                <div className="request-row" key={r.id}>
                  <Avatar name={r.other_user.display_name} url={r.other_user.avatar_url} size={32} />
                  <div className="request-body">
                    <div className="row-top">
                      <PrefsDropdown
                        name={r.other_user.display_name} age={r.other_user.age} bio={r.other_user.bio}
                        car_make={r.other_user.car_make} car_colour={r.other_user.car_colour}
                        pref_smoking={r.other_user.pref_smoking} pref_pets={r.other_user.pref_pets}
                        pref_music={r.other_user.pref_music} detour_m={r.other_user.detour_m}
                      />
                      <span className="pill pending">Pending</span>
                    </div>
                    <div className="muted small">
                      leaves {r.their_journey.start_time} ·{' '}
                      <code>{daysToString(r.their_journey.days_mask)}</code>
                    </div>
                    {r.message && <div className="request-msg">&ldquo;{r.message}&rdquo;</div>}
                    <div className="request-actions">
                      <ConfirmButton
                        confirmLabel="Cancel"
                        tone="danger"
                        onConfirm={() => p.onCancel(r.id)}
                      >
                        Cancel request
                      </ConfirmButton>
                    </div>
                  </div>
                </div>
              ))}
            </section>
          )}

          <section className="card-section matches-section">
            <h4>
              {isDriver ? 'People looking for a lift' : 'Matches'}
              {!p.isAway && <span className="match-count"> ({isDriver ? demandCount : visibleMatchCount})</span>}
              {!p.isAway && !isDriver && p.searchRadiusM != null && p.searchWindowMin != null && (
                <span className="muted small search-meta">
                  {', '}within <Distance m={p.searchRadiusM} />, ±{fmtMinutes(p.searchWindowMin)}
                </span>
              )}
            </h4>

            {/* Away mode short-circuits the whole match UI: no inline list,
                no "Show matches" button, no expand-search prompt. Others can't
                see this user in their match results either (server filters
                them out by u.is_away = 0), so listing matches here would only
                invite requests that go nowhere. The notice points the user
                straight at Profile, where the Away toggle lives. */}
            {p.isAway ? (
              <div className="away-notice">
                <span className="away-pill">AWAY</span>
                <div className="away-notice-body">
                  <strong>You're in Away mode</strong>
                  <p className="muted small">
                    While Away is on, you don't appear in other people's
                    matches, and matches are hidden here too. Turn it off
                    in your profile to start seeing matches again.
                  </p>
                  <button className="small primary" onClick={p.onOpenProfile}>
                    Open Profile
                  </button>
                </div>
              </div>
            ) : isDriver ? (
              /* Driver view: an anonymised demand signal only. Drivers don't
                 browse or message passengers, when someone wants a lift they
                 send a request, which shows up in the section above to accept
                 or decline. */
              <div className="demand-note">
                {demandCount > 0 ? (
                  <p className="muted small">
                    <b>{demandCount}</b> {demandCount === 1 ? 'person is' : 'people are'} looking
                    for a lift that matches this route
                    {p.searchRadiusM != null && p.searchWindowMin != null && (
                      <> within <Distance m={p.searchRadiusM} />, ±{fmtMinutes(p.searchWindowMin)}</>
                    )}.
                    When someone asks to ride with you, their request appears above for you to accept or decline.
                  </p>
                ) : (
                  <p className="muted small">
                    No one's looking for a lift on this route just now. Keep your journey
                    active. You'll get a request here as soon as someone is.
                  </p>
                )}
              </div>
            ) : (
            <>
            {/* Mobile: skip the inline list entirely, just a "Show matches"
                button that opens the MatchesModal. The header above already
                shows the count, so this is purely a way in. The modal handles
                the empty case (0 matches) with its own widen-the-search sliders. */}
            {p.onExpandSearch && (
              <div className="matches-summary-mobile">
                <button className="small primary" onClick={p.onExpandSearch}>
                  Show matches
                </button>
              </div>
            )}

            <div className="matches-inline">
            {p.matchesLoading && matches.length === 0 && (
              <>
                <MatchSkeletonRow />
                <MatchSkeletonRow />
              </>
            )}

            {!p.matchesLoading && p.matchesError && (
              <div className="error small">Couldn't load matches: {p.matchesError}</div>
            )}

            {!p.matchesLoading && !p.matchesError && matches.length === 0 ? (
              <div className="empty">
                {/* Empty body line removed, the count "(0)" in the section
                    heading already says it, so just offer the action button. */}
                {p.onExpandSearch && (p.searchRadiusM ?? 0) < 2000 && (
                  <button className="small primary" onClick={p.onExpandSearch}>
                    Expand search
                  </button>
                )}
              </div>
            ) : matches.map(m => {
              const existing = requestsByMatchJourney.get(m.id);
              const alreadyHandled = existing && existing.status !== 'declined' && existing.status !== 'cancelled';
              if (alreadyHandled) return null; // it's already shown above as a request/connection

              // m is the OTHER user. The match list only ever shows opposite-
              // direction matches, so the action label flips per their direction:
              //   match.direction === 'offer'   → they're a driver, I'm requesting
              //   match.direction === 'request' → they're a passenger, I'm offering
              const matchIsDriver    = m.direction === 'offer';
              const matchIsPassenger = m.direction === 'request';
              const seatsFull        = matchIsDriver && m.seats_taken >= m.seats;
              const actionLabel      = matchIsPassenger ? 'Offer lift' : 'Request lift';
              const composeHint      = matchIsPassenger
                ? "Optional message (e.g. \"happy to pick you up on Tuesdays\")"
                : "Optional message (e.g. \"I work nearby, can usually grab a Tuesday lift\")";

              // Matching only needs ONE shared day, so e.g. a Mon-only driver
              // matches a Mon+Tue journey. Spell out exactly which days overlap,
              // and flag any of my days this match does NOT cover, so the user
              // doesn't assume the lift runs every day they travel.
              const sharedMask    = journey.days_mask & m.days_mask;
              const uncoveredMask = journey.days_mask & ~sharedMask & 0x7f;

              return (
                <div key={m.id} className="match-row">
                  <Avatar name={m.display_name} url={m.avatar_url} size={32} />
                  <div className="match-body">
                    <div className="row-top">
                      <PrefsDropdown
                        name={m.display_name} age={m.age}
                        car_make={m.car_make} car_colour={m.car_colour}
                        pref_smoking={m.pref_smoking} pref_pets={m.pref_pets}
                        pref_music={m.pref_music} detour_m={m.detour_m}
                      />
                      <span className={`pill ${m.direction}`}>{m.direction === 'offer' ? 'Driver' : 'Passenger'}</span>
                    </div>
                    <div className="muted small">leaves {m.start_time}</div>
                    <div className="muted small">start <Distance m={m.start_dist_m} /> · end <Distance m={m.end_dist_m} /></div>
                    <div className="muted small">shared days: {maskToDayNames(sharedMask)}</div>
                    {uncoveredMask > 0 && (
                      <div className="small warn-text">
                        Doesn't cover your {maskToDayNames(uncoveredMask)}. You'd still need a lift for {(uncoveredMask & (uncoveredMask - 1)) === 0 ? 'that day' : 'those days'}.
                      </div>
                    )}

                    {matchIsDriver && (
                      <div className="muted small">
                        {m.seats_taken}/{m.seats} seats taken
                        {seatsFull && <> · <b className="error-text">full</b></>}
                      </div>
                    )}

                    {existing?.status === 'declined' && <span className="pill declined">Declined</span>}

                    {!existing && composeFor !== m.id && (
                      <div className="match-action">
                        <button className="small primary"
                                disabled={seatsFull || p.requestLimits.remaining_today <= 0}
                                title={
                                  seatsFull ? 'No spare seats on this journey'
                                  : p.requestLimits.remaining_today <= 0 ? "Daily request limit reached. Try again tomorrow."
                                  : undefined
                                }
                                onClick={() => { setComposeFor(m.id); setDraftMsg(''); }}>
                          {seatsFull ? 'No seats' : p.requestLimits.remaining_today <= 0 ? 'Limit reached' : actionLabel}
                        </button>
                      </div>
                    )}

                    {composeFor === m.id && (
                      <div className="compose">
                        <textarea
                          rows={2}
                          maxLength={p.requestLimits.max_chars}
                          value={draftMsg}
                          onChange={e => setDraftMsg(e.target.value)}
                          placeholder={composeHint}
                        />
                        <div className="compose-actions">
                          <span className="muted small">
                            {draftMsg.length}/{p.requestLimits.max_chars} · {p.requestLimits.remaining_today} left today
                          </span>
                          <button className="small primary"
                                  disabled={busy || p.requestLimits.remaining_today <= 0}
                                  onClick={() => send(m.id)}>Send</button>
                          <button className="small" disabled={busy} onClick={() => setComposeFor(null)}>Cancel</button>
                        </div>
                      </div>
                    )}
                  </div>
                </div>
              );
            })}

            {!p.matchesLoading && matches.length > 0 && p.onExpandSearch && (p.searchRadiusM ?? 0) < 2000 && (
              <button className="small primary expand-search" onClick={p.onExpandSearch}>
                Expand search
              </button>
            )}
            </div> {/* /matches-inline */}
            </>
            )}
          </section>
        </div>
      )}

      {!selected && activityCount > 0 && (
        <div className="journey-card-footer muted small">
          {pendingReceived.length > 0 && <>{pendingReceived.length} new request{pendingReceived.length === 1 ? '' : 's'} · </>}
          {accepted.length > 0 && <>{accepted.length} connected · </>}
          {pendingSent.length > 0 && <>{pendingSent.length} sent</>}
        </div>
      )}

      {confirmDelete && (
        <ConfirmModal
          title="Delete this journey?"
          message={
            <>
              This journey and any pending lift requests on it will be removed.
              {accepted.length > 0 && (
                <> Existing connections will be disconnected and their seats freed up.</>
              )}{' '}
              This can't be undone.
            </>
          }
          confirmLabel="Delete"
          cancelLabel="Keep journey"
          tone="danger"
          onConfirm={p.onDelete}
          onClose={() => setConfirmDelete(false)}
        />
      )}

      {confirmDisconnect && (
        <ConfirmModal
          title={`Disconnect from ${confirmDisconnect.other_user.display_name}?`}
          message="The connection ends and this seat opens back up. You can reconnect later if both sides want to."
          confirmLabel="Disconnect"
          cancelLabel="Stay connected"
          tone="danger"
          onConfirm={() => p.onCancel(confirmDisconnect.id)}
          onClose={() => setConfirmDisconnect(null)}
        />
      )}

      {confirmBlockReport && (
        <BlockReportModal
          userId={confirmBlockReport.userId}
          displayName={confirmBlockReport.name}
          onDone={p.onUserBlocked}
          onClose={() => setConfirmBlockReport(null)}
        />
      )}
    </div>
  );
}
