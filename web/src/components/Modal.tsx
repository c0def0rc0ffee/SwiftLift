import { ReactNode, useEffect, useRef } from 'react';

/**
 * <summary>
 * Props for <see cref="Modal"/>.
 * </summary>
 */
type Props = {
  /** Modal heading. */
  title: ReactNode;
  /** Optional sub heading shown beneath the title in muted text. */
  subtitle?: ReactNode;
  /** Closes the modal. Wired to Esc, backdrop click, and the header close button. */
  onClose: () => void;
  /** Modal body content. */
  children: ReactNode;
  /** Modal size. "md" is roughly 520px, "lg" is roughly 880px for two pane layouts. */
  size?: 'md' | 'lg';
  /** Optional extra controls placed in the header to the right of the title. */
  headerExtras?: ReactNode;
};

/**
 * <summary>
 * Generic centred modal with a dim backdrop. Provides Esc to close,
 * click outside to close, and locks body scroll while open.
 * </summary>
 * <param name="title">Heading.</param>
 * <param name="subtitle">Optional sub heading.</param>
 * <param name="onClose">Closes the modal.</param>
 * <param name="children">Modal body.</param>
 * <param name="size">"md" or "lg" preset.</param>
 * <param name="headerExtras">Optional extra header controls.</param>
 * <remarks>
 * Body scroll is locked on mount and restored to its previous value on
 * unmount so the page underneath does not drift while the modal is open.
 *
 * Focus is moved into the dialog on mount, trapped inside it while open,
 * and returned to the previously focused element on close.
 * </remarks>
 */
export function Modal({ title, subtitle, onClose, children, size = 'md', headerExtras }: Props) {
  const panelRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    /**
     * <summary>Closes the modal on Escape.</summary>
     * <param name="e">The document-level keydown event.</param>
     */
    function onKey(e: KeyboardEvent) { if (e.key === 'Escape') onClose(); }
    document.addEventListener('keydown', onKey);
    const prevOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = prevOverflow;
    };
  }, [onClose]);

  // Focus management. aria-modal="true" tells assistive tech the rest of
  // the page is inert, but it does not move focus or contain Tab, without
  // this a keyboard user stayed on the trigger behind the backdrop and
  // tabbed through the obscured page, unable to reach the dialog at all.
  useEffect(() => {
    const panel = panelRef.current;
    if (!panel) return;
    const previouslyFocused = document.activeElement as HTMLElement | null;

    const SELECTOR =
      'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';
    const focusables = (): HTMLElement[] =>
      (Array.from(panel.querySelectorAll(SELECTOR)) as HTMLElement[])
        .filter(el => el.offsetParent !== null);

    // Prefer the first control that isn't the close button, so opening a
    // dialog doesn't park the user on "dismiss".
    const initial = focusables();
    (initial.find(el => el.getAttribute('aria-label') !== 'Close') ?? initial[0] ?? panel).focus();

    /**
     * <summary>
     * Keeps Tab and Shift+Tab cycling inside the dialog.
     * </summary>
     * <param name="e">The panel-level keydown event.</param>
     * <remarks>
     * Wraps from the last focusable element to the first and back. The
     * focusable set is recomputed on every keypress so controls that
     * appear or disable while the modal is open are handled correctly.
     * </remarks>
     */
    function onKeyDown(e: KeyboardEvent) {
      if (e.key !== 'Tab') return;
      const items = focusables();
      if (items.length === 0) { e.preventDefault(); return; }
      const first = items[0];
      const last  = items[items.length - 1];
      const active = document.activeElement as HTMLElement | null;
      if (e.shiftKey && (active === first || !panel!.contains(active))) {
        e.preventDefault();
        last.focus();
      } else if (!e.shiftKey && active === last) {
        e.preventDefault();
        first.focus();
      }
    }

    panel.addEventListener('keydown', onKeyDown);
    return () => {
      panel.removeEventListener('keydown', onKeyDown);
      // Hand focus back where it came from, so closing a dialog returns the
      // user to the control that opened it rather than the top of the page.
      previouslyFocused?.focus?.();
    };
  }, []);

  return (
    <div className="modal-backdrop" role="dialog" aria-modal="true" onClick={onClose}>
      <div ref={panelRef} tabIndex={-1} className={`modal modal-${size}`} onClick={e => e.stopPropagation()}>
        <header className="modal-header">
          <div className="modal-title">
            <div>
              <h2>{title}</h2>
              {subtitle && <div className="muted small">{subtitle}</div>}
            </div>
          </div>
          <div className="modal-header-extras">
            {headerExtras}
            <button className="icon" onClick={onClose} aria-label="Close">×</button>
          </div>
        </header>
        <div className="modal-body">{children}</div>
      </div>
    </div>
  );
}
