import { useState } from 'react';
import { api, User } from '../api';
import { BrandMark } from './BrandMark';

/**
 * <summary>
 * Full screen blocker shown when the user is unverified past the
 * remind window. Replaces the whole app with a "verify or you're out"
 * screen, offering three escape hatches: resend the verify link, fix
 * the email address (in case of typo), or sign out.
 * </summary>
 * <param name="user">Signed in user awaiting verification.</param>
 * <param name="onUserUpdated">Receives the updated user after an email change.</param>
 * <param name="onLogout">Called after the user signs out from the gate.</param>
 * <remarks>
 * The backend enforces the gate independently: every non auth, non
 * profile API call returns 403 with a <c>verify_state='forced'</c>
 * error past REMIND_DAYS but before FORCE_DAYS. This component is the
 * UI side of that.
 *
 * Changing the email requires the current password (audit H5 fix) and
 * triggers a fresh verify email to the new address as a server side
 * side effect.
 *
 * Displays a humanised days remaining countdown when
 * <c>user.verify_lock_at</c> is present.
 * </remarks>
 */
export function VerifyGate({
  user,
  onUserUpdated,
  onLogout,
}: {
  user: User;
  onUserUpdated: (u: User) => void;
  onLogout: () => void;
}) {
  const [busy, setBusy] = useState(false);
  const [sent, setSent] = useState(false);
  const [err,  setErr]  = useState<string | null>(null);

  const [editingEmail, setEditingEmail] = useState(false);
  const [newEmail, setNewEmail]   = useState(user.email);
  const [currentPw, setCurrentPw] = useState('');

  const lockAt   = user.verify_lock_at ? Date.parse(user.verify_lock_at) : NaN;
  const daysLeft = Number.isFinite(lockAt)
    ? Math.max(0, Math.ceil((lockAt - Date.now()) / 86400000))
    : null;

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
   * Submits a new email address (with the current password) to fix a
   * typo and trigger a fresh verification mail.
   * </summary>
   * <remarks>
   * The server requires <c>current_password</c> for any email change
   * (H5 fix from the audit). The endpoint also fires a fresh verify
   * email to the new address as a side effect.
   * </remarks>
   */
  async function saveEmail() {
    setBusy(true); setErr(null);
    try {
      // The server requires current_password for any email change
      // (H5 fix from the audit). The endpoint also fires a fresh
      // verify email to the new address as a side-effect.
      const res = await api.updateProfile({ email: newEmail.trim(), current_password: currentPw } as any);
      onUserUpdated(res.user);
      setSent(true);
      setEditingEmail(false);
      setCurrentPw('');
    } catch (e) {
      setErr(e instanceof Error ? e.message : String(e));
    } finally {
      setBusy(false);
    }
  }

  /**
   * <summary>
   * Calls the logout endpoint (best effort) and notifies the host.
   * </summary>
   * <remarks>
   * Errors from the API call are deliberately swallowed because the
   * host needs to clear local auth state regardless.
   * </remarks>
   */
  async function logout() {
    try { await api.logout(); } catch { /* ignore */ }
    onLogout();
  }

  return (
    <div className="auth">
      <div className="auth-bg" aria-hidden="true" />
      <div className="auth-card">
        <header className="auth-brand">
          <div className="auth-mark" aria-hidden="true"><BrandMark variant="gradient" tile idSuffix="verify-gate" /></div>
          <div className="auth-wordmark">
            <h1>Confirm your email</h1>
            <p className="muted">SwiftLift is locked until you verify.</p>
          </div>
        </header>

        <p style={{ marginTop: '1rem' }}>
          We sent a confirmation link to <b>{user.email}</b>.
          Open it to unlock the app.
        </p>

        {daysLeft !== null && (
          <p className="muted small">
            {daysLeft === 0
              ? 'The account will be disabled later today if you don\'t verify.'
              : daysLeft === 1
                ? 'The account will be disabled in 1 day if you don\'t verify.'
                : `The account will be disabled in ${daysLeft} days if you don't verify.`}
          </p>
        )}

        {!editingEmail && (
          <div className="auth-fields" style={{ marginTop: '1rem' }}>
            {sent
              ? <div className="ok small">Verification email sent. Check your inbox (and spam).</div>
              : <button type="button" className="auth-submit" onClick={resend} disabled={busy}>
                  {busy ? '…' : 'Resend verification email'}
                </button>}
            {err && <div className="error small">{err}</div>}

            <button type="button" className="link" onClick={() => { setEditingEmail(true); setErr(null); setSent(false); }}>
              Wrong address? Change your email
            </button>
            <button type="button" className="link" onClick={logout}>
              Sign out
            </button>
          </div>
        )}

        {editingEmail && (
          <form className="auth-fields" style={{ marginTop: '1rem' }} onSubmit={e => { e.preventDefault(); saveEmail(); }}>
            <label>New email
              <input type="email" autoComplete="email" required value={newEmail} onChange={e => setNewEmail(e.target.value)} />
            </label>
            <label>Current password
              <input type="password" autoComplete="current-password" required value={currentPw} onChange={e => setCurrentPw(e.target.value)} />
            </label>
            {err && <div className="error small">{err}</div>}
            <button type="submit" className="auth-submit" disabled={busy}>
              {busy ? '…' : 'Save & re-send link'}
            </button>
            <button type="button" className="link" onClick={() => { setEditingEmail(false); setErr(null); }}>Cancel</button>
          </form>
        )}
      </div>
    </div>
  );
}
