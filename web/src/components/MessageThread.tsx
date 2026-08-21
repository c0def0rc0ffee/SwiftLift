import { useEffect, useRef, useState } from 'react';
import { api, Message } from '../api';
import { MessageSkeleton } from './Skeleton';

/**
 * <summary>
 * Props for <see cref="MessageThread"/>.
 * </summary>
 */
type Props = {
  /** Identifies which lift request's thread to load. */
  liftRequestId: number;
  /** Signed in user's id. Used to colour bubbles as "mine" vs "theirs". */
  myUserId: number;
  /** Other party's display name. Used in the empty state hint. */
  otherName: string;
  /**
   * Fired after a poll resolves so the parent can refresh its message
   * summary, even when only the read at timestamps changed.
   */
  onUnreadChanged?: () => void;
};

/**
 * <summary>
 * Chat thread for a single lift request. Renders messages as bubbles,
 * polls for new messages every 10 seconds while the tab is visible, and
 * provides a compose box at the bottom.
 * </summary>
 * <param name="liftRequestId">Which thread to load.</param>
 * <param name="myUserId">Signed in user's id, used to colour bubbles.</param>
 * <param name="otherName">Other party's display name.</param>
 * <param name="onUnreadChanged">Fired after each poll so parents can refresh their summary.</param>
 * <remarks>
 * New messages arriving while the user is reading get pulled in and
 * immediately marked read, keeping the unread badge clear instead of
 * re lighting on the next 30 second summary poll.
 *
 * System messages (e.g. "driver moved the pickup pin") render as a
 * centred notice rather than a chat bubble.
 *
 * Ctrl+Enter or Cmd+Enter sends from the compose box.
 * </remarks>
 */
export function MessageThread({ liftRequestId, myUserId, otherName, onUnreadChanged }: Props) {
  const [messages, setMessages] = useState<Message[] | null>(null);
  const [draft, setDraft]       = useState('');
  const [busy, setBusy]         = useState(false);
  const [err, setErr]           = useState<string | null>(null);
  const [remaining, setRemaining] = useState<number | null>(null);
  const [maxChars, setMaxChars]   = useState(280);
  const scrollRef = useRef<HTMLDivElement>(null);

  /**
   * <summary>
   * Fetches the message list for the current thread, dedupes against the
   * previous result, and notifies the parent that unread state may have
   * changed.
   * </summary>
   * <remarks>
   * Dedupe: when the message ids have not changed we skip the state
   * update so we don't re trigger auto scroll to bottom on every poll
   * tick. The parent is notified on every poll because the server may
   * have updated read at timestamps on this very fetch (see
   * <c>MessageRepo::listForLiftRequest</c>).
   * </remarks>
   */
  async function load() {
    try {
      const r = await api.listMessages(liftRequestId);
      // Dedupe: if the message ids haven't changed, skip the state update so
      // we don't re-trigger auto-scroll-to-bottom on every poll tick.
      setMessages(prev => {
        if (prev && prev.length === r.messages.length) {
          const lastSame = prev[prev.length - 1]?.id === r.messages[r.messages.length - 1]?.id;
          if (lastSame) return prev;
        }
        return r.messages;
      });
      setRemaining(r.remaining_today);
      setMaxChars(r.max_chars);
      // Notify parent on every poll so the badge clears even if the only thing
      // that changed is read_at (server marks inbound messages read on this
      // very fetch, see MessageRepo::listForLiftRequest).
      onUnreadChanged?.();
    } catch (e) {
      setErr(e instanceof Error ? e.message : String(e));
    }
  }

  // Initial fetch + polling while the thread is open. New messages arriving
  // while the user is reading get pulled in and immediately marked read,
  // keeping the unread badge clear instead of re-lighting on the next 30s
  // summary poll.
  useEffect(() => {
    load();
    const id = setInterval(() => {
      if (document.visibilityState === 'visible') load();
    }, 10_000);
    return () => clearInterval(id);
    /* eslint-disable-next-line react-hooks/exhaustive-deps */
  }, [liftRequestId]);

  // Scroll to newest after each render of messages.
  useEffect(() => {
    if (scrollRef.current) scrollRef.current.scrollTop = scrollRef.current.scrollHeight;
  }, [messages]);

  /**
   * <summary>
   * Sends the current compose draft as a new message, appends it
   * optimistically to the list, and resets the compose state.
   * </summary>
   * <remarks>
   * Empty or whitespace only drafts are ignored, as are sends while a
   * previous send is still in flight.
   * </remarks>
   */
  async function send() {
    const body = draft.trim();
    if (!body || busy) return;
    setBusy(true);
    setErr(null);
    try {
      const r = await api.sendMessage(liftRequestId, body);
      setMessages(prev => prev ? [...prev, r.message] : [r.message]);
      setRemaining(r.message.remaining_today);
      setDraft('');
    } catch (e) {
      setErr(e instanceof Error ? e.message : String(e));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="thread">
      <div className="thread-scroll" ref={scrollRef}>
        {messages === null ? (
          <MessageSkeleton />
        ) : messages.length === 0 ? (
          <div className="muted small">No messages yet. Say hi to {otherName}.</div>
        ) : messages.map(m => {
          // System messages (e.g. "driver moved the pickup pin") are
          // rendered as a centred notice, not a chat bubble, they aren't
          // really "from" either party even though sender_id is set.
          if (m.kind === 'system') {
            return (
              <div key={m.id} className="thread-system-notice">
                <div className="thread-system-body">{m.body}</div>
                <div className="thread-system-meta">{formatTime(m.created_at)}</div>
              </div>
            );
          }
          const mine = m.sender_id === myUserId;
          return (
            <div key={m.id} className={`bubble ${mine ? 'mine' : 'theirs'}`}>
              <div className="bubble-body">{m.body}</div>
              <div className="bubble-meta">{formatTime(m.created_at)}</div>
            </div>
          );
        })}
      </div>

      {err && <div className="error small">{err}</div>}

      <div className="thread-compose">
        <textarea
          rows={2}
          maxLength={maxChars}
          value={draft}
          placeholder="Write a quick message…"
          onChange={e => setDraft(e.target.value)}
          onKeyDown={e => {
            if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); send(); }
          }}
        />
        <div className="thread-compose-foot">
          <span className="muted small">
            {draft.length}/{maxChars} · {remaining ?? '?'} left today
          </span>
          <button className="small primary" disabled={busy || !draft.trim() || (remaining ?? 0) <= 0} onClick={send}>
            Send
          </button>
        </div>
      </div>
    </div>
  );
}

/**
 * <summary>
 * Formats a server timestamp as a short, locale aware label for a
 * chat bubble. Uses just the time when the message is from today, or
 * "Mon DD HH:MM" otherwise.
 * </summary>
 * <param name="iso">Server timestamp. Either ISO 8601 or MariaDB
 * <c>YYYY-MM-DD HH:MM:SS</c> form (interpreted as UTC).</param>
 * <returns>Short human readable timestamp.</returns>
 */
function formatTime(iso: string): string {
  const d = new Date(iso.replace(' ', 'T') + 'Z'); // MariaDB returns 'YYYY-MM-DD HH:MM:SS' UTC
  const now = new Date();
  const sameDay =
    d.getFullYear() === now.getFullYear() &&
    d.getMonth() === now.getMonth() &&
    d.getDate() === now.getDate();
  return sameDay
    ? d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
    : d.toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}
