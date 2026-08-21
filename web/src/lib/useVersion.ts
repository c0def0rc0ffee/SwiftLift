import { useEffect, useState } from 'react';
import { api } from '../api';

/**
 * <summary>
 * Module-level cache for the app version so every consumer shares one
 * network request per page load, regardless of mount order or count.
 * </summary>
 */
let cached: string | null = null;
let inflight: Promise<string | null> | null = null;

/**
 * <summary>
 * Fetches the current app version (the same value the About page
 * shows), caching it for the lifetime of the page.
 * </summary>
 * <returns>The version string, or null while loading / on failure.</returns>
 * <remarks>
 * Rides the unauthenticated /api/issues endpoint, which already returns
 * the live version from app_meta. Failure is silent, a version badge
 * is decoration, so a fetch error just leaves it hidden rather than
 * surfacing an error state.
 * </remarks>
 */
export function useVersion(): string | null {
  const [version, setVersion] = useState<string | null>(cached);

  useEffect(() => {
    if (cached !== null) return;
    let alive = true;
    inflight ??= api.listIssues()
      .then(r => (cached = r.version))
      .catch(() => null);
    inflight.then(v => { if (alive && v) setVersion(v); });
    return () => { alive = false; };
  }, []);

  return version;
}
