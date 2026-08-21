import { useEffect, useRef, useState } from 'react';
import { GeocodeResult, geolocateMe, reverseGeocode, searchAddress } from '../lib/geocode';
import { Distance } from './Distance';
import { fmtMinutes } from '../lib/units';

/**
 * <summary>
 * A simple latitude / longitude pair, used for pin positions throughout
 * the editor and map.
 * </summary>
 */
export type LatLng = { lat: number; lng: number };

/**
 * <summary>
 * Mutable draft of a journey being created or edited. RideApp holds
 * this and passes it into the editor and the map; the editor mutates
 * it through <c>onChange</c>.
 * </summary>
 */
export type DraftJourney = {
  /** Present when editing an existing journey, absent when creating new. */
  id?: number;
  /** User supplied label, e.g. "Commute to work". */
  label: string;
  /** Start pin position, <c>null</c> until set. */
  start: LatLng | null;
  /** End pin position, <c>null</c> until set. */
  end:   LatLng | null;
  /** Start time as HH:MM. */
  start_time: string;
  /** 7 bit days mask. Bit 0 is Monday. */
  days_mask: number;
  /** Whether the user is offering a lift (driver) or requesting one (passenger). */
  direction: 'offer' | 'request';
  /** Spare seats for drivers. Ignored when direction is <c>request</c>. */
  seats: number;
  /** Match radius in metres. */
  radius_m: number;
  /** Time window in minutes either side of the start time. */
  window_min: number;
  /** When true the editor asks OSRM for a driving route between the pins. */
  use_routing: boolean;
  /** Computed WKT linestring for the route. <c>null</c> when off or unresolved. */
  route_wkt?: string | null;
  /** Route distance in metres, when routing succeeded. */
  route_distance_m?: number | null;
  /** Route driving duration in seconds, when routing succeeded. */
  route_duration_s?: number | null;
  /** Per journey minimum age filter on the other party. <c>null</c> means no lower bound. */
  age_min: number | null;
  /** Per journey maximum age filter on the other party. <c>null</c> means no upper bound. */
  age_max: number | null;
  /** Per journey sex preference on the other party. */
  pref_sex: 'any' | 'male' | 'female';
};

/**
 * <summary>
 * Day name labels in display order, Monday first. Bit position in
 * <c>days_mask</c> matches the index here.
 * </summary>
 */
const DAY_LABELS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

/**
 * <summary>
 * Props for <see cref="JourneyEditor"/>.
 * </summary>
 */
type Props = {
  /** The draft being edited. */
  draft: DraftJourney;
  /** Called on every field change with the new draft. */
  onChange: (d: DraftJourney) => void;
  /** Called when the user clicks Save and validation passes. */
  onSave: (d: DraftJourney) => void;
  /** Called when the user cancels. */
  onCancel: () => void;
  /**
   * Fired when the user picks a Start or End target that already has a
   * pin. RideApp uses this to fly the map camera onto that pin.
   */
  onFocusPin?: (ll: LatLng) => void;
};

/**
 * <summary>
 * Which pin (start or end) the next geolocate / address pick should fill.
 * </summary>
 */
type Target = 'start' | 'end';

/**
 * <summary>
 * Editor panel for creating or modifying a journey. Provides pin
 * targeting, address search, "use my location", days and time, routing
 * options, and per journey filters.
 * </summary>
 * <param name="draft">Current draft state, owned by the parent.</param>
 * <param name="onChange">Receives the updated draft on every field change.</param>
 * <param name="onSave">Called when Save is clicked and the draft passes validation.</param>
 * <param name="onCancel">Called when the user cancels.</param>
 * <param name="onFocusPin">Optional camera hook that flies to a pin when its target button is clicked.</param>
 * <remarks>
 * On a phone the editor sheet eats most of the screen, so the user
 * cannot see where they are tapping pins. The collapse button shrinks
 * the sheet to just the Start and End target buttons plus a re expand
 * chevron, so they can drop pins freely and still know which one they
 * are placing. The editor defaults to collapsed on mobile for both new
 * and edit flows.
 *
 * Validation feedback: when the user taps Save without all required
 * fields filled, we scroll to the first missing piece and pulse it
 * green so they can see exactly what is missing rather than just being
 * blocked by a disabled button.
 * </remarks>
 */
