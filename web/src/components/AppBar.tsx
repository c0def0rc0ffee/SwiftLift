import { useEffect, useRef, useState } from 'react';
import { LiftRequest, MessageSummary, User } from '../api';
import { Avatar } from './Avatar';
import { BrandMark } from './BrandMark';
import { NotificationsMenu } from './NotificationsMenu';
import { useInstallHint } from '../lib/useInstallHint';

/**
 * <summary>
 * Props accepted by <see cref="AppBar"/>: the signed-in user, the active
 * top-level view, plus all the live data and callbacks the bar needs to
 * render its badges and respond to clicks.
 * </summary>
 */
type Props = {
  /** Currently signed-in user. Drives the avatar, display name and the AWAY pill. */
  user: User;
  /** Which top-level view is active. Used to highlight the matching nav item. */
  view: 'map' | 'profile' | 'groups';
  /** Live list of lift requests. Feeds the bell badge (pending received) and the dropdown contents. */
  requests: LiftRequest[];
  /** Per-thread unread counts. Feeds the chat icon badge. */
  messageSummary: MessageSummary;
  /** Whether the off-canvas sidebar is open. Toggles the burger button label. */
  sidebarOpen: boolean;
  /** Toggle the off-canvas sidebar. */
  onToggleSidebar: () => void;
  /** Open the profile view. */
  onOpenProfile: () => void;
  /** Open the map view (also wired to the brand logo). */
  onOpenMap: () => void;
  /** Open the shared journeys / groups view. */
  onOpenGroups: () => void;
  /** Open the messages modal. */
  onOpenMessages: () => void;
  /** Sign the user out. */
  onLogout: () => void;
  /** Jump to a specific lift request thread (used when a bell notification is clicked). */
  onJumpToRequest: (liftRequestId: number) => void;
};

/**
 * <summary>
 * Top application bar shown on every authenticated view. Hosts the brand
 * mark, primary navigation (Groups, Messages), the notifications bell,
 * the profile chip, and sign-out.
 * </summary>
 * <param name="p">Props bag (see <see cref="Props"/>).</param>
 * <remarks>
 * The bell and the chat icon carry distinct badges by design:
 * the chat icon counts unread chat messages, while the bell counts
 * pending received lift requests plus the one-off install hint. We
 * deliberately do not double count messages on the bell, since the
 * chat icon already surfaces them.
 *
 * The notifications dropdown closes on any outside mousedown.
 * </remarks>
 */
