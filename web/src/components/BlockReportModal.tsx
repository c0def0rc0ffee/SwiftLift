import { useState } from 'react';
import { api } from '../api';
import { Modal } from './Modal';

/**
 * <summary>
 * Props for <see cref="BlockReportModal"/>.
 * </summary>
 */
type Props = {
  /** User id of the person being blocked and reported. */
  userId: number;
  /** Their display name. Shown in the dialog title and message body. */
  displayName: string;
  /** Fired after both block and report calls resolve successfully. Useful for refreshing parent state. */
  onDone: () => void;
  /** Closes the modal (no side effects). Called on cancel and after <c>onDone</c>. */
  onClose: () => void;
};

/**
 * <summary>
 * Selectable reasons for a report. <c>v</c> is the wire value sent to
 * the API; <c>l</c> is the human label shown in the dropdown.
 * </summary>
 */
const REASONS = [
  { v: 'harassment',    l: 'Harassment or abuse' },
  { v: 'unsafe',        l: 'Unsafe / dangerous behaviour' },
  { v: 'spam',          l: 'Spam or scams' },
  { v: 'impersonation', l: 'Impersonation' },
  { v: 'scam',          l: 'Tried to defraud me' },
  { v: 'other',         l: 'Other' },
];

/**
 * <summary>
 * Single step "Block and Report" dialog. The user picks a reason, can
 * optionally add detail, and submits.
 * </summary>
 * <param name="userId">User id to block and report.</param>
 * <param name="displayName">Display name shown in the title.</param>
 * <param name="onDone">Callback fired after both calls succeed.</param>
 * <param name="onClose">Callback that closes the modal.</param>
 * <remarks>
 * Reuses the global Modal frame so it picks up the same theming as
 * ConfirmModal and the messages modal.
 *
 * Submitting fires both POSTs in parallel (block plus report). They are
 * idempotent on the server side, so a partial failure (one returns 4xx)
 * does not strand the user; the surviving call still applies. The block
 * gives the user immediate relief from seeing the person, while the
 * report is the slower moderation review side of the action.
 * </remarks>
 */
export function BlockReportModal({ userId, displayName, onDone, onClose }: Props) {
  const [reason, setReason] = useState('harassment');
  const [detail, setDetail] = useState('');
  const [busy, setBusy]     = useState(false);
  const [err, setErr]       = useState<string | null>(null);

  /**
   * <summary>
   * Submits the block and report POSTs in parallel, then closes the modal
   * via <c>onDone</c> followed by <c>onClose</c> on success.
   * </summary>
   * <remarks>
   * The two API calls are independent and idempotent server side, so a
   * single failure does not roll back the other. Errors are stored in
   * local state and rendered inline.
   * </remarks>
   */
  async function submit() {
    setBusy(true); setErr(null);
    try {
      // Block first so the user gets immediate relief from seeing them; the
      // report is the slower, moderation-review side of the action.
      await Promise.all([
        api.blockUser(userId, detail || undefined),
        api.reportUser(userId, reason, detail || undefined),
      ]);
      onDone();
      onClose();
    } catch (e) {
      setErr(e instanceof Error ? e.message : String(e));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal title={`Block & report ${displayName}?`} onClose={onClose}>
      <div className="confirm-modal block-report-modal">
        <p className="confirm-message">
          You won't see each other's journeys, and any active requests or connections between you
          will be cancelled. The report goes to the SwiftLift moderators.
        </p>

        <label>Reason
          <select value={reason} onChange={e => setReason(e.target.value)} disabled={busy}>
            {REASONS.map(r => <option key={r.v} value={r.v}>{r.l}</option>)}
          </select>
        </label>

        <label>Detail (optional)
          <textarea rows={3} maxLength={500} value={detail}
                    onChange={e => setDetail(e.target.value)} disabled={busy} />
        </label>

        {err && <div className="error small">{err}</div>}

        <div className="modal-actions confirm-actions">
          <button className="small" disabled={busy} onClick={onClose}>Cancel</button>
          <button className="small danger" disabled={busy} onClick={submit} autoFocus>
            {busy ? '…' : 'Block & report'}
          </button>
        </div>
      </div>
    </Modal>
  );
}
