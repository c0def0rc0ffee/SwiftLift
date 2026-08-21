// Thin wrapper around Nominatim. Public usage requires a meaningful User-Agent
// and rate-limit etiquette (≤ 1 req/sec). Browser fetch can't set User-Agent
// directly, but Nominatim accepts the Referer + Origin headers from the browser.
//
// We bias the search towards Guernsey by passing a viewbox + countrycodes,
// but allow nearby Channel Islands too.

/**
 * <summary>
 * Base URL of the public Nominatim instance run by OpenStreetMap.
 * </summary>
 */
const NOMINATIM = 'https://nominatim.openstreetmap.org';

/**
 * <summary>
 * Rough bounding box around the Bailiwick of Guernsey including
 * Sark, Herm and Alderney. Encoded as `left,top,right,bottom`.
 * </summary>
 * <remarks>
 * Passed to Nominatim as a `viewbox` hint to bias results towards
 * the Channel Islands without limiting the search to that area;
 * `bounded=0` keeps mainland results in scope for users typing
 * addresses on the ferry route.
 * </remarks>
 */
const GUERNSEY_VIEWBOX = '-2.75,49.78,-2.10,49.40';

/**
 * <summary>
 * Normalised result row returned by `searchAddress`. Bundles the
 * coordinates and the human readable label that Nominatim built
 * from the underlying OSM tags.
 * </summary>
 */
export type GeocodeResult = {
  lat: number;
  lng: number;
  display_name: string;
};

let lastSearchAt = 0;
/**
 * <summary>
 * Internal helper that delays the next Nominatim request to honour
 * the public service's "no more than one request per second" rule.
 * </summary>
 * <returns>A promise that resolves once it is safe to fire the next request.</returns>
 * <remarks>
 * Tracks the timestamp of the previous call in a module level
 * variable and sleeps until at least 1100 milliseconds have elapsed
 * since then. The extra 100 milliseconds of headroom covers clock
 * jitter and network skew so we never edge over the published rate
 * limit.
 * </remarks>
 */
async function ratelimit() {
  const now = Date.now();
  const wait = Math.max(0, 1100 - (now - lastSearchAt));
  if (wait > 0) await new Promise(r => setTimeout(r, wait));
  lastSearchAt = Date.now();
}

/**
 * <summary>
 * Performs a forward geocode against Nominatim, returning up to six
 * candidates for the supplied query.
 * </summary>
 * <param name="query">Free text search string. Returns an empty array when blank.</param>
 * <param name="signal">Optional AbortSignal for cancelling the in flight call when the user keeps typing.</param>
 * <returns>An array of normalised GeocodeResult rows.</returns>
 * <remarks>
 * The search is biased towards Guernsey via the `viewbox` parameter
 * but is not bounded to it, so addresses in Jersey, mainland France
 * and the United Kingdom remain reachable. Rate limited to one
 * request per second via `ratelimit`. Throws if the upstream
 * service responds with a non success status.
 * </remarks>
 */
export async function searchAddress(query: string, signal?: AbortSignal): Promise<GeocodeResult[]> {
  if (!query.trim()) return [];
  await ratelimit();
  const params = new URLSearchParams({
    q: query,
    format: 'json',
    addressdetails: '0',
    limit: '6',
    viewbox: GUERNSEY_VIEWBOX,
    bounded: '0',           // bias, not limit
    countrycodes: 'gg,je,fr,gb',
  });
  const res = await fetch(`${NOMINATIM}/search?${params}`, { signal, headers: { 'Accept-Language': 'en' } });
  if (!res.ok) throw new Error(`Geocode ${res.status}`);
  const json: Array<{ lat: string; lon: string; display_name: string }> = await res.json();
  return json.map(r => ({ lat: Number(r.lat), lng: Number(r.lon), display_name: r.display_name }));
}

/**
 * <summary>
 * Reverse geocodes a coordinate to a short human readable label
 * suitable for displaying next to a dropped pin.
 * </summary>
 * <param name="lat">Latitude in degrees.</param>
 * <param name="lng">Longitude in degrees.</param>
 * <param name="signal">Optional AbortSignal for cancellation.</param>
 * <returns>A compact label such as "Rue des Mauxmarquis, St Peter Port", or null if the lookup fails.</returns>
 * <remarks>
 * Returns null rather than throwing on network or parse errors so
 * the caller can render a fallback like the raw coordinates. The
 * label is shortened by preferring road or neighbourhood level tags
 * over the full `display_name`, falling back to the first two
 * commas separated chunks when no usable address tag is present.
 * Rate limited via `ratelimit`.
 * </remarks>
 */
export async function reverseGeocode(lat: number, lng: number, signal?: AbortSignal): Promise<string | null> {
  await ratelimit();
  const params = new URLSearchParams({
    lat: String(lat), lon: String(lng),
    format: 'json',
    zoom: '17',
    addressdetails: '1',
  });
  try {
    const res = await fetch(`${NOMINATIM}/reverse?${params}`, { signal, headers: { 'Accept-Language': 'en' } });
    if (!res.ok) return null;
    const json: { display_name?: string; address?: Record<string, string> } = await res.json();
    if (!json) return null;
    // Prefer a short label: road / village / town. Fallback to first chunk of display_name.
    const a = json.address ?? {};
    const short = a.road || a.pedestrian || a.suburb || a.village || a.hamlet || a.town || a.city;
    if (short) {
      const area = a.suburb || a.village || a.town || a.city || a.county;
      return area && area !== short ? `${short}, ${area}` : short;
    }
    return json.display_name?.split(',').slice(0, 2).join(',').trim() ?? null;
  } catch {
    return null;
  }
}

/**
 * <summary>
 * Wraps `navigator.geolocation.getCurrentPosition` in a promise so
 * callers can `await` the user's current coordinates.
 * </summary>
 * <returns>
 * The user's `{lat, lng}`. The return type also allows `null`, but
 * in practice the function either resolves with coordinates or
 * rejects.
 * </returns>
 * <remarks>
 * Requests high accuracy with an eight second timeout and accepts
 * cached fixes up to thirty seconds old. Throws synchronously if
 * the browser does not expose `navigator.geolocation` at all, and
 * rejects with the underlying GeolocationPositionError message when
 * the user denies permission or the device fails to fix.
 * </remarks>
 */
export async function geolocateMe(): Promise<{ lat: number; lng: number } | null> {
  if (!navigator.geolocation) throw new Error('Geolocation not available in this browser');
  return new Promise((resolve, reject) => {
    navigator.geolocation.getCurrentPosition(
      pos => resolve({ lat: pos.coords.latitude, lng: pos.coords.longitude }),
      err => reject(new Error(err.message || 'Could not read your location')),
      { enableHighAccuracy: true, timeout: 8000, maximumAge: 30_000 }
    );
  });
}
