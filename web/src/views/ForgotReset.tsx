import { FormEvent, useState } from 'react';
import { api, PASSWORD_MIN } from '../api';

/**
 * <summary>
 * Which flow the <see cref="ForgotReset"/> view is running.
 * </summary>
 * <remarks>
 * "forgot" is the email entry step that sends a reset link. "reset" is the
 * second step, reached by following the link in that email, where the user
 * picks a new password using the token from the URL.
 * </remarks>
 */
type Mode = { kind: 'forgot' } | { kind: 'reset'; token: string };

/**
 * <summary>
 * Props for <see cref="ForgotReset"/>.
 * </summary>
 */
type Props = {
  /** Which step of the password recovery flow to show. */
  mode: Mode;
  /** Called after success or when the user clicks "Back to sign in". */
  onDone: () => void;
};

/**
 * <summary>
 * Password recovery page. Shows either the "enter your email" form or the
 * "choose a new password" form depending on <paramref name="mode"/>.
 * </summary>
 * <param name="mode">Selects forgot vs reset behaviour.</param>
 * <param name="onDone">Callback that takes the user back to sign in.</param>
 * <remarks>
 * The component never reveals whether an email is registered. The success
 * message after the forgot step is the same for known and unknown emails,
 * and the API returns the same response either way. The reset step uses a
 * single use token issued by the link in the recovery email, which the
 * server validates and times out after an hour.
 * </remarks>
 */
export function ForgotReset({ mode, onDone }: Props) {
  const [email, setEmail]   = useState('');
  const [pw, setPw]         = useState('');
  const [pw2, setPw2]       = useState('');
  const [busy, setBusy]     = useState(false);
  const [err, setErr]       = useState<string | null>(null);
  const [done, setDone]     = useState(false);

  /**
   * <summary>
   * Submits the active form. In "forgot" mode this asks the API to send a
   * reset link, in "reset" mode it validates the password and posts the
   * new one against the token.
   * </summary>
   * <param name="e">The form submission event. Default is prevented.</param>
   * <remarks>
   * Throws a local error if the two password fields disagree or the new
   * password is shorter than <c>PASSWORD_MIN</c>. Sets a "done" flag on
   * success so the parent component swaps the form for a confirmation.
   * </remarks>
   */
  async function submit(e: FormEvent) {
    e.preventDefault();
    setErr(null); setBusy(true);
    try {
      if (mode.kind === 'forgot') {
        await api.forgotPassword(email);
        setDone(true);
      } else {
        if (pw !== pw2) throw new Error("Passwords don't match");
        if (pw.length < PASSWORD_MIN) throw new Error(`Password too short. Please use at least ${PASSWORD_MIN} characters.`);
        await api.resetPassword(mode.token, pw);
        setDone(true);
      }
    } catch (e) {
      setErr(e instanceof Error ? e.message : String(e));
    } finally {
      setBusy(false);
    }
  }

  if (done) {
    return (
      <div className="auth">
        <div className="auth-card">
          <h1>SwiftLift</h1>
          {mode.kind === 'forgot' ? (
            <>
              <p className="muted">If an account exists for that email, we've just sent a reset link. Check your inbox (and spam folder).</p>
              <p className="muted small">The link expires in one hour.</p>
            </>
          ) : (
            <p className="muted">Password updated. You can sign in now.</p>
          )}
          <button className="primary" onClick={onDone}>Back to sign in</button>
        </div>
      </div>
    );
  }

  return (
    <div className="auth">
      <form className="auth-card" onSubmit={submit}>
        <h1>SwiftLift</h1>
        <p className="muted">
          {mode.kind === 'forgot' ? 'Enter your email to get a reset link.' : 'Choose a new password.'}
        </p>

        {mode.kind === 'forgot' && (
          <label>Email
            <input type="email" required value={email} onChange={e => setEmail(e.target.value)} />
          </label>
        )}

        {mode.kind === 'reset' && (
          <>
            <label>New password
              <input type="password" minLength={10} required value={pw} onChange={e => setPw(e.target.value)} />
            </label>
            <label>Confirm new password
              <input type="password" minLength={10} required value={pw2} onChange={e => setPw2(e.target.value)} />
            </label>
          </>
        )}

        {err && <div className="error">{err}</div>}

        <button type="submit" disabled={busy}>{busy ? '…' : (mode.kind === 'forgot' ? 'Send reset link' : 'Set new password')}</button>
        <button type="button" className="link" onClick={onDone}>Back to sign in</button>
      </form>
    </div>
  );
}
