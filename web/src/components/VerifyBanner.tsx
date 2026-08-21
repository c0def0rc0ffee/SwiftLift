import { useState } from 'react';
import { api } from '../api';

/**
 * <summary>
 * Local storage key tracking when the banner snooze expires.
 * </summary>
 */
const SNOOZE_KEY = 'sl_verify_banner_snoozed_until';

/**
 * <summary>
 * Snooze duration in milliseconds (24 hours).
 * </summary>
 */
const SNOOZE_MS  = 24 * 60 * 60 * 1000;

/**
 * <summary>
 * Friendly nudge shown across the top of the app when a user is
 * unverified but still within the soft remind window (REMIND_DAYS).
 * Offers a resend link, a humanised countdown to the lock deadline,
 * and a 24 hour snooze.
 * </summary>
 * <param name="email">The email address awaiting verification.</param>
 * <param name="forceAt">ISO 8601 timestamp at which the account moves
 * from remind to forced. The banner displays it as a humanised
 * "in 3 days" countdown so the user sees the deadline approaching.</param>
 * <remarks>
 * Past the remind window the backend returns
 * <c>verify_state='forced'</c> and the UI renders
 * <see cref="VerifyGate"/> (a full screen blocker) instead.
 *
 * Snooze state is kept in localStorage so it survives reloads but is
 * scoped to the device.
 * </remarks>
 */
export function VerifyBanner({ email, forceAt }: { email: string; forceAt?: string | null }) {
  const [hidden, setHidden] = useState(() => {
    const until = Number(localStorage.getItem(SNOOZE_KEY) || 0);
    return Number.isFinite(until) && Date.now() < until;
  });
  const [busy, setBusy]   = useState(false);
  const [sent, setSent]   = useState(false);
  const [err, setErr]     = useState<string | null>(null);

  if (hidden) return null;

  /**
   * <summary>
   * Re sends the verification email and reports success or failure
   * inline.
   * </summary>
   */
  async function resend() {
    setBusy(true); setErr(null);
    try {
      await api.sendVerifyEmail();
      setSent(true);
    } catch (e) {
      setErr(e instanceof Error ? e.message : String(e));
    } finally {
      setBusy(false);
    }
  }

  /**
   * <summary>
   * Hides the banner for 24 hours by recording the next show time in
   * localStorage.
   * </summary>
   */
  function snooze() {
    localStorage.setItem(SNOOZE_KEY, String(Date.now() + SNOOZE_MS));
    setHidden(true);
  }

  const deadline = forceAt ? humanDeadline(forceAt) : null;

  return (
    <div className="verify-banner">
      <button
        type="button"
        className="verify-banner-close"
        aria-label="Hide for 24 hours"
        title="Hide for 24 hours"
        onClick={snooze}
      >×</button>
      <span>
        Confirm your email <b>{email}</b> to make sure you don't lose access.
        {deadline && <> The app will be locked {deadline} until you do.</>}
      </span>
      {sent ? (
        <span className="muted small">Sent. Check your inbox.</span>
      ) : (
        <button className="small primary" onClick={resend} disabled={busy}>{busy ? '…' : 'Resend link'}</button>
      )}
      {err && <span className="error small">{err}</span>}
    </div>
  );
}

/**
 * <summary>
 * Renders an ISO timestamp as a short countdown fragment such as
 * "soon", "tomorrow", or "in 3 days".
 * </summary>
 * <param name="iso">ISO 8601 timestamp.</param>
 * <returns>Short countdown phrase, or empty string when the date is unparseable.</returns>
 */
function humanDeadline(iso: string): string {
  const t = Date.parse(iso);
  if (!Number.isFinite(t)) return '';
  const days = Math.ceil((t - Date.now()) / 86400000);
  if (days <= 0) return 'soon';
  if (days === 1) return 'tomorrow';
  return `in ${days} days`;
}
