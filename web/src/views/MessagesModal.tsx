import { useEffect, useState } from 'react';
import { LiftRequest, MessageSummary } from '../api';
import { Modal } from '../components/Modal';
import { Avatar } from '../components/Avatar';
import { MessageThread } from '../components/MessageThread';

/**
 * <summary>
 * Props for <see cref="MessagesModal"/>.
 * </summary>
 */
type Props = {
  /** All known lift requests for the current user. Only those with status
   *  "accepted" are surfaced in the conversation list. */
  requests: LiftRequest[];
  /** Per request summary: last message body, sender, timestamp, and
   *  unread count. Keyed by lift request id. */
  messageSummary: MessageSummary;
  /** The signed in user's id. Used to label "You: " on outgoing snippets
   *  and to distinguish own messages in the thread. */
  myUserId: number;
  /** Closes the modal. */
  onClose: () => void;
  /** Called after the user reads a thread so the parent can refresh the
   *  unread counts shown in the AppBar. */
  onMessagesRead: () => void;
  /** Pre-select a specific connection, e.g. when jumping from a
   *  notification. Null defaults to the most recently active thread. */
  initialLiftRequestId?: number | null;
};

/**
 * <summary>
 * Formats a UTC ISO timestamp into a short relative label such as "5m",
 * "2h", "3d" or, for anything older than a week, a "Mar 12" style date.
 * </summary>
 * <param name="iso">UTC timestamp from the API. May be null or undefined,
 * in which case the empty string is returned.</param>
 * <returns>A compact, locale aware relative time label.</returns>
 * <remarks>
 * The input format is the SQL style "YYYY-MM-DD HH:MM:SS" the API emits.
 * The space is rewritten to "T" and a "Z" suffix added so the resulting
 * string parses as UTC under the standard Date constructor.
 * </remarks>
 */
const fmtRelative = (iso?: string | null) => {
  if (!iso) return '';
  const d = new Date(iso.replace(' ', 'T') + 'Z');
  const diffMs = Date.now() - d.getTime();
  const min = Math.floor(diffMs / 60_000);
  if (min < 1) return 'just now';
  if (min < 60) return `${min}m`;
  const hr = Math.floor(min / 60);
  if (hr < 24) return `${hr}h`;
  const day = Math.floor(hr / 24);
  return day < 7 ? `${day}d` : d.toLocaleDateString([], { month: 'short', day: 'numeric' });
};

/**
 * <summary>
 * Modal where the user can read and reply to all of their accepted lift
 * conversations. Conversation list on the left, full message thread on
 * the right.
 * </summary>
 * <param name="requests">All known lift requests, filtered to accepted
 * ones for display.</param>
 * <param name="messageSummary">Latest message metadata keyed by request
 * id.</param>
 * <param name="myUserId">The signed in user's id.</param>
 * <param name="onClose">Closes the modal.</param>
 * <param name="onMessagesRead">Callback used by the thread component to
 * tell the parent that unread counts may have changed.</param>
 * <param name="initialLiftRequestId">Optional id to pre-select, e.g. when
 * jumping from a notification.</param>
 * <remarks>
 * Conversations are sorted by latest activity. The sort key prefers the
 * last message timestamp from <paramref name="messageSummary"/>, falling
 * back to <c>responded_at</c> then <c>created_at</c> so brand new threads
 * with no messages still appear in a sensible position.
 *
 * The selected conversation is held in local state, but reacts to changes
 * in <paramref name="initialLiftRequestId"/> after mount so notifications
 * arriving while the modal is open still jump to the right thread.
 * </remarks>
 */
export function MessagesModal({ requests, messageSummary, myUserId, onClose, onMessagesRead, initialLiftRequestId = null }: Props) {
  const accepted = requests
    .filter(r => r.status === 'accepted')
    // Sort by latest message first, falling back to creation time
    .sort((a, b) => {
      const aT = messageSummary[a.id]?.last_at ?? a.responded_at ?? a.created_at;
      const bT = messageSummary[b.id]?.last_at ?? b.responded_at ?? b.created_at;
      return (bT ?? '').localeCompare(aT ?? '');
    });

  const [selectedId, setSelectedId] = useState<number | null>(
    initialLiftRequestId ?? accepted[0]?.id ?? null
  );

  // Whenever the props bring in a fresh selection target, honour it.
  useEffect(() => {
    if (initialLiftRequestId != null) setSelectedId(initialLiftRequestId);
  }, [initialLiftRequestId]);

  const selected = accepted.find(r => r.id === selectedId) ?? null;

  return (
    <Modal title="Messages" subtitle={`${accepted.length} connection${accepted.length === 1 ? '' : 's'}`} onClose={onClose} size="lg">
      <div className="messages-modal">
        <aside className="convo-list">
          {accepted.length === 0 ? (
            <div className="empty">
              <div className="empty-headline">No connections yet</div>
              <div className="empty-body">When a lift request is accepted by either side, you'll be able to message each other here.</div>
            </div>
          ) : accepted.map(r => {
            const summary = messageSummary[r.id];
            const unread  = summary?.unread ?? 0;
            const last    = summary?.last_body ?? null;
            const lastSnippet = last
              ? (summary?.last_sender === myUserId ? 'You: ' : '') + last
              : 'No messages yet';
            return (
              <button
                key={r.id}
                className={`convo-row ${selectedId === r.id ? 'on' : ''} ${unread > 0 ? 'has-unread' : ''}`}
                onClick={() => setSelectedId(r.id)}
              >
                <Avatar name={r.other_user.display_name} url={r.other_user.avatar_url} size={40} />
                <div className="convo-body">
                  <div className="convo-top">
                    <strong>{r.other_user.display_name}</strong>
                    <span className="muted small">{fmtRelative(summary?.last_at ?? null)}</span>
                  </div>
                  <div className="convo-snippet">
                    <span className={unread > 0 ? '' : 'muted'}>{lastSnippet}</span>
                    {unread > 0 && <span className="badge attention">{unread}</span>}
                  </div>
                </div>
              </button>
            );
          })}
        </aside>

        <main className="convo-thread">
          {!selected ? (
            <div className="empty">
              <div className="empty-body">Pick a conversation on the left.</div>
            </div>
          ) : (
            <>
              <header className="convo-thread-head">
                <Avatar name={selected.other_user.display_name} url={selected.other_user.avatar_url} size={36} />
                <div>
                  <div><strong>{selected.other_user.display_name}</strong>
                    {selected.other_user.age != null && <span className="age-chip"> · {selected.other_user.age}</span>}
                  </div>
                  <div className="muted small">
                    {selected.direction === 'sent'
                      ? <>their journey · leaves {selected.their_journey.start_time}</>
                      : <>your journey</>}
                  </div>
                </div>
              </header>
              <MessageThread
                liftRequestId={selected.id}
                myUserId={myUserId}
                otherName={selected.other_user.display_name}
                onUnreadChanged={onMessagesRead}
              />
            </>
          )}
        </main>
      </div>
    </Modal>
  );
}
