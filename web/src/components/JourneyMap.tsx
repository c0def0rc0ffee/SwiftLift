import { useEffect, useRef, useState } from 'react';
import { MapContainer, Marker, Polyline, TileLayer, Tooltip, useMap, useMapEvents, ZoomControl } from 'react-leaflet';
import L from 'leaflet';
import { Journey } from '../api';
import type { DraftJourney, LatLng } from './JourneyEditor';
import { wktToCoords } from '../lib/osrm';
import { effectiveJourney } from '../lib/effectiveJourney';
import { useVersion } from '../lib/useVersion';

// Same setup as the RoadClosures project: Carto dark basemap, no default
// attribution control, zoom control in the bottom-right.

/**
 * <summary>
 * Default map centre, roughly the middle of Guernsey.
 * </summary>
 */
const GUERNSEY_CENTER: [number, number] = [49.456, -2.55];

/**
 * <summary>
 * Default zoom level on first load.
 * </summary>
 */
const DEFAULT_ZOOM = 12;

/**
 * <summary>
 * Tight bounds covering just Guernsey island.
 * </summary>
 * <remarks>
 * Used to (a) clamp where pins can be dropped (see <see cref="inGuernsey"/>)
 * and (b) refit the camera on first load so the user always starts
 * looking at Guernsey rather than mid Atlantic.
 * </remarks>
 */
const GUERNSEY_BOUNDS: L.LatLngBoundsLiteral = [
  [49.41, -2.70], // SW
  [49.52, -2.47], // NE
];

/**
 * <summary>
 * Looser bounds covering the Channel Islands plus a chunk of the
 * Cotentin, Normandy and Brittany coast.
 * </summary>
 * <remarks>
 * Used as the map's <c>maxBounds</c> so the user can zoom out and see
 * real world context. Pins still cannot be dropped outside the tight
 * Guernsey bounds (see <see cref="inGuernsey"/>).
 * </remarks>
 */
const PAN_BOUNDS: L.LatLngBoundsLiteral = [
  [48.40, -4.50], // SW (Brittany / west of Brest)
  [50.20, -0.80], // NE (Cherbourg / Normandy)
];

/** Minimum zoom level. Regional view of Channel Islands, Normandy and Brittany. */
const MIN_ZOOM = 8;
/** Maximum zoom level Leaflet will allow the user to reach. */
const MAX_ZOOM = 20;

/**
 * <summary>
 * Highest zoom level for which tiles actually exist.
 * </summary>
 * <remarks>
 * CARTO <c>dark_all</c> and standard OpenStreetMap tiles top out at
 * zoom 19. Anything past that and the tile server returns 404, leaving
 * the map black against the dark page background. Pinning
 * <c>maxNativeZoom</c> one notch lower than <c>MAX_ZOOM</c> lets Leaflet
 * upscale the level 19 tiles for the final pinch in instead of asking
 * for tiles that do not exist.
 * </remarks>
 */
const MAX_NATIVE_ZOOM = 19;

/**
 * <summary>
 * URL and attribution for the dark CARTO basemap.
 * </summary>
 */
const DARK_TILES = {
  url: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
  attribution:
    '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> &copy; <a href="https://carto.com/attributions">CARTO</a>',
};

/**
 * <summary>
 * URL and attribution for the light standard OpenStreetMap basemap.
 * </summary>
 */
const LIGHT_TILES = {
  url: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
  attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
};

/**
 * <summary>
 * Builds a self contained SVG Leaflet <c>DivIcon</c> for a map pin in
 * the given fill and ring colour.
 * </summary>
 * <param name="fill">Pin body fill colour.</param>
 * <param name="ring">Stroke colour around the pin and its dot.</param>
 * <returns>A Leaflet <c>DivIcon</c> with the correct anchor offsets.</returns>
 * <remarks>
 * Self contained means no CDN, no missing image fallback, and the
 * colour is fully controllable.
 * </remarks>
 */
function pinIcon(fill: string, ring: string): L.DivIcon {
  const svg = `
    <svg xmlns="http://www.w3.org/2000/svg" width="30" height="42" viewBox="0 0 30 42">
      <path d="M15 0C6.7 0 0 6.7 0 15c0 11.3 15 27 15 27s15-15.7 15-27C30 6.7 23.3 0 15 0z"
            fill="${fill}" stroke="${ring}" stroke-width="2"/>
      <circle cx="15" cy="15" r="5.5" fill="#ffffff"/>
    </svg>`;
  return L.divIcon({
    html: svg,
    className: 'svg-pin',
    iconSize: [30, 42],
    iconAnchor: [15, 42],
    popupAnchor: [0, -42],
  });
}

