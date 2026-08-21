import { FormEvent, useEffect, useState } from 'react';
import { api, OauthProviders, PASSWORD_MIN, User } from '../api';
import { LegalView } from './LegalView';
import { ForgotReset } from './ForgotReset';
import { BrandMark } from '../components/BrandMark';
import { useVersion } from '../lib/useVersion';

/**
 * <summary>
 * Authentication landing page where the user can sign in, register a new
 * account, kick off a password reset, or continue with Google or Facebook
 * if those providers are configured server side.
 * </summary>
 * <param name="onLogin">Called with the freshly authenticated
 * <see cref="User"/> after a successful sign in. The parent App swaps to
 * the main shell.</param>
 * <remarks>
 * Hosts several internal views without unmounting itself: when the user
 * clicks Terms or Privacy this renders <see cref="LegalView"/>, when they
 * click Forgot password it renders <see cref="ForgotReset"/>, and when a
 * fresh registration succeeds it renders an inline "check your email"
 * confirmation. The reset flow can also be entered via a URL token
 * (<c>?reset=</c>), which is read once on mount.
 *
 * Registration responses are intentionally identical for new and existing
 * emails: either the verification link or a password reset link is sent
 * to the real owner, and the page itself does not reveal which. This
 * prevents the form from being used as an account existence oracle.
 *
 * The 18+ checkbox at registration is the legal cover step. The age
 * inputs on the profile and per-journey filter pages already enforce
 * min=18 and the backend clamps under-18 ages to NULL, but the explicit
 * confirmation means anyone who lies has made a misrepresentation and
 * the Terms apply unambiguously.
 *
 * Reads <c>?oauth_error=</c> once on mount so an OAuth callback failure
 * surfaces inline rather than vanishing into the URL. Also pulls the
 * provider list so the Continue with Google or Facebook buttons only
 * render when the corresponding APP_ID and SECRET are set on the server.
 * </remarks>
 */
