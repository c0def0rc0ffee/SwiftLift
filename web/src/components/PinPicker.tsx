import { useEffect, useState } from 'react';
import { MapContainer, Marker, TileLayer, useMap, useMapEvents } from 'react-leaflet';
import L from 'leaflet';

// Mini map for picking a single lat/lng. Reuses the same Guernsey constraints
// as the main JourneyMap, pins must land on the island.

/**
 * <summary>
 * Default centre of the mini map (roughly mid Guernsey).
 * </summary>
 */
const GUERNSEY_CENTER: [number, number] = [49.456, -2.55];

/**
 * <summary>
 * Tight Guernsey bounding box used to clamp where pins can be dropped.
 * </summary>
 */
const GUERNSEY_BOUNDS: L.LatLngBoundsLiteral = [
  [49.41, -2.70],
  [49.52, -2.47],
];

/**
 * <summary>
 * Tile URL and attribution for the OpenStreetMap basemap.
 * </summary>
 */
const TILES = {
  url: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
  attribution: '&copy; OpenStreetMap',
};

/**
 * <summary>
 * Builds the single blue Leaflet pin icon used by the picker.
 * </summary>
 * <returns>A Leaflet <c>DivIcon</c> with the correct anchor offsets.</returns>
 */
function pinIcon(): L.DivIcon {
  return L.divIcon({
    html: `<svg xmlns="http://www.w3.org/2000/svg" width="30" height="42" viewBox="0 0 30 42">
      <path d="M15 0C6.7 0 0 6.7 0 15c0 11.3 15 27 15 27s15-15.7 15-27C30 6.7 23.3 0 15 0z"
            fill="#3a86ff" stroke="#0a1f3a" stroke-width="2"/>
      <circle cx="15" cy="15" r="5.5" fill="#ffffff"/>
    </svg>`,
    className: 'svg-pin',
    iconSize: [30, 42],
    iconAnchor: [15, 42],
  });
}

/**
 * <summary>
 * Memoised pin icon instance shared across all PinPicker renders.
 * </summary>
 */
const PIN = pinIcon();

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
 * Invisible Leaflet child that translates map clicks into pin picks,
 * ignoring clicks outside Guernsey.
 * </summary>
 * <param name="onPick">Receives the latitude / longitude of valid clicks.</param>
 */
function ClickHandler({ onPick }: { onPick: (lat: number, lng: number) => void }) {
  useMapEvents({
    click(e) {
      if (!inGuernsey(e.latlng.lat, e.latlng.lng)) return;
      onPick(e.latlng.lat, e.latlng.lng);
    },
  });
  return null;
}

/**
 * <summary>
 * Invisible Leaflet child that flies the camera to <c>pin</c> whenever
 * its coordinates change.
 * </summary>
 * <param name="pin">Target pin position, or <c>null</c> for no fly.</param>
 */
function FlyToPin({ pin }: { pin: { lat: number; lng: number } | null }) {
  const map = useMap();
  useEffect(() => {
    if (pin) map.flyTo([pin.lat, pin.lng], Math.max(map.getZoom(), 14), { duration: 0.4 });
  }, [pin?.lat, pin?.lng, map]);
  return null;
}

/**
 * <summary>
 * Props for <see cref="PinPicker"/>.
 * </summary>
 */
type Props = {
  /** Current pin position, or <c>null</c> when none is set. */
  value: { lat: number; lng: number } | null;
  /** Receives the new pin position, or <c>null</c> when the user clears it. */
  onChange: (v: { lat: number; lng: number } | null) => void;
  /** Extra class names merged onto the wrapper. */
  className?: string;
};

/**
 * <summary>
 * Compact embedded map for picking a single Guernsey lat / lng. Offers
 * tap to drop, drag to adjust, "use my location", and a clear button.
 * </summary>
 * <param name="value">Current pin position.</param>
 * <param name="onChange">Receives pin updates and clears.</param>
 * <param name="className">Extra class names.</param>
 * <remarks>
 * Uses the same Guernsey constraint as the main map; clicks and drags
 * outside the island are rejected. Geolocation falls back silently when
 * the user is off island.
 * </remarks>
 */
export function PinPicker({ value, onChange, className }: Props) {
  const [geoBusy, setGeoBusy] = useState(false);
  // Why the last "Use my location" press produced nothing, if it didn't.
  const [geoErr, setGeoErr]   = useState<string | null>(null);

  /**
   * <summary>
   * Asks the browser for the current position and drops a pin there if
   * it lands inside Guernsey.
   * </summary>
   * <remarks>
   * Reports why nothing happened rather than bailing silently: a denied
   * permission, a timeout, and a fix that lands off-island are all
   * indistinguishable from a dead button otherwise. The high accuracy
   * flag is requested with an 8 second timeout. Mirrors the equivalent
   * handler in JourneyEditor, which already surfaced its errors.
   * </remarks>
   */
  function useMyLocation() {
    setGeoErr(null);
    if (!navigator.geolocation) {
      setGeoErr('This browser cannot share your location.');
      return;
    }
    setGeoBusy(true);
    navigator.geolocation.getCurrentPosition(
      pos => {
        setGeoBusy(false);
        const { latitude, longitude } = pos.coords;
        if (!inGuernsey(latitude, longitude)) {
          setGeoErr("That fix looks like it's outside Guernsey. Drop the pin on the map instead.");
          return;
        }
        onChange({ lat: latitude, lng: longitude });
      },
      err => {
        setGeoBusy(false);
        setGeoErr(
          err.code === err.PERMISSION_DENIED
            ? 'Location permission was denied. Drop the pin on the map instead.'
            : err.code === err.TIMEOUT
              ? "Couldn't get a fix in time. Try again, or drop the pin on the map."
              : "Couldn't get your location. Drop the pin on the map instead."
        );
      },
      { enableHighAccuracy: true, timeout: 8000 }
    );
  }

  return (
    <div className={`pin-picker ${className ?? ''}`}>
      <div className="pin-picker-actions">
        <button type="button" className="ghost small" onClick={useMyLocation} disabled={geoBusy}>
          {geoBusy ? 'Locating…' : '📍 Use my location'}
        </button>
        {value && (
          <button type="button" className="ghost small" onClick={() => onChange(null)}>
            Clear pin
          </button>
        )}
      </div>
      {geoErr && <div className="muted small" role="status">{geoErr}</div>}
      <MapContainer
        center={value ? [value.lat, value.lng] : GUERNSEY_CENTER}
        zoom={value ? 15 : 12}
        minZoom={11}
        maxBounds={GUERNSEY_BOUNDS}
        maxBoundsViscosity={0.9}
        className="pin-picker-map"
        zoomControl={true}
        attributionControl={false}
      >
        <TileLayer url={TILES.url} attribution={TILES.attribution} />
        <ClickHandler onPick={(lat, lng) => onChange({ lat, lng })} />
        <FlyToPin pin={value} />
        {value && (
          <Marker
            position={[value.lat, value.lng]}
            icon={PIN}
            draggable={true}
            eventHandlers={{
              dragend: e => {
                const ll = e.target.getLatLng();
                if (!inGuernsey(ll.lat, ll.lng)) return;
                onChange({ lat: ll.lat, lng: ll.lng });
              },
            }}
          />
        )}
      </MapContainer>
      <div className="pin-picker-hint muted small">
        {value
          ? `${value.lat.toFixed(5)}, ${value.lng.toFixed(5)} · drag the pin to adjust`
          : 'Tap the map to drop a pin'}
      </div>
    </div>
  );
}