/** Pin icon used for journey start points. Eco green body, dark green ring. */
const startIcon = pinIcon('#6ec546', '#0e1a08');
/** Pin icon used for journey end points. Red body, dark red ring. */
const endIcon   = pinIcon('#e56b6b', '#2a0a0a');

/**
 * <summary>
 * Props for <see cref="JourneyMap"/>.
 * </summary>
 */
type Props = {
  /** All of the user's journeys. Their routes are drawn as background polylines. */
  journeys: Journey[];
  /** Currently selected journey, whose pins and route are drawn highlighted. */
  selected: Journey | null;
  /** Active editor draft, drawn with draggable pins. <c>null</c> when no editor is open. */
  draft: DraftJourney | null;
  /** Receives draft updates when the user clicks the map or drags a pin. */
  onDraftChange: (d: DraftJourney) => void;
  /**
   * External camera target (e.g. JourneyEditor pin focus). The <c>tick</c>
   * field lets the same lat / lng re fire, which is useful when the user
   * clicks the same target button twice and expects the map to recentre
   * each time.
   */
  flyTo?: { ll: [number, number]; tick: number } | null;
  /** Initial basemap theme. Updated locally when the user toggles. */
  initialTheme?: 'dark' | 'light';
};

/**
 * <summary>
 * Tests whether a coordinate is within the tight Guernsey bounding box.
 * </summary>
 * <param name="lat">Latitude.</param>
 * <param name="lng">Longitude.</param>
 * <returns><c>true</c> when the point sits inside <see cref="GUERNSEY_BOUNDS"/>.</returns>
 */
function inGuernsey(lat: number, lng: number): boolean {
  const [[s, w], [n, e]] = GUERNSEY_BOUNDS;
  return lat >= s && lat <= n && lng >= w && lng <= e;
}

/**
 * <summary>
 * Invisible Leaflet child that translates map clicks into draft pin
 * updates. Fills <c>start</c> first, then <c>end</c>, ignoring clicks
 * outside Guernsey.
 * </summary>
 * <param name="draft">Current editor draft, or <c>null</c> when no editor is open.</param>
 * <param name="onDraftChange">Receives the updated draft.</param>
 */
function DraftClickHandler({ draft, onDraftChange }: { draft: DraftJourney | null; onDraftChange: (d: DraftJourney) => void }) {
  const draftRef = useRef(draft);
  draftRef.current = draft;

  useMapEvents({
    click(e) {
      const d = draftRef.current;
      if (!d) return;
      if (!inGuernsey(e.latlng.lat, e.latlng.lng)) return;
      const ll: LatLng = { lat: e.latlng.lat, lng: e.latlng.lng };
      if (!d.start)    onDraftChange({ ...d, start: ll });
      else if (!d.end) onDraftChange({ ...d, end:   ll });
    },
  });
  return null;
}

/**
 * <summary>
 * Invisible Leaflet child that flies the camera to <c>target</c> when
 * it changes. The zoom level is the larger of the current zoom and 12.
 * </summary>
 * <param name="target">Camera target lat / lng, or <c>null</c> for no fly.</param>
 */
function FlyTo({ target }: { target: [number, number] | null }) {
  const map = useMap();
  useEffect(() => {
    if (target) map.flyTo(target, Math.max(map.getZoom(), 12), { duration: 0.6 });
  }, [target, map]);
  return null;
}

/**
 * <summary>
 * Camera fly variant that re fires whenever the <c>tick</c> field of
 * the payload changes, so the same lat / lng can be flown to multiple
 * times in a row (e.g. clicking the same pin target button twice).
 * </summary>
 * <param name="payload">Lat / lng plus an incrementing tick. <c>null</c> disables.</param>
 */
function FlyToOnTick({ payload }: { payload: { ll: [number, number]; tick: number } | null }) {
  const map = useMap();
  useEffect(() => {
    if (!payload) return;
    map.flyTo(payload.ll, Math.max(map.getZoom(), 14), { duration: 0.6 });
  }, [payload?.tick, map]);
  return null;
}

