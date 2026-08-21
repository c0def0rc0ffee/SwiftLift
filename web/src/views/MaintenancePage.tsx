/**
 * <summary>
 * Full screen "we'll be back shortly" gate the user sees when SwiftLift is
 * temporarily offline for maintenance. Offers a manual reload link and a
 * support email.
 * </summary>
 * <param name="message">Optional server supplied notice. Falls back to a
 * generic reassurance line when empty.</param>
 * <remarks>
 * Rendered by App.tsx when api.Maintenance.isOn() returns true (the server
 * returned a 503 with a maintenance flag on any request). No auto reload,
 * the user picks when to refresh so a long maintenance window does not
 * have their tab cycling endlessly in the background.
 *
 * The component is deliberately self contained, it must render even if
 * every other API call is dead. No data fetches, no Auth dependencies.
 * </remarks>
 */
export function MaintenancePage({ message }: { message: string }) {
  return (
    <div className="auth">
      <div className="auth-bg" aria-hidden="true" />
      <div className="auth-card maintenance-card">
        <img
          className="maintenance-hero"
          src="/maintenance.jpg"
          alt="A gremlin driving off in a car loaded with our servers past a 'We're down for maintenance' sign"
          width={600} height={480}
        />
        <div className="maintenance-body">
          <h1 style={{ marginTop: 0 }}>Back in a moment</h1>
          <p className="muted" style={{ marginTop: '-.4rem' }}>
            A gremlin is making improvements.
          </p>

          <p style={{ marginTop: '1rem' }}>
            {message || "The site is briefly offline while we update something. Your account, journeys and messages are safe. We'll be back shortly."}
          </p>

          <p className="muted small" style={{ marginTop: '1rem' }}>
            Try refreshing in a few minutes.{' '}
            <button type="button" className="link inline" onClick={() => location.reload()}>
              Reload
            </button>
          </p>

          <p className="muted small" style={{ marginTop: '1.5rem' }}>
            Urgent? Reach us at <a href="mailto:hello@swiftlift.gg">hello@swiftlift.gg</a>.
          </p>
        </div>
      </div>
    </div>
  );
}
