import { LiftRequest } from '../api';
import { Avatar } from './Avatar';
import type { InstallHint } from '../lib/useInstallHint';

/**
 * <summary>
 * Props for <see cref="NotificationsMenu"/>.
 * </summary>
 */
type Props = {
  /** All lift requests. The menu filters them to pending received only. */
  requests: LiftRequest[];
  /** Jump to a given lift request row. Fired when a notification is clicked. */
  onJump: (liftRequestId: number) => void;
  /** Closes the dropdown. */
  onClose: () => void;
  /** Optional install hint payload from <c>useInstallHint</c>. */
  installHint?: InstallHint;
};

/**
 * <summary>
 * Dropdown panel attached to the AppBar bell. Shows pending received
 * lift requests and an optional one off "install SwiftLift" hint.
 * </summary>
 * <param name="requests">Lift requests; filtered to pending received inside.</param>
 * <param name="onJump">Jump to a specific lift request.</param>
 * <param name="onClose">Close the dropdown.</param>
 * <param name="installHint">Optional install hint state.</param>
 * <remarks>
 * The bell is for lift requests and the one off install hint. Unread
 * chat messages live on their own icon (the speech bubble), so they are
 * not repeated here.
 *
 * Shows a friendly "you're all caught up" empty state when there are no
 * pending requests and no install hint.
 * </remarks>
 */
export function NotificationsMenu({ requests, onJump, onClose, installHint }: Props) {
  const pendingReceived = requests.filter(r => r.direction === 'received' && r.status === 'pending');
  const showInstall     = !!installHint?.show;

  return (
    <div className="notif-menu" role="menu">
      <header className="notif-menu-head">
        <h3>Notifications</h3>
        <button className="link small" onClick={onClose}>Close</button>
      </header>

      {pendingReceived.length === 0 && !showInstall && (
        <div className="muted small notif-empty">You're all caught up.</div>
      )}

      {pendingReceived.length > 0 && (
        <section>
          <h4>New lift requests</h4>
          {pendingReceived.map(r => (
            <button key={r.id} className="notif-row" onClick={() => onJump(r.id)}>
              <Avatar name={r.other_user.display_name} url={r.other_user.avatar_url} size={32} />
              <div className="notif-body">
                <div className="notif-title">
                  <strong>{r.other_user.display_name}</strong>
                  {r.other_user.age != null && <span className="age-chip"> · {r.other_user.age}</span>}
                </div>
                <div className="muted small">wants a lift on your journey</div>
              </div>
            </button>
          ))}
        </section>
      )}

      {showInstall && (
        <section>
          <h4>App</h4>
          <div className="notif-row install-hint">
            <div className="install-hint-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24">
                <path d="M12 3v12m0 0-4-4m4 4 4-4M5 21h14"
                      fill="none" stroke="currentColor" strokeWidth="2"
                      strokeLinecap="round" strokeLinejoin="round" />
              </svg>
            </div>
            <div className="notif-body">
              <div className="notif-title"><strong>Install SwiftLift</strong></div>
              <div className="muted small">
                {installHint?.install
                  ? 'Add SwiftLift to your home screen for quick access.'
                  : <>To install: tap <b>Share</b> then <b>Add to Home Screen</b>.</>}
              </div>
              <div className="install-hint-actions">
                {installHint?.install && (
                  <button className="small primary" onClick={() => installHint.install?.()}>
                    Install
                  </button>
                )}
                <button className="small" onClick={() => installHint?.dismiss()}>
                  Clear
                </button>
              </div>
            </div>
          </div>
        </section>
      )}
    </div>
  );
}