/**
 * <summary>
 * Refit the camera onto Guernsey on first load when there are no
 * journeys or draft to centre on, otherwise leave zoom untouched.
 * </summary>
 * <param name="refit">When <c>true</c> the camera fits Guernsey bounds on mount.</param>
 * <remarks>
 * Lets the user start looking at the right place but still freely zoom
 * out as far as <see cref="MIN_ZOOM"/> allows to see the wider Channel
 * Islands area for context.
 * </remarks>
 */
function GuernseyFit({ refit }: { refit: boolean }) {
  const map = useMap();
  useEffect(() => {
    if (!refit) return;
    map.fitBounds(GUERNSEY_BOUNDS, { padding: [10, 10], animate: false });
  }, [refit, map]);
  return null;
}

/**
 * <summary>
 * Main interactive Leaflet map. Shows the user's journey routes and
 * pins, the active editor draft (with draggable pins), and a basemap
 * theme toggle. Clicking the map drops draft pins when the editor is open.
 * </summary>
 * <param name="journeys">User's journeys, drawn as background polylines.</param>
 * <param name="selected">Currently selected journey, drawn highlighted.</param>
 * <param name="draft">Active editor draft, or <c>null</c>.</param>
 * <param name="onDraftChange">Receives draft updates from clicks and pin drags.</param>
 * <param name="flyTo">External camera target with a tick for re firing.</param>
 * <param name="initialTheme">Starting basemap theme.</param>
 * <remarks>
 * Centring, fly to, and pin drawing all use the <em>effective</em>
 * journey via <c>effectiveJourney</c>, so a passenger with an accepted
 * lift sees the driver's pickup and dropoff rather than their own
 * original pins. Drivers and unconnected passengers see their own pins
 * as before.
 *
 * Pin drags that land outside Guernsey are rejected and the marker
 * snaps back to its previous position.
 * </remarks>
 */
