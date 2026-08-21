/**
 * <summary>
 * Props for <see cref="AppFooter"/>.
 * </summary>
 */
type Props = {
  /** Optional click handler that opens the About page. When omitted the link is not rendered. */
  onShowAbout?: () => void;
};

/**
 * <summary>
 * Slim footer pinned to the bottom of the logged-in shell. Shows a
 * branding line on the left, an optional About link on the right, and
 * the current year.
 * </summary>
 * <param name="onShowAbout">Optional handler that opens the About page.</param>
 * <remarks>
 * Stays out of the way: roughly 28px tall, muted text, no shadow.
 * Terms and Privacy now live inside the About page rather than each
 * getting a footer slot of their own.
 * </remarks>
 */
export function AppFooter({ onShowAbout }: Props) {
  const year = new Date().getFullYear();
  return (
    <footer className="app-footer">
      <span className="app-footer-brand">SwiftLift · Guernsey lift sharing</span>
      <span className="app-footer-spacer" />
      <span className="app-footer-links">
        {onShowAbout && (
          <>
            <button className="link" onClick={onShowAbout}>About</button>
            <span className="dot">·</span>
          </>
        )}
        <span>&copy; {year}</span>
      </span>
    </footer>
  );
}
