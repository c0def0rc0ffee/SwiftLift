import type { Journey } from '../api';

/**
 * <summary>
 * Subset of Journey describing how a journey should appear on the
 * map and in "leaves at" copy. Carries the pins, road route, start
 * time and a `connected_to` flag that names the driver when the
 * values come from a connection rather than the journey itself.
 * </summary>
 * <remarks>
 * `connected_to` is null for drivers and for passengers without an
 * accepted lift; in those cases the journey's own fields are used
 * verbatim. When non null it holds the driver's display name and the
 * other fields are taken from the connected driver, providing the
 * "be here, at this time" view for the passenger.
 * </remarks>
 */
export type EffectiveJourney = {
  start_lat: number;
  start_lng: number;
  end_lat: number;
  end_lng: number;
  route_wkt: string | null;
  start_time: string;
  /**
   * <summary>
   * Display name of the connected driver, or null when the values
   * came from the journey itself.
   * </summary>
   */
  connected_to: string | null;
};

/**
 * <summary>
 * Returns the journey's pickup, dropoff, start time and route as the
 * user should see them, applying the connected driver swap when one
 * exists.
 * </summary>
 * <param name="j">The journey to project.</param>
 * <returns>
 * An EffectiveJourney containing either the journey's own fields or
 * the connected driver's fields, with `connected_to` populated
 * accordingly.
 * </returns>
 * <remarks>
 * For a passenger journey with an accepted connection this returns
 * the driver's pickup, dropoff, departure time and (when computed)
 * road route, answering "where do I need to be and at what time".
 * For everyone else, including drivers and passengers without an
 * accepted lift, the journey's own values are returned unchanged.
 * </remarks>
 */
export function effectiveJourney(j: Journey): EffectiveJourney {
  const cd = j.connected_driver;
  if (cd) {
    return {
      start_lat:    cd.start_lat,
      start_lng:    cd.start_lng,
      end_lat:      cd.end_lat,
      end_lng:      cd.end_lng,
      route_wkt:    cd.route_wkt,
      start_time:   cd.start_time,
      connected_to: cd.driver_name,
    };
  }
  return {
    start_lat:    j.start_lat,
    start_lng:    j.start_lng,
    end_lat:      j.end_lat,
    end_lng:      j.end_lng,
    route_wkt:    j.route_wkt,
    start_time:   j.start_time,
    connected_to: null,
  };
}
