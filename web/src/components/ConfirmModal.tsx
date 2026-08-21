import { ReactNode } from 'react';
import { Modal } from './Modal';

/**
 * <summary>
 * Props for <see cref="ConfirmModal"/>.
 * </summary>
 */
type Props = {
  /** Modal heading. Rendered inside the global Modal frame. */
  title: ReactNode;
  /** Body text. The actual question or warning shown to the user. */
  message: ReactNode;
  /** Override label for the confirm button. Defaults to "Confirm". */
  confirmLabel?: string;
  /** Override label for the cancel button. Defaults to "Cancel". */
  cancelLabel?: string;
  /** "danger" paints the confirm button red; "primary" uses the accent green. */
  tone?: 'primary' | 'danger';
  /** Fired when the user confirms. The modal closes itself afterwards. */
  onConfirm: () => void;
  /** Closes the modal without confirming. */
  onClose: () => void;
};

/**
 * <summary>
 * Centred confirmation dialog reusing the global Modal frame so it picks
 * up the same theming (panel background, border, fonts, dim backdrop,
 * Esc to close).
 * </summary>
 * <param name="title">Dialog heading.</param>
 * <param name="message">Body text or rich content.</param>
 * <param name="confirmLabel">Override confirm button label.</param>
 * <param name="cancelLabel">Override cancel button label.</param>
 * <param name="tone">Confirm button tone.</param>
 * <param name="onConfirm">Fired on confirm.</param>
 * <param name="onClose">Closes the modal.</param>
 * <remarks>
 * Used for irreversible-ish actions like sign out. Inline
 * <see cref="ConfirmButton"/> is still preferred for tightly scoped
 * actions inside cards (delete journey).
 * </remarks>
 */
export function ConfirmModal({ title, message, confirmLabel = 'Confirm', cancelLabel = 'Cancel', tone = 'primary', onConfirm, onClose }: Props) {
  return (
    <Modal title={title} onClose={onClose}>
      <div className="confirm-modal">
        <div className="confirm-message">{message}</div>
        <div className="modal-actions confirm-actions">
          <button className="small" onClick={onClose}>{cancelLabel}</button>
          <button
            className={`small ${tone === 'danger' ? 'danger' : 'primary'}`}
            onClick={() => { onConfirm(); onClose(); }}
            autoFocus
          >
            {confirmLabel}
          </button>
        </div>
      </div>
    </Modal>
  );
}
