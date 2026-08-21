import { Component, ReactNode } from 'react';

/**
 * <summary>
 * Props for <see cref="ErrorBoundary"/>.
 * </summary>
 */
type Props = { children: ReactNode };

/**
 * <summary>
 * Internal state for <see cref="ErrorBoundary"/>.
 * </summary>
 */
type State = { error: Error | null };

/**
 * <summary>
 * Top of tree error boundary. A React render error anywhere below this
 * unmounts the broken subtree and shows a friendly recovery screen
 * instead of a blank white page.
 * </summary>
 * <remarks>
 * The error itself is fired at <c>/api/log-error</c> so the operator
 * finds out about it without having to wait for a user to report it.
 * The endpoint is cheap (file append, rate limited) and accepts
 * unauthenticated POSTs because boundary errors often happen before
 * auth state is settled.
 *
 * In development mode the stack trace is also rendered inline beneath
 * the recovery card.
 * </remarks>
 */
export class ErrorBoundary extends Component<Props, State> {
  state: State = { error: null };

  /**
   * <summary>
   * Standard React boundary hook. Maps a thrown error into the next
   * render state so the recovery screen replaces the broken subtree.
   * </summary>
   * <param name="error">The thrown error.</param>
   * <returns>The next state with <c>error</c> populated.</returns>
   */
  static getDerivedStateFromError(error: Error): State {
    return { error };
  }

  /**
   * <summary>
   * Reports the caught error to <c>/api/log-error</c>. Never throws and
   * never blocks the recovery render.
   * </summary>
   * <param name="error">The thrown error.</param>
   * <param name="info">React's componentStack metadata.</param>
   * <remarks>
   * Uses <c>navigator.sendBeacon</c> when available so the report
   * survives the page being unloaded (which can happen when an error
   * during navigation triggers the boundary). Falls back to <c>fetch</c>
   * with <c>keepalive</c>.
   * </remarks>
   */
  componentDidCatch(error: Error, info: { componentStack?: string | null }) {
    // Best effort, the boundary still works if logging fails.
    try {
      const body = JSON.stringify({
        message: error.message,
        stack:   error.stack ?? null,
        componentStack: info.componentStack ?? null,
        url:     window.location.href,
        ua:      navigator.userAgent,
        ts:      new Date().toISOString(),
      });
      // Use sendBeacon if available, survives the page being unloaded
      // (which can happen when an error during navigation triggers the boundary).
      if (typeof navigator.sendBeacon === 'function') {
        navigator.sendBeacon('/api/log-error', new Blob([body], { type: 'application/json' }));
      } else {
        fetch('/api/log-error', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body,
          keepalive: true,
        }).catch(() => { /* swallow */ });
      }
    } catch { /* never throw from a boundary */ }
  }

  /**
   * <summary>
   * Renders either the recovery screen (when an error has been caught)
   * or the wrapped children otherwise.
   * </summary>
   */
  render() {
    if (this.state.error) {
      return (
        <div className="boundary-screen">
          <div className="boundary-card">
            <h1>Something went wrong.</h1>
            <p className="muted">
              SwiftLift hit a snag and had to stop. The error has been reported.
              Try refreshing the page.
            </p>
            <div className="boundary-actions">
              <button
                className="primary"
                onClick={() => location.reload()}
              >
                Refresh
              </button>
              <button onClick={() => this.setState({ error: null })}>
                Try to continue
              </button>
            </div>
            {import.meta.env.DEV && (
              <pre className="boundary-stack">{this.state.error.stack ?? this.state.error.message}</pre>
            )}
          </div>
        </div>
      );
    }
    return this.props.children;
  }
}