export function JourneyEditor({ draft, onChange, onSave, onCancel, onFocusPin }: Props) {
  const draftRef = useRef(draft);
  draftRef.current = draft;

  const isEdit = draft.id !== undefined;

  // On a phone the editor sheet eats most of the screen, so the user can't
  // actually see where they're tapping pins. Collapse button shrinks the
  // sheet to just the Start/End target buttons + a re-expand chevron, so
  // they can drop pins freely and still know which one they're placing.
  //
  // Default to *collapsed* on mobile for BOTH new and edit flows. On a
  // phone the form fields are the secondary thing, the primary thing is
  // the map, where pins live. Opening collapsed lets the user see the
  // existing pins right away (edit) or drop new ones (new). Save/Cancel
  // stays in the sticky top bar, so the basic flow (adjust pins → Save)
  // never requires re-expanding. The chevron is always there if the user
  // does want to change label/days/etc.
  const [collapsed, setCollapsed] = useState(() => {
    if (typeof window === 'undefined') return false;
    return window.matchMedia('(max-width: 767px)').matches;
  });

  // Validation feedback: when the user taps Save without all fields filled,
  // we scroll to the first missing piece and pulse it green so they can see
  // exactly what's missing instead of just being blocked by a disabled button.
  type MissingField = 'pins' | 'days';
  const [highlight, setHighlight] = useState<MissingField | null>(null);
  const daysRef        = useRef<HTMLFieldSetElement>(null);
  const pinTargetsRef  = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!highlight) return;
    const t = setTimeout(() => setHighlight(null), 1700);
    return () => clearTimeout(t);
  }, [highlight]);

  /**
   * <summary>
   * Validates the draft and, if everything is present, calls <c>onSave</c>.
   * Otherwise scrolls to the first missing field and pulses it green.
   * </summary>
   */
  function attemptSave() {
    if (!draft.start || !draft.end) {
      setHighlight('pins');
      pinTargetsRef.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      return;
    }
    if (draft.days_mask === 0) {
      setHighlight('days');
      daysRef.current?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }
    onSave(draft);
  }

  // The address-search and geolocate buttons need to know which pin to update.
  // Default: whichever pin is empty; if both set, default to start (user can flip).
  const [target, setTarget] = useState<Target>(!draft.start ? 'start' : !draft.end ? 'end' : 'start');
  useEffect(() => {
    if (!draft.start) setTarget('start');
    else if (!draft.end) setTarget('end');
    // if both set, leave target as user chose
  }, [draft.start, draft.end]);

  const step = !draft.start ? 'Tap the map, search an address, or use your location for the START point'
             : !draft.end   ? 'Now set the END point: tap the map, search, or use your location'
             : draft.use_routing && draft.route_wkt === undefined ? 'Calculating route…'
             : isEdit ? 'Adjust pins or details, then Save changes' : 'Drag pins to adjust, then Save';

  const km = draft.route_distance_m != null ? (draft.route_distance_m / 1000).toFixed(1) : null;
  const min = draft.route_duration_s != null ? Math.round(draft.route_duration_s / 60) : null;

  // ----- Address search -----
  const [query, setQuery]       = useState('');
  const [results, setResults]   = useState<GeocodeResult[] | null>(null);
  const [searching, setSearching] = useState(false);
  const [searchErr, setSearchErr] = useState<string | null>(null);
  const searchAbort = useRef<AbortController | null>(null);

  useEffect(() => {
    if (!query.trim()) { setResults(null); return; }
    searchAbort.current?.abort();
    const ctrl = new AbortController();
    searchAbort.current = ctrl;
    const t = setTimeout(async () => {
      setSearching(true); setSearchErr(null);
      try {
        const r = await searchAddress(query, ctrl.signal);
        if (!ctrl.signal.aborted) setResults(r);
      } catch (e) {
        if ((e as Error).name !== 'AbortError') setSearchErr((e as Error).message);
      } finally {
        if (!ctrl.signal.aborted) setSearching(false);
      }
    }, 400);
    return () => { clearTimeout(t); ctrl.abort(); };
  }, [query]);

  /**
   * <summary>
   * Applies a geocode search hit to whichever pin (start or end) is the
   * current target, then clears the search box.
   * </summary>
   * <param name="r">The picked geocode result.</param>
   */
  function pickResult(r: GeocodeResult) {
    onChange({ ...draft, [target]: { lat: r.lat, lng: r.lng } });
    setQuery('');
    setResults(null);
  }

  // ----- "Use my location" -----
  const [locating, setLocating]     = useState(false);
  const [locErr, setLocErr]         = useState<string | null>(null);
  const [explainGeo, setExplainGeo] = useState(false);

  /**
   * <summary>
   * Entry point for the "use my location" button.
   * </summary>
   * <remarks>
   * Shows a friendly explainer the first time only, which saves users
   * from a mystery browser permission prompt. On subsequent uses it
   * jumps straight to the geolocate call.
   * </remarks>
   */
  function tryUseMyLocation() {
    // Show a friendly explainer the first time only, saves users from a
    // mystery browser permission prompt.
    if (!localStorage.getItem('sl_geo_explained')) {
      setExplainGeo(true);
      return;
    }
    runGeolocate();
  }

  /**
   * <summary>
   * Asks the browser for the current position and writes it into the
   * current pin target. Sets a localStorage marker so the explainer is
   * not shown again.
   * </summary>
   */
  async function runGeolocate() {
    setExplainGeo(false);
    localStorage.setItem('sl_geo_explained', '1');
    setLocating(true); setLocErr(null);
    try {
      const ll = await geolocateMe();
      if (ll) onChange({ ...draftRef.current, [target]: ll });
    } catch (e) {
      setLocErr((e as Error).message);
    } finally {
      setLocating(false);
    }
  }

  // ----- Reverse geocoding for the place-name labels under each pin -----
  const [startLabel, setStartLabel] = useState<string | null>(null);
  const [endLabel,   setEndLabel]   = useState<string | null>(null);

  useEffect(() => {
    let abort = false;
    setStartLabel(null);
    if (!draft.start) return;
    reverseGeocode(draft.start.lat, draft.start.lng).then(s => { if (!abort) setStartLabel(s); });
    return () => { abort = true; };
  }, [draft.start?.lat, draft.start?.lng]);

  useEffect(() => {
    let abort = false;
    setEndLabel(null);
    if (!draft.end) return;
    reverseGeocode(draft.end.lat, draft.end.lng).then(s => { if (!abort) setEndLabel(s); });
    return () => { abort = true; };
  }, [draft.end?.lat, draft.end?.lng]);

  return (
    <div className={`editor ${collapsed ? 'collapsed' : ''}`}>
      {/* Collapse / expand handle. Hidden on desktop via CSS, on phones it's
          a small chevron button that sits above the Start/End row, just a
          handle to fold the sheet down so the user can see the map. */}
      <button
        type="button"
        className={`editor-collapse-handle ${collapsed ? 'collapsed' : ''}`}
        aria-label={collapsed ? 'Expand editor' : 'Collapse editor'}
        aria-expanded={!collapsed}
        onClick={() => setCollapsed(c => !c)}
      >
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M6 14 L12 8 L18 14" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
      </button>

      {/* Pin target toggle, kept above the collapsible body so it stays
          reachable when the editor is folded down on a phone. Clicking the
          button also recentres the map onto that pin if it's already set,
          so the user can quickly jump back to look at where they dropped it. */}
      <div ref={pinTargetsRef}
           className={`pin-targets ${highlight === 'pins' ? 'field-highlight' : ''}`}>
        <button type="button" className={`target-btn start ${target === 'start' ? 'on' : ''}`}
                onClick={() => { setTarget('start'); if (draft.start) onFocusPin?.(draft.start); }}>
          <span className="dot" /> Start{startLabel ? <span className="muted small"> · {startLabel}</span> : draft.start ? <span className="muted small"> · pin set</span> : ''}
        </button>
        <button type="button" className={`target-btn end ${target === 'end' ? 'on' : ''}`}
                onClick={() => { setTarget('end'); if (draft.end) onFocusPin?.(draft.end); }}>
          <span className="dot" /> End{endLabel ? <span className="muted small"> · {endLabel}</span> : draft.end ? <span className="muted small"> · pin set</span> : ''}
        </button>
      </div>

      {/* Mobile-only sticky Save/Cancel bar, keeps Save reachable even when
          scrolled deep into the form. Hidden on desktop and when collapsed. */}
      <div className="editor-actions editor-actions-top">
        <button type="button" className="primary" onClick={attemptSave}>
          {isEdit ? 'Save changes' : 'Save'}
        </button>
        <button type="button" onClick={onCancel}>Cancel</button>
      </div>

      <div className="editor-collapsible">
      <div className="editor-step">{step}</div>
      {km && min !== null && (
        <div className="route-summary">Route: {km} km · ~{min} min driving</div>
      )}

      <div className="search-row">
        <input
          className="search-input"
          placeholder={`Search address for ${target.toUpperCase()}…`}
          value={query}
          onChange={e => setQuery(e.target.value)}
        />
        <button type="button" className="small" disabled={locating} onClick={tryUseMyLocation} title="Use my current location">
          {locating ? '…' : '📍'}
        </button>
      </div>

      {explainGeo && (
        <div className="geo-explainer">
          <p>
            Use your phone or laptop's <b>location</b> to drop the {target.toUpperCase()} pin where you're standing right now?
          </p>
          <p className="muted small">
            Your browser will ask for permission. SwiftLift only reads the coordinate
            once. We don't track or store it beyond the pin you save.
          </p>
          <div className="geo-explainer-actions">
            <button className="small primary" onClick={runGeolocate}>Use my location</button>
            <button className="small" onClick={() => setExplainGeo(false)}>Cancel</button>
          </div>
        </div>
      )}

      {locErr && <div className="error small">{locErr}</div>}
      {searchErr && <div className="error small">{searchErr}</div>}
      {searching && <div className="muted small">Searching…</div>}
      {results && results.length > 0 && (
        <ul className="search-results">
          {results.map((r, i) => (
            <li key={i}>
              <button type="button" onClick={() => pickResult(r)}>{r.display_name}</button>
            </li>
          ))}
        </ul>
      )}
      {results && results.length === 0 && !searching && (
        <div className="muted small">No matches for &ldquo;{query}&rdquo;.</div>
      )}

      <label>Start time
        <input type="time" value={draft.start_time}
               onChange={e => onChange({ ...draft, start_time: e.target.value })} />
      </label>

      <fieldset ref={daysRef} className={`days ${highlight === 'days' ? 'field-highlight' : ''}`}>
        <legend>Days</legend>
        {DAY_LABELS.map((d, i) => {
          const bit = 1 << i;
          const on = (draft.days_mask & bit) !== 0;
          return (
            <button type="button" key={d}
                    className={on ? 'day on' : 'day'}
                    onClick={() => onChange({ ...draft, days_mask: draft.days_mask ^ bit })}>
              {d}
            </button>
          );
        })}
      </fieldset>

      <label>I'm
        <select value={draft.direction}
                onChange={e => onChange({ ...draft, direction: e.target.value as 'offer' | 'request' })}>
          <option value="offer">offering a lift (driving)</option>
          <option value="request">looking for a lift</option>
        </select>
      </label>

      {draft.direction === 'offer' && (
        <label>Spare seats
          <input type="number" inputMode="numeric" min={1} max={8}
                 value={draft.seats}
                 onChange={e => onChange({ ...draft, seats: Math.max(1, Math.min(8, Number(e.target.value) || 1)) })} />
        </label>
      )}

      <label>Match radius: <strong><Distance m={Math.min(draft.radius_m, 2000)} /></strong>
        <input type="range" min={100} max={2000} step={100}
               value={Math.min(draft.radius_m, 2000)}
               onChange={e => onChange({ ...draft, radius_m: Number(e.target.value) })} />
      </label>

      <label>Time window: <strong>±{fmtMinutes(draft.window_min)}</strong>
        <input type="range" min={5} max={240} step={5}
               value={draft.window_min}
               onChange={e => onChange({ ...draft, window_min: Number(e.target.value) })} />
      </label>

      <label className="inline-check">
        <input
          type="checkbox"
          checked={draft.use_routing}
          onChange={e => onChange({ ...draft, use_routing: e.target.checked, route_wkt: e.target.checked ? undefined : null })}
        />
        <span>Follow roads (calculates a driving route)</span>
      </label>

      {/* Per-journey age + sex filter on the other party. Mirrors the
          shape of the profile-level defaults: age_min and age_max are
          optional, pref_sex defaults to 'any'. Empty = no bound. */}
      <fieldset className="editor-prefs">
        <legend>Who I'll share with on this journey</legend>
        <p className="muted small">
          Defaults come from your profile. Tighten or loosen them just for
          this journey if you want.
        </p>
        <div className="editor-prefs-row">
          <label>Min age
            <input type="number" min={18} max={120} placeholder="any"
                   value={draft.age_min ?? ''}
                   onChange={e => onChange({ ...draft, age_min: e.target.value === '' ? null : Number(e.target.value) })} />
          </label>
          <label>Max age
            <input type="number" min={18} max={120} placeholder="any"
                   value={draft.age_max ?? ''}
                   onChange={e => onChange({ ...draft, age_max: e.target.value === '' ? null : Number(e.target.value) })} />
          </label>
          <label>Sex preference
            <select value={draft.pref_sex}
                    onChange={e => onChange({ ...draft, pref_sex: e.target.value as 'any' | 'male' | 'female' })}>
              <option value="any">Anyone</option>
              <option value="female">Women only</option>
              <option value="male">Men only</option>
            </select>
          </label>
        </div>
      </fieldset>

      <div className="editor-actions">
        <button type="button" className="primary" onClick={attemptSave}>
          {isEdit ? 'Save changes' : 'Save'}
        </button>
        <button type="button" onClick={onCancel}>Cancel</button>
        {(draft.start || draft.end) && (
          <button type="button" className="link" onClick={() => onChange({ ...draft, start: null, end: null })}>
            Reset pins
          </button>
        )}
      </div>
      </div> {/* /editor-collapsible */}
    </div>
  );
}