export function AppBar(p: Props) {
  const [notifOpen, setNotifOpen] = useState(false);
  const notifRef = useRef<HTMLDivElement>(null);
  const installHint = useInstallHint();

  // close dropdown on outside click
  useEffect(() => {
    if (!notifOpen) return;
    /**
     * <summary>
     * Closes the notifications dropdown when a mousedown lands outside it.
     * </summary>
     * <param name="e">The document-level mousedown event.</param>
     * <remarks>
     * Bound on <c>mousedown</c> rather than <c>click</c> so the menu closes
     * before any click handler underneath it runs.
     * </remarks>
     */
    function onDoc(e: MouseEvent) {
      if (!notifRef.current) return;
      if (!notifRef.current.contains(e.target as Node)) setNotifOpen(false);
    }
    document.addEventListener('mousedown', onDoc);
    return () => document.removeEventListener('mousedown', onDoc);
  }, [notifOpen]);

  // Two distinct surfaces, two distinct badges:
  //   • Messages icon  → unread chat messages.
  //   • Bell icon      → pending lift requests + the install hint (if any).
  // Don't double-count messages on the bell, the chat icon already covers them.
  const pendingReceived = p.requests.filter(r => r.direction === 'received' && r.status === 'pending').length;
  const unreadMessages  = Object.values(p.messageSummary).reduce((a, c) => a + (c.unread ?? 0), 0);
  const bellCount       = pendingReceived + (installHint.show ? 1 : 0);

  return (
    <header className="appbar">
      <button
        className="appbar-burger"
        aria-label={p.sidebarOpen ? 'Close menu' : 'Open menu'}
        onClick={p.onToggleSidebar}
      >
        <svg className="appbar-svg" viewBox="0 0 24 24" aria-hidden="true">
          <path d="M4 7h16M4 12h16M4 17h16"
                fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
        </svg>
      </button>

      <button className="appbar-brand" onClick={p.onOpenMap}>
        <span className="brand-mark" aria-hidden="true">
          <BrandMark variant="solid" />
        </span>
        <span className="brand-text">SwiftLift</span>
      </button>

      {/* Primary navigation items, sit on the LEFT next to the brand
          so the right side is reserved for status (notifications,
          profile). Each one has both an icon (mobile) and a label
          (desktop); the label hides below ~520px via CSS. */}
      <nav className="appbar-nav" aria-label="Main navigation">
        <button
          className={`appbar-nav-item ${p.view === 'groups' ? 'active' : ''}`}
          aria-label="Shared journeys"
          title="Shared journeys"
          onClick={p.onOpenGroups}
        >
          <svg className="appbar-svg" viewBox="0 0 24 24" aria-hidden="true">
            <circle cx="8"  cy="9"  r="3" fill="none" stroke="currentColor" strokeWidth="1.8"/>
            <circle cx="16" cy="9"  r="3" fill="none" stroke="currentColor" strokeWidth="1.8"/>
            <path d="M3 19c0-2.5 2-4 5-4s5 1.5 5 4" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round"/>
            <path d="M11 19c0-2.5 2-4 5-4s5 1.5 5 4" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round"/>
          </svg>
          <span className="appbar-nav-label">Groups</span>
        </button>

        <button
          className={`appbar-nav-item ${unreadMessages > 0 ? 'has-badge' : ''}`}
          aria-label={`Messages${unreadMessages > 0 ? ` (${unreadMessages} unread)` : ''}`}
          title="Messages"
          onClick={p.onOpenMessages}
        >
          <svg className="appbar-svg" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M5 5h14a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2h-7l-4 3v-3H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"
                  fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinejoin="round"/>
            <circle cx="9"  cy="11" r="1.1" fill="currentColor"/>
            <circle cx="12" cy="11" r="1.1" fill="currentColor"/>
            <circle cx="15" cy="11" r="1.1" fill="currentColor"/>
          </svg>
          <span className="appbar-nav-label">Messages</span>
          {unreadMessages > 0 && <span className="appbar-badge">{unreadMessages}</span>}
        </button>
      </nav>

      <div className="appbar-spacer" />

      <div className="appbar-notif" ref={notifRef}>
        <button
          className={`appbar-icon ${bellCount > 0 ? 'has-badge' : ''}`}
          aria-label={`Notifications${bellCount > 0 ? ` (${bellCount})` : ''}`}
          onClick={() => setNotifOpen(v => !v)}
        >
          <svg className="appbar-svg" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M6 16h12l-1.5-2V11a4.5 4.5 0 0 0-9 0v3L6 16z"
                  fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/>
            <path d="M10.5 18.5a1.7 1.7 0 0 0 3 0"
                  fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
            <path d="M12 4v2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
          </svg>
          {bellCount > 0 && <span className="appbar-badge">{bellCount}</span>}
        </button>
        {notifOpen && (
          <NotificationsMenu
            requests={p.requests}
            onJump={id => { setNotifOpen(false); p.onJumpToRequest(id); }}
            onClose={() => setNotifOpen(false)}
            installHint={installHint}
          />
        )}
      </div>

      <button
        className={`appbar-profile ${p.view === 'profile' ? 'active' : ''}`}
        onClick={p.onOpenProfile}
        title="Profile & settings"
      >
        <Avatar name={p.user.display_name} url={p.user.avatar_url} size={32} />
        <span className="appbar-profile-name">
          {p.user.display_name}
          {p.user.is_away && <span className="away-pill compact">AWAY</span>}
        </span>
      </button>

      <button className="appbar-icon appbar-signout" onClick={p.onLogout} title="Sign out" aria-label="Sign out">
        <svg className="appbar-svg" viewBox="0 0 24 24" aria-hidden="true">
          <path d="M14 7V5a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-2"
                fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
          <path d="M10 12h11m-3-3 3 3-3 3"
                fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </button>
    </header>
  );
}
