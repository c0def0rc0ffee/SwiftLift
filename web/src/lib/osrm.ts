/**
 * <summary>
 * Simple latitude and longitude pair used as the input to OSRM
 * routing helpers.
 * </summary>
 */
export type LatLng = { lat: number; lng: number };

/**
 * <summary>
 * Driving route returned by `fetchRoute`. Bundles a Leaflet ready
 * coordinate array, a WKT LINESTRING for the PHP API, and the
 * upstream distance and duration estimates.
 * </summary>
 * <remarks>
 * `coords` is in `[lat, lng]` order to match Leaflet's Polyline
 * input. `wkt` keeps the original `lng lat` order so it stays
 * compatible with the standard WKT convention and the backend
 * spatial indexes.
 * </remarks>
 */
export type OsrmRoute = {
  coords: [number, number][];
  wkt: string;
  distance_m: number;
  duration_s: number;
};

/**
 * <summary>
 * Fetches a driving route between two points from the public OSRM
 * server and returns it in two shapes ready for the Leaflet map and
 * the SwiftLift backend.
 * </summary>
 * <param name="start">The journey's start point.</param>
 * <param name="end">The journey's end point.</param>
 * <param name="signal">Optional AbortSignal for cancelling a stale fetch.</param>
 * <returns>
 * An OsrmRoute with the polyline coordinates, a WKT LINESTRING, and
 * the OSRM reported distance and duration; null when the server
 * returned no routes for the pair.
 * </returns>
 * <remarks>
 * Calls `router.project-osrm.org` with `overview=full` and
 * `geometries=geojson` so the response includes the full polyline.
 * GeoJSON returns coordinates as `[lng, lat]`; this function flips
 * them to `[lat, lng]` for Leaflet and keeps `lng lat` for the WKT
 * string. Throws when the upstream responds with a non success
 * status.
 * </remarks>
 */
export async function fetchRoute(start: LatLng, end: LatLng, signal?: AbortSignal): Promise<OsrmRoute | null> {
  const url =
    `https://router.project-osrm.org/route/v1/driving/` +
    `${start.lng},${start.lat};${end.lng},${end.lat}` +
    `?overview=full&geometries=geojson`;

  const res = await fetch(url, { signal });
  if (!res.ok) throw new Error(`OSRM ${res.status}`);
  const data = await res.json();

  const route = data?.routes?.[0];
  if (!route) return null;

  // GeoJSON uses [lng, lat]; Leaflet wants [lat, lng].
  const raw: [number, number][] = route.geometry.coordinates;
  const coords = raw.map<[number, number]>(([lng, lat]) => [lat, lng]);

  const wkt = 'LINESTRING(' + raw.map(([lng, lat]) => `${lng} ${lat}`).join(', ') + ')';

  return {
    coords,
    wkt,
    distance_m: route.distance ?? 0,
    duration_s: route.duration ?? 0,
  };
}

/**
 * <summary>
 * Parses a WKT LINESTRING (as stored in the database) back into the
 * `[lat, lng]` coordinate array Leaflet expects.
 * </summary>
 * <param name="wkt">A WKT LINESTRING in `lng lat` order, or null.</param>
 * <returns>
 * Coordinates in `[lat, lng]` order, or null when the input is
 * null or does not match the expected `LINESTRING(...)` shape.
 * </returns>
 * <remarks>
 * The regular expression is intentionally permissive about leading
 * and trailing whitespace and is case insensitive on the keyword.
 * Individual coordinate pairs are split on any run of whitespace so
 * both `1.2 3.4` and `1.2  3.4` parse correctly.
 * </remarks>
 */
export function wktToCoords(wkt: string | null): [number, number][] | null {
  if (!wkt) return null;
  const m = wkt.match(/^LINESTRING\s*\((.+)\)\s*$/i);
  if (!m) return null;
  return m[1].split(',').map(pair => {
    const [lng, lat] = pair.trim().split(/\s+/).map(Number);
    return [lat, lng] as [number, number];
  });
}
