import { useEffect, useRef, useState } from 'react';

/**
 * <summary>
 * Props for <see cref="ConfirmButton"/>.
 * </summary>
 */
type Props = {
  /** Label shown in the resting state. */
  children: React.ReactNode;
  /** Optional secondary label shown after the user clicks once. Defaults to "Confirm". */
  confirmLabel?: string;
  /** "danger" gives the confirm step a red look; "primary" a blue one. */
  tone?: 'danger' | 'primary';
  /** "small" or "" -- the same modifier classes used elsewhere. */
  size?: '' | 'small';
  /** Extra class names merged onto the button element. */
  className?: string;
  /** Tooltip text for the resting button. */
  title?: string;
  /** When true the button is non interactive. */
  disabled?: boolean;
  /** Action fired once the user clicks the explicit "Confirm" step. */
  onConfirm: () => void | Promise<void>;
};

/**
 * <summary>
 * Two step button: a first click reveals an explicit "Confirm or cancel"
 * pair, a second click confirms.
 * </summary>
 * <param name="children">Label for the resting state.</param>
 * <param name="confirmLabel">Label for the armed confirm button.</param>
 * <param name="tone">Visual treatment for the confirm step.</param>
 * <param name="size">Size modifier.</param>
 * <param name="className">Extra class names.</param>
 * <param name="title">Tooltip for the resting button.</param>
 * <param name="disabled">Disables the button entirely.</param>
 * <param name="onConfirm">Handler fired on the second, confirmation click.</param>
 * <remarks>
 * Auto cancels after 4 seconds or on outside mousedown. Deliberately
 * avoids <c>window.confirm()</c> so the experience is consistent on
 * phones and inside the app shell.
 * </remarks>
 */
export function ConfirmButton({
  children,
  confirmLabel = 'Confirm',
  tone = 'danger',
  size = 'small',
  className = '',
  title,
  disabled,
  onConfirm,
}: Props) {
  const [armed, setArmed] = useState(false);
  const [busy, setBusy]   = useState(false);
  const wrapRef           = useRef<HTMLSpanElement>(null);

  useEffect(() => {
    if (!armed) return;
    const cancelTimer = setTimeout(() => setArmed(false), 4000);
    /**
     * <summary>
     * Disarms the button when a mousedown lands outside it, so an armed
     * "are you sure?" state cannot be left hanging on screen.
     * </summary>
     * <param name="e">The document-level mousedown event.</param>
     * <remarks>
     * Belt and braces with the 4-second auto-disarm timer alongside it.
     * </remarks>
     */
    function onDoc(e: MouseEvent) {
      if (wrapRef.current && !wrapRef.current.contains(e.target as Node)) setArmed(false);
    }
    document.addEventListener('mousedown', onDoc);
    return () => { clearTimeout(cancelTimer); document.removeEventListener('mousedown', onDoc); };
  }, [armed]);

  /**
   * <summary>
   * Awaits the user supplied <c>onConfirm</c> handler with a busy flag
   * raised so the button can disable itself while the action runs.
   * </summary>
   * <remarks>
   * Always clears the busy and armed flags in a <c>finally</c> block so
   * the button settles back to its resting state even if the action throws,
   * and surfaces a failure rather than letting the reset read as success.
   * </remarks>
   */
  async function fire() {
    setBusy(true);
    try {
      await onConfirm();
    } catch (e) {
      // Without this the rejection escapes unhandled and the button just
      // springs back to its resting state, indistinguishable from success.
      alert(`Couldn't do that: ${(e as Error)?.message || 'Unknown error'}`);
    } finally {
      setBusy(false);
      setArmed(false);
    }
  }

  if (!armed) {
    return (
      <button
        type="button"
        className={`${size} link ${tone === 'danger' ? 'danger-link' : ''} ${className}`}
        disabled={disabled}
        title={title}
        onClick={() => setArmed(true)}
      >
        {children}
      </button>
    );
  }

  return (
    <span className="confirm-pair" ref={wrapRef}>
      <button
        type="button"
        className={`${size} ${tone === 'danger' ? 'danger' : 'primary'}`}
        disabled={busy}
        onClick={fire}
      >
        {busy ? '…' : confirmLabel}
      </button>
      <button
        type="button"
        className={`${size}`}
        disabled={busy}
        onClick={() => setArmed(false)}
      >
        Cancel
      </button>
    </span>
  );
}
