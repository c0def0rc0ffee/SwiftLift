/**
 * <summary>
 * Returns the next datetime in the user's local timezone at which a
 * recurring journey is scheduled to run, given a weekday bitmask and
 * an `HH:MM` start time.
 * </summary>
 * <param name="daysMask">
 * Seven bit bitmask of weekdays. Bit 0 is Monday and bit 6 is
 * Sunday. A mask of zero means the journey never runs.
 * </param>
 * <param name="hhmm">Start time in `HH:MM` 24 hour format.</param>
 * <param name="now">Reference instant. Defaults to the current time; injectable for tests.</param>
 * <returns>The next occurrence as a Date in local time, or null if the mask is empty or `hhmm` is invalid.</returns>
 * <remarks>
 * Iterates through the next eight days starting with today,
 * skipping days that are not set in the mask and skipping today if
 * the start time has already passed. Eight rather than seven because
 * a single-weekday journey whose slot has already passed today must
 * fall through to the same weekday *next* week; stopping at seven
 * would return null for the rest of the day. JavaScript's `Date.getDay()`
 * returns 0 for Sunday through 6 for Saturday whereas the database
 * uses 0 for Monday; the translation is applied at the boundary.
 * </remarks>
 */
export function nextOccurrence(daysMask: number, hhmm: string, now: Date = new Date()): Date | null {
  if (!daysMask) return null;
  const [hh, mm] = hhmm.split(':').map(Number);
  if (!Number.isFinite(hh) || !Number.isFinite(mm)) return null;

  for (let i = 0; i < 8; i++) {
    const d = new Date(now);
    d.setDate(now.getDate() + i);
    d.setHours(hh, mm, 0, 0);

    const jsDay = d.getDay();             // 0 Sun … 6 Sat
    const bit   = jsDay === 0 ? 6 : jsDay - 1; // 0 Mon … 6 Sun
    if (!(daysMask & (1 << bit))) continue;
    if (i === 0 && d.getTime() <= now.getTime()) continue; // today's slot has passed
    return d;
  }
  return null;
}

/**
 * <summary>
 * Formats the output of `nextOccurrence` as a short, locale aware
 * label for use in journey summaries.
 * </summary>
 * <param name="date">The datetime to format, or null.</param>
 * <returns>
 * `"Today · HH:MM"`, `"Tomorrow · HH:MM"`, a short weekday label,
 * or a `"Mon, 3 Jun · HH:MM"` style label for dates a week or more
 * out. Returns an em dash when the input is null.
 * </returns>
 * <remarks>
 * Day arithmetic is performed by zeroing out the time of day on
 * both sides so daylight saving transitions never push a
 * "tomorrow" result into "today" or vice versa. Times are rendered
 * in 24 hour format to match the rest of the application.
 * </remarks>
 */
export function fmtNextOccurrence(date: Date | null): string {
  if (!date) return '-';
  const now = new Date();
  const today = new Date(now); today.setHours(0, 0, 0, 0);
  const target = new Date(date); target.setHours(0, 0, 0, 0);
  const days = Math.round((target.getTime() - today.getTime()) / 86_400_000);

  const time = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });
  if (days === 0) return `Today · ${time}`;
  if (days === 1) return `Tomorrow · ${time}`;
  if (days < 7)   return `${date.toLocaleDateString([], { weekday: 'short' })} · ${time}`;
  return `${date.toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' })} · ${time}`;
}
