// Unit toggle for distances. Lives in localStorage so the choice survives
// reloads but stays per-browser (no backend round-trip).

import { useSyncExternalStore } from 'react';

/**
 * <summary>
 * The display unit for distances. `'km'` is the default; `'mi'`
 * switches the entire UI over to miles and feet.
 * </summary>
 */
export type Unit = 'km' | 'mi';

/**
 * <summary>
 * `localStorage` key under which the unit preference is persisted.
 * </summary>
 */
const KEY = 'sl_unit';

/**
 * <summary>
 * Set of `useSyncExternalStore` subscribers that will be notified
 * whenever the unit preference changes.
 * </summary>
 */
const subs = new Set<() => void>();

/**
 * <summary>
 * Internal getter that returns the current unit preference, falling
 * back to `'km'` when running in an environment without
 * `localStorage` (server side rendering, tests).
 * </summary>
 * <returns>The current Unit.</returns>
 */
function read(): Unit {
  if (typeof localStorage === 'undefined') return 'km';
  return localStorage.getItem(KEY) === 'mi' ? 'mi' : 'km';
}

/**
 * <summary>
 * Persists the unit preference and notifies every subscriber so
 * components re render with the new choice.
 * </summary>
 * <param name="u">The unit to store.</param>
 * <remarks>
 * Writes are wrapped in a try/catch because some private browsing
 * modes throw on any localStorage write. A failure to persist still
 * fires subscribers so the rest of the session sees the new value.
 * </remarks>
 */
export function setUnit(u: Unit): void {
  try { localStorage.setItem(KEY, u); } catch { /* ignore */ }
  subs.forEach(s => s());
}

/**
 * <summary>
 * Flips the current unit between kilometres and miles.
 * </summary>
 * <remarks>
 * Convenience wrapper around `setUnit` for the unit toggle button.
 * </remarks>
 */
export function toggleUnit(): void {
  setUnit(read() === 'km' ? 'mi' : 'km');
}

/**
 * <summary>
 * React hook that subscribes the calling component to the unit
 * preference so it re renders whenever the value changes.
 * </summary>
 * <returns>The current Unit.</returns>
 * <remarks>
 * Implemented with `useSyncExternalStore`. The server snapshot is
 * pinned to `'km'` so SSR output matches the default in `read()`
 * before hydration.
 * </remarks>
 */
export function useUnit(): Unit {
  return useSyncExternalStore(
    cb => { subs.add(cb); return () => { subs.delete(cb); }; },
    read,
    () => 'km' as Unit,
  );
}

/**
 * <summary>
 * Formats a distance in metres as a short string in the chosen
 * unit.
 * </summary>
 * <param name="metres">The distance in metres.</param>
 * <param name="unit">The unit to format in. Defaults to the current preference.</param>
 * <returns>
 * A compact label such as `"450 m"`, `"3.2 km"`, `"820 ft"` or
 * `"4.7 mi"`. Sub kilometre and sub mile values use the smaller
 * unit (metres or feet) and round to the nearest whole.
 * </returns>
 * <remarks>
 * The imperial threshold is 305 metres, which is roughly one
 * thousand feet; below that the value is shown in feet. The metric
 * threshold is one kilometre.
 * </remarks>
 */
export function fmtDistance(metres: number, unit: Unit = read()): string {
  if (unit === 'mi') {
    if (metres < 305) return `${Math.round(metres * 3.28084)} ft`;
    return `${(metres / 1609.344).toFixed(1)} mi`;
  }
  if (metres < 1000) return `${Math.round(metres)} m`;
  return `${(metres / 1000).toFixed(1)} km`;
}


/**
 * <summary>
 * Formats a duration in minutes for time window pickers and journey
 * labels.
 * </summary>
 * <param name="minutes">The duration in minutes. Negative values are clamped to zero.</param>
 * <returns>
 * `"30 min"` and `"45 min"` for sub hour values; `"1 hr"`,
 * `"1 hr 30 min"`, `"2 hr 15 min"` from sixty minutes upwards.
 * </returns>
 * <remarks>
 * The longer form switches in at the hour mark because labels such
 * as `"240 min"` are harder to read at a glance than `"4 hr"`. The
 * value is rounded to the nearest minute before formatting.
 * </remarks>
 */
export function fmtMinutes(minutes: number): string {
  const m = Math.max(0, Math.round(minutes));
  if (m < 60) return `${m} min`;
  const hours = Math.floor(m / 60);
  const rem   = m % 60;
  if (rem === 0) return `${hours} hr`;
  return `${hours} hr ${rem} min`;
}