export function Login({ onLogin }: { onLogin: (u: User) => void }) {
  const [mode, setMode]               = useState<'login' | 'register'>('login');
  const [legal, setLegal]             = useState<'privacy' | 'terms' | null>(null);
  const [forgot, setForgot]           = useState<{ kind: 'forgot' } | { kind: 'reset'; token: string } | null>(() => {
    const sp = new URLSearchParams(window.location.search);
    const reset = sp.get('reset');
    return reset ? { kind: 'reset', token: reset } : null;
  });
  const [email, setEmail]             = useState('');
  const [password, setPassword]       = useState('');
  const [displayName, setDisplayName] = useState('');
  // 18+ self-confirmation. Required to register. The age inputs on the
  // profile and per-journey filter pages all enforce min=18 and the
  // backend clamps under-18 ages to NULL, but the explicit checkbox at
  // registration is the legal-cover step: anyone who lies has made a
  // misrepresentation and the ToS apply unambiguously.
  const [confirmAdult, setConfirmAdult] = useState(false);
  const [error, setError]             = useState<string | null>(null);
  const [busy, setBusy]               = useState(false);
  const [providers, setProviders]     = useState<OauthProviders | null>(null);
  // Set after a successful register POST. Replaces the form with a
  // "check your email" notice, same message regardless of whether the
  // email was new or already in use, so the page can't be used to probe
  // the user table.
  const [registerSent, setRegisterSent] = useState(false);
  // Live app version for the small badge at the foot of the card.
  const version = useVersion();

  // Read ?oauth_error= once on mount and surface it inline. Also pull the
  // provider list so the Continue with Facebook button only appears when
  // FACEBOOK_APP_ID/SECRET are set on the server.
  useEffect(() => {
    const sp = new URLSearchParams(window.location.search);
    const oerr = sp.get('oauth_error');
    if (oerr) {
      setError(oerr);
      sp.delete('oauth_error');
      const url = new URL(window.location.href);
      url.search = sp.toString();
      window.history.replaceState({}, '', url.toString());
    }
    api.oauthProviders().then(setProviders).catch(() => setProviders(null));
  }, []);

  if (legal)  return <LegalView which={legal} onClose={() => setLegal(null)} />;
  if (forgot) return <ForgotReset mode={forgot} onDone={() => {
    const url = new URL(window.location.href);
    url.searchParams.delete('reset');
    window.history.replaceState({}, '', url.toString());
    setForgot(null);
  }} />;

  // After a register submission the form is replaced by an
  // identical-looking confirmation. The text deliberately doesn't say
  // whether the email was new or already in use, the email itself
  // tells the real owner the right thing.
  if (registerSent) {
    return (
      <div className="auth">
        <div className="auth-bg" aria-hidden="true" />
        <div className="auth-card">
          <header className="auth-brand">
            <div className="auth-mark" aria-hidden="true">
              <BrandMark variant="gradient" tile idSuffix="register-sent" />
            </div>
            <div className="auth-wordmark"><h1>SwiftLift</h1></div>
          </header>
          <h2 style={{ marginTop: '1rem', fontSize: '1rem' }}>Check your email</h2>
          <p className="muted small" style={{ lineHeight: 1.5 }}>
            We've sent a message to <b>{email}</b>. Open the link inside to finish setting
            up your account. If your email is already registered we've sent you a
            password-reset link instead. Either way, your inbox has what you need.
          </p>
          <p className="muted small">
            Didn't get anything in a couple of minutes? Check your spam folder, or{' '}
            <button type="button" className="link inline" onClick={() => { setRegisterSent(false); setError(null); }}>
              go back and try again
            </button>.
          </p>
          <footer className="auth-foot">
            <button type="button" className="link inline" onClick={() => setLegal('terms')}>Terms</button>
            <span className="muted"> · </span>
            <button type="button" className="link inline" onClick={() => setLegal('privacy')}>Privacy</button>
          </footer>
        </div>
      </div>
    );
  }

  /**
   * <summary>
   * Submits the active form, either logging the user in or registering a
   * new account.
   * </summary>
   * <param name="e">The form submission event, default is prevented.</param>
   * <remarks>
   * Registration does not auto sign in. The user has to click the link in
   * the verification email to finish, and the <c>registerSent</c> screen
   * shown afterwards covers both the "new email" and "email already in
   * use" outcomes without revealing which it was.
   * </remarks>
   */
  async function submit(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setBusy(true);
    try {
      if (mode === 'login') {
        const user = await api.login(email, password);
        onLogin(user);
      } else {
        if (!confirmAdult) {
          throw new Error('You need to confirm you are 18 or over to use SwiftLift.');
        }
        await api.register(email, password, displayName, confirmAdult);
        // No auto-login, the user has to click the link in their email
        // to finish. The same `registerSent` view shows whether their
        // email was new (verify link sent) or already taken (a password-
        // reset link was sent to the real owner instead). The text below
        // covers both cases without revealing which.
        setRegisterSent(true);
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
    } finally {
      setBusy(false);
    }
  }

  const isLogin = mode === 'login';

  return (
    <div className="auth">
      <div className="auth-bg" aria-hidden="true" />

      <form className="auth-card" onSubmit={submit}>
        <header className="auth-brand">
          <div className="auth-mark" aria-hidden="true">
            <BrandMark variant="gradient" tile idSuffix="login" />
          </div>
          <div className="auth-wordmark">
            <h1>SwiftLift</h1>
            <p className="muted">Share regular journeys around Guernsey.</p>
          </div>
        </header>

        <div className="auth-tabs" role="tablist">
          <button type="button" role="tab" aria-selected={isLogin}
                  className={`auth-tab ${isLogin ? 'on' : ''}`}
                  onClick={() => { setMode('login'); setError(null); }}>
            Sign in
          </button>
          <button type="button" role="tab" aria-selected={!isLogin}
                  className={`auth-tab ${!isLogin ? 'on' : ''}`}
                  onClick={() => { setMode('register'); setError(null); }}>
            New account
          </button>
        </div>

        <div className="auth-fields">
          {!isLogin && (
            <>
              <label>Display name
                <input value={displayName}
                       autoComplete="name"
                       onChange={e => setDisplayName(e.target.value)}
                       required />
              </label>
            </>
          )}

          <label>Email
            <input type="email" value={email}
                   autoComplete={isLogin ? 'email' : 'email'}
                   onChange={e => setEmail(e.target.value)}
                   required />
          </label>

          <label>Password
            <input type="password" value={password}
                   autoComplete={isLogin ? 'current-password' : 'new-password'}
                   onChange={e => setPassword(e.target.value)}
                   required minLength={PASSWORD_MIN} />
          </label>

          {!isLogin && (
            <label className="auth-adult-check">
              <input type="checkbox"
                     checked={confirmAdult}
                     onChange={e => setConfirmAdult(e.target.checked)}
                     required />
              <span>
                I confirm I am 18 or older and accept the{' '}
                <button type="button" className="link inline" onClick={() => setLegal('terms')}>Terms</button>
                {' '}and{' '}
                <button type="button" className="link inline" onClick={() => setLegal('privacy')}>Privacy</button>
                {' '}notice.
              </span>
            </label>
          )}
        </div>

        {error && <div className="error">{error}</div>}

        <button type="submit" className="auth-submit"
                disabled={busy || (!isLogin && !confirmAdult)}>
          {busy ? '…' : isLogin ? 'Sign in' : 'Create account'}
        </button>

        {(providers?.facebook || providers?.google) && (
          <div className="auth-or"><span>or</span></div>
        )}
        {providers?.google && (
          <a className="auth-google" href="/api/index.php?p=auth/oauth/google/start">
            {/* Google "G" mark, official multicolour logo per branding guidance. */}
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
              <path fill="#4285F4" d="M21.6 12.227c0-.7-.062-1.373-.18-2.018H12v3.82h5.4a4.62 4.62 0 0 1-2.005 3.03v2.518h3.245c1.9-1.749 2.96-4.323 2.96-7.35z"/>
              <path fill="#34A853" d="M12 22c2.7 0 4.964-.895 6.62-2.422l-3.245-2.519c-.9.604-2.05.96-3.375.96-2.605 0-4.81-1.76-5.598-4.123H3.064v2.6A9.998 9.998 0 0 0 12 22z"/>
              <path fill="#FBBC05" d="M6.402 13.896A6.013 6.013 0 0 1 6.08 12c0-.658.114-1.298.322-1.896v-2.6H3.064A10.002 10.002 0 0 0 2 12c0 1.614.386 3.14 1.064 4.496l3.338-2.6z"/>
              <path fill="#EA4335" d="M12 5.98c1.47 0 2.787.505 3.823 1.498l2.866-2.866C16.96 2.99 14.696 2 12 2A9.998 9.998 0 0 0 3.064 7.504l3.338 2.6C7.19 7.74 9.395 5.98 12 5.98z"/>
            </svg>
            Continue with Google
          </a>
        )}
        {providers?.facebook && (
          <a className="auth-fb" href="/api/index.php?p=auth/oauth/facebook/start">
            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
              <path fill="currentColor" d="M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06C2 17.07 5.66 21.22 10.44 22v-7.03H7.9v-2.91h2.54V9.85c0-2.51 1.49-3.89 3.77-3.89 1.09 0 2.24.2 2.24.2v2.46h-1.26c-1.24 0-1.63.77-1.63 1.56v1.87h2.78l-.44 2.91h-2.34V22C18.34 21.22 22 17.07 22 12.06z"/>
            </svg>
            Continue with Facebook
          </a>
        )}

        {isLogin && (
          <button type="button" className="link auth-forgot" onClick={() => setForgot({ kind: 'forgot' })}>
            Forgot password?
          </button>
        )}

        {!isLogin && (
          <div className="muted small auth-fineprint">
            By creating an account you agree to our{' '}
            <button type="button" className="link inline" onClick={() => setLegal('terms')}>terms</button>
            {' '}and{' '}
            <button type="button" className="link inline" onClick={() => setLegal('privacy')}>privacy policy</button>.
          </div>
        )}

        {/* Footer Terms / Privacy links only show on the Sign in tab.
            On New account the same links already appear in the agreement
            line right above the submit button, so a second copy here is
            visual noise. */}
        {isLogin && (
          <footer className="auth-foot">
            <button type="button" className="link inline" onClick={() => setLegal('terms')}>Terms</button>
            <span className="muted"> · </span>
            <button type="button" className="link inline" onClick={() => setLegal('privacy')}>Privacy</button>
          </footer>
        )}

        {version && <div className="auth-version muted">v{version}</div>}
      </form>
    </div>
  );
}
