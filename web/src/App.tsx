import { useEffect, useState } from 'react';
import { api, Maintenance, User } from './api';
import { Login } from './views/Login';
import { RideApp } from './views/RideApp';
import { MaintenancePage } from './views/MaintenancePage';
import { ErrorBoundary } from './components/ErrorBoundary';
import { VerifyGate } from './components/VerifyGate';

/**
 * <summary>
 * Top level SPA component. Owns the session and email verification
 * state, watches the global maintenance flag, and decides which view
 * sits at the root of the tree (MaintenancePage, Login, VerifyGate or
 * RideApp).
 * </summary>
 * <returns>
 * A React tree wrapped in an ErrorBoundary. While the session is still
 * loading the component renders a lightweight splash; once resolved it
 * routes to the appropriate top level view based on user state and
 * email verification status.
 * </returns>
 * <remarks>
 * Three concerns are layered here.
 *
 * Maintenance gate: subscribes to the api.Maintenance singleton so any
 * 503 with {"maintenance": true} flips the page over to MaintenancePage
 * for every visitor, authenticated or not.
 *
 * URL-driven flows: on mount the component inspects the query string
 * for a `?join=CODE` group invite and a `?verify=TOKEN` email
 * confirmation. The join code is stashed in sessionStorage before the
 * verify branch runs so a combined verify and join link still picks up
 * the invite after the session settles. The verify token is consumed
 * once and stripped from the address bar via history.replaceState.
 *
 * Verification state branching: once a user is known, the render is
 * chosen from `verify_state`. `verified` and `remind` users see the
 * full RideApp (the soft reminder banner is rendered inside RideApp);
 * `forced` users get the blocking VerifyGate; `disabled` users are
 * bounced back to Login as a safety net even though the front
 * controller normally logs them out before they reach this point.
 *
 * Toast surface: short-lived success and failure copy from the verify
 * and join flows is rendered as a click-to-dismiss toast layered on
 * top of whichever view is active.
 * </remarks>
 */
export function App() {
  const [user, setUser]     = useState<User | null | undefined>(undefined);
  const [verifyMsg, setVerifyMsg] = useState<{ ok: boolean; text: string } | null>(null);
  // Subscribe to api.Maintenance, when any API call gets a 503 with
  // {"maintenance": true}, the flag flips and we re-render to swap in
  // MaintenancePage at the top of the tree.
  const [maintenanceOn, setMaintenanceOn] = useState(Maintenance.isOn());
  useEffect(() => {
    return Maintenance.onChange(() => setMaintenanceOn(Maintenance.isOn()));
  }, []);

  useEffect(() => {
    const sp = new URLSearchParams(window.location.search);
    const verify = sp.get('verify');
    const url = new URL(window.location.href);

    // ?join=CODE, accept a group invite once logged in. Stash the code
    // in sessionStorage so it survives the login round-trip; the second
    // effect below consumes it the moment the user transitions from
    // null → User. Stash BEFORE the verify branch so the join survives
    // a chained "verify email AND join this group" link (verify path
    // used to return early and silently drop the join code).
    const joinCode = sp.get('join');
    if (joinCode) {
      try { sessionStorage.setItem('swiftlift_pending_join', joinCode); } catch { /* ignore */ }
      url.searchParams.delete('join');
      window.history.replaceState({}, '', url.toString());
    }

    // If the URL has ?verify=TOKEN, consume it before checking the session.
    if (verify) {
      api.confirmVerifyEmail(verify)
        .then(u => {
          setUser(u);
          setVerifyMsg({ ok: true, text: 'Email confirmed. Welcome!' });
        })
        .catch(err => {
          setVerifyMsg({ ok: false, text: err instanceof Error ? err.message : 'Verification failed' });
          // Still load the session so user isn't kicked out.
          api.me().then(r => setUser(r.user)).catch(() => setUser(null));
        })
        .finally(() => {
          url.searchParams.delete('verify');
          window.history.replaceState({}, '', url.toString());
        });
      return;
    }
    api.me()
      .then(r => setUser(r.user))
      .catch(() => setUser(null));
  }, []);

  // Consume any pending group-invite code once we have a logged-in user. This
  // covers both the "already logged in when the link was clicked" case and
  // the "logged in just now" case, the previous version only handled the
  // first, dropping invites for anyone who had to sign in / register.
  useEffect(() => {
    if (!user) return;
    let pending: string | null = null;
    try { pending = sessionStorage.getItem('swiftlift_pending_join'); } catch { /* ignore */ }
    if (!pending) return;
    try { sessionStorage.removeItem('swiftlift_pending_join'); } catch { /* ignore */ }
    api.joinGroupByCode(pending)
      .then(() => setVerifyMsg({ ok: true, text: 'Group joined. Find it under Shared journeys.' }))
      .catch(err => setVerifyMsg({ ok: false, text: err instanceof Error ? err.message : 'Join failed' }));
  }, [user]);

  // Maintenance trumps everything else, render before checking user state
  // so unauthenticated visitors and pre-load tabs also see the gate.
  if (maintenanceOn) {
    return (
      <ErrorBoundary>
        <MaintenancePage message={Maintenance.message()} />
      </ErrorBoundary>
    );
  }

  if (user === undefined) return <div className="splash">Loading…</div>;

  // Branch on verification state. Unverified users in the soft-remind
  // window see RideApp with a banner (handled inside RideApp). Once
  // they're past REMIND_DAYS the server returns verify_state='forced'
  // and we replace the app with the VerifyGate, they can resend the
  // link, fix a typo'd email, or sign out, and nothing else.
  // ('disabled' isn't normally observed here because the front
  // controller logs disabled users out before they reach /api/auth/me;
  // included as a fallback to bounce them back to Login cleanly.)
  const renderMain = (() => {
    if (user === null)                    return <Login onLogin={setUser} />;
    if (user.verify_state === 'disabled') return <Login onLogin={setUser} />;
    if (user.verify_state === 'forced')   return <VerifyGate user={user} onUserUpdated={setUser} onLogout={() => setUser(null)} />;
    return <RideApp user={user} onLogout={() => setUser(null)} onUserUpdated={setUser} />;
  })();

  return (
    <ErrorBoundary>
      {renderMain}
      {verifyMsg && (
        <div className={`toast ${verifyMsg.ok ? 'ok' : 'error'}`} onClick={() => setVerifyMsg(null)}>
          {verifyMsg.text}
        </div>
      )}
    </ErrorBoundary>
  );
}