export function JourneyMap({ journeys, selected, draft, onDraftChange, flyTo, initialTheme = 'dark' }: Props) {
  const draftRef = useRef(draft);
  draftRef.current = draft;

  const [theme, setTheme] = useState<'dark' | 'light'>(initialTheme);
  // Live app version for the corner badge. Shared cache with the login
  // page, so this costs no extra request.
  const version = useVersion();
  useEffect(() => { setTheme(initialTheme); }, [initialTheme]);
  const tiles = theme === 'dark' ? DARK_TILES : LIGHT_TILES;

  // Centring + flyTo + pin drawing all use the *effective* journey so a
  // passenger with an accepted lift sees the driver's pickup/dropoff, not
  // their own original pins. Drivers and unconnected passengers see their
  // own pins as before.
  const selectedEff = selected ? effectiveJourney(selected) : null;
  const firstEff    = journeys[0] ? effectiveJourney(journeys[0]) : null;
  const center: [number, number] = selectedEff
    ? [selectedEff.start_lat, selectedEff.start_lng]
    : firstEff
      ? [firstEff.start_lat, firstEff.start_lng]
      : GUERNSEY_CENTER;

  return (
    <MapContainer
      center={center}
      zoom={DEFAULT_ZOOM}
      minZoom={MIN_ZOOM}
      maxZoom={MAX_ZOOM}
      maxBounds={PAN_BOUNDS}
      maxBoundsViscosity={0.7}
      className="map"
      zoomControl={false}
      attributionControl={false}
    >
      <TileLayer
        key={theme}
        url={tiles.url}
        attribution={tiles.attribution}
        maxZoom={MAX_ZOOM}
        maxNativeZoom={MAX_NATIVE_ZOOM}
      />
      <ZoomControl position="bottomright" />

      {version && (
        <div className="map-version" aria-label={`SwiftLift version ${version}`}>
          v{version}
        </div>
      )}

      <button
        className="basemap-toggle"
        title={theme === 'dark' ? 'Switch to light basemap' : 'Switch to dark basemap'}
        aria-label={theme === 'dark' ? 'Switch to light basemap' : 'Switch to dark basemap'}
        onClick={() => setTheme(t => (t === 'dark' ? 'light' : 'dark'))}
      >
        {/* Inline SVG so it renders identically on every platform,
            the old ☀ / ☾ unicode chars were auto-substituted with
            full-colour emoji on iOS and Android, which looked wrong
            against the muted button chrome. */}
        {theme === 'dark' ? (
          // Sun (shown when on dark basemap; click → light)
          <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"
               fill="none" stroke="currentColor" strokeWidth="2"
               strokeLinecap="round" strokeLinejoin="round">
            <circle cx="12" cy="12" r="4" />
            <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41" />
          </svg>
        ) : (
          // Moon (shown when on light basemap; click → dark)
          <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"
               fill="currentColor">
            <path d="M21 12.79A9 9 0 1 1 11.21 3a7 7 0 0 0 9.79 9.79z" />
          </svg>
        )}
      </button>

      <DraftClickHandler draft={draft} onDraftChange={onDraftChange} />
      <GuernseyFit refit={!selected && journeys.length === 0 && !draft} />
      <FlyTo target={selectedEff ? [selectedEff.start_lat, selectedEff.start_lng] : null} />
      <FlyToOnTick payload={flyTo ?? null} />

      {/* Only draw a polyline if the journey actually has a stored road route.
          Journeys saved without "Follow roads" show as just their two pins.
          A connected passenger draws the *driver's* route. */}
      {journeys.map(j => {
        const eff = effectiveJourney(j);
        const route = wktToCoords(eff.route_wkt);
        if (!route) return null;
        const isSelected = selected?.id === j.id;
        return (
          <Polyline
            key={`j-${j.id}`}
            positions={route}
            pathOptions={{
              color: isSelected ? '#8fd66a' : '#6a6a6a',
              weight: isSelected ? 5 : 3,
              opacity: isSelected ? 0.95 : 0.6,
            }}
          />
        );
      })}

      {selected && selectedEff && (
        <>
          <Marker position={[selectedEff.start_lat, selectedEff.start_lng]} icon={startIcon}>
            <Tooltip permanent direction="top" offset={[0, -32]} className="pin-label start">
              {selectedEff.connected_to ? 'Pickup' : 'Start'}
            </Tooltip>
          </Marker>
          <Marker position={[selectedEff.end_lat, selectedEff.end_lng]} icon={endIcon}>
            <Tooltip permanent direction="top" offset={[0, -32]} className="pin-label end">
              {selectedEff.connected_to ? 'Dropoff' : 'End'}
            </Tooltip>
          </Marker>
        </>
      )}


      {draft?.start && (
        <Marker
          position={[draft.start.lat, draft.start.lng]}
          icon={startIcon}
          draggable
          eventHandlers={{
            dragend: e => {
              const d = draftRef.current;
              const m = e.target as L.Marker;
              const ll = m.getLatLng();
              if (!inGuernsey(ll.lat, ll.lng)) {
                m.setLatLng([draft.start!.lat, draft.start!.lng]);
                return;
              }
              if (d) onDraftChange({ ...d, start: { lat: ll.lat, lng: ll.lng } });
            },
          }}
        >
          <Tooltip permanent direction="top" offset={[0, -32]} className="pin-label start">Start</Tooltip>
        </Marker>
      )}
      {draft?.end && (
        <Marker
          position={[draft.end.lat, draft.end.lng]}
          icon={endIcon}
          draggable
          eventHandlers={{
            dragend: e => {
              const d = draftRef.current;
              const m = e.target as L.Marker;
              const ll = m.getLatLng();
              if (!inGuernsey(ll.lat, ll.lng)) {
                m.setLatLng([draft.end!.lat, draft.end!.lng]);
                return;
              }
              if (d) onDraftChange({ ...d, end: { lat: ll.lat, lng: ll.lng } });
            },
          }}
        >
          <Tooltip permanent direction="top" offset={[0, -32]} className="pin-label end">End</Tooltip>
        </Marker>
      )}
      {/* Draft route polyline appears only if the user enabled "Follow roads"
          AND OSRM successfully returned a route. Otherwise: just the two pins. */}
      {draft?.start && draft.end && draft.use_routing && (() => {
        const routed = wktToCoords(draft.route_wkt ?? null);
        if (!routed) return null;
        return (
          <>
            <Polyline positions={routed} pathOptions={{ color: '#0e1a08', weight: 7, opacity: 0.6 }} />
            <Polyline positions={routed} pathOptions={{ color: '#6ec546', weight: 4, opacity: 0.95 }} />
          </>
        );
      })()}
    </MapContainer>
  );
}
