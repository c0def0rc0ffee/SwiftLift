/**
 * <summary>
 * Props for <see cref="LegalView"/>. Picks which legal page to render and
 * supplies the back action.
 * </summary>
 */
type Props = {
  /** Which legal document to show. */
  which: 'privacy' | 'terms';
  /** Invoked when the user taps the Back button. */
  onClose: () => void
};

/**
 * <summary>
 * Static legal page where the user can read the Privacy Policy or Terms of
 * Use. Reachable from the auth screen, the About page, and the footer of
 * the logged in shell.
 * </summary>
 * <param name="which">Which document to display.</param>
 * <param name="onClose">Callback to return to the previous view.</param>
 * <remarks>
 * The body is plain copy hard coded into <see cref="PrivacyBody"/> and
 * <see cref="TermsBody"/>. The "Last updated" date is also hard coded
 * because the wording rarely changes and keeping it inline avoids a
 * round trip for a single string.
 * </remarks>
 */
export function LegalView({ which, onClose }: Props) {
  return (
    <div className="page legal-page">
      <div className="page-inner">
        <header className="page-header">
          <button className="link" onClick={onClose}>← Back</button>
          <div className="page-title">
            <h1>{which === 'privacy' ? 'Privacy Policy' : 'Terms of Use'}</h1>
            <div className="muted small">Last updated: April 2026</div>
          </div>
        </header>

        {which === 'privacy' ? <PrivacyBody /> : <TermsBody />}
      </div>
    </div>
  );
}

/**
 * <summary>
 * Renders the Privacy Policy article body. Plain prose, no state.
 * </summary>
 * <remarks>
 * Lede explains the headline reassurance (very little is visible to other
 * users, email and exact home location are never shown), then sections
 * cover what other users can see, what nobody can see, messaging rules,
 * privacy controls, what SwiftLift stores, third party services, and
 * contact details.
 * </remarks>
 */
function PrivacyBody() {
  return (
    <article className="legal">
      <div className="privacy-lede">
        <p>
          Most people simply want to know what another user can actually see
          about them.
        </p>
        <p>The short answer is: very little.</p>
        <p>
          Your email address and exact home location are never shown to other
          users.
        </p>
      </div>

      <h2>What Other Users Can See</h2>

      <h3>People outside SwiftLift</h3>
      <p>
        Your profile and journeys are private and only visible inside
        SwiftLift.
      </p>
      <p>Search engines cannot index your account, journeys, or messages.</p>

      <h3>People with overlapping journeys</h3>
      <p>Users whose journeys overlap with yours may see:</p>
      <ul>
        <li>Your display name</li>
        <li>Your avatar</li>
        <li>Your age and sex, if you choose to provide them</li>
        <li>Your bio</li>
        <li>Vehicle details and preferences</li>
        <li>The days and times you travel</li>
        <li>Approximate distance between your journey and theirs</li>
      </ul>
      <p>
        Other users do not see your exact location or map pins. They only see
        approximate distances such as &ldquo;start 140m away&rdquo;.
      </p>

      <h3>Requests and accepted matches</h3>
      <p>
        If you send or receive a lift request, the other user can also see
        the short message attached to that request.
      </p>
      <p>
        Once a request is accepted, both users can message each other inside
        SwiftLift to arrange details.
      </p>
      <p>Email addresses are never shared through the platform.</p>

      <h2>What Nobody Can See</h2>

      <h3>Your password</h3>
      <p>Passwords are securely hashed and cannot be read by anyone.</p>

      <h3>Your exact address</h3>
      <p>
        SwiftLift stores map pin locations only. Addresses, postcodes, and
        street names are not displayed to other users.
      </p>
      <p>
        <b>Recommended:</b> place your pin on a nearby junction, lay-by or
        landmark rather than directly on your home. Other users only see
        approximate distances from your pin, so a small offset costs nothing
        in match quality and keeps your exact home address private. This is
        the approach we suggest for everyone.
      </p>

      <h3>Your phone number</h3>
      <p>SwiftLift does not store phone numbers.</p>
      <p>
        If you wish to share one with another user, you can do so manually
        in messages after accepting a lift request.
      </p>

      <h3>Your private conversations</h3>
      <p>
        Messages are only visible to the people involved in the
        conversation.
      </p>

      <h2>Messaging Rules</h2>
      <ul>
        <li>
          Messaging is only available after a lift request has been
          accepted.
        </li>
        <li>Users cannot send unsolicited direct messages to strangers.</li>
        <li>
          To help prevent abuse and spam, SwiftLift applies reasonable
          limits to messages and lift requests.
        </li>
        <li>
          Blocking another user immediately closes the conversation and
          removes both users from each other&rsquo;s matches.
        </li>
      </ul>

      <h2>Your Privacy Controls</h2>

      <h3>Away mode</h3>
      <p>
        Away mode hides your profile from matches while keeping your saved
        journeys.
      </p>

      <h3>Blocking users</h3>
      <p>
        You can block users directly from their match card. Blocked users
        cannot contact you and will no longer appear in your matches.
      </p>

      <h3>Match preferences</h3>
      <p>
        You can optionally set age and sex preferences to control who
        appears in your matches and whose matches you appear in.
      </p>

      <h3>Export your data</h3>
      <p>
        You can export your account data as a JSON file from your profile
        page.
      </p>

      <h3>Delete your account</h3>
      <p>You can permanently delete your account from your profile page.</p>
      <p>When deleted:</p>
      <ul>
        <li>Your profile becomes anonymised as &ldquo;deleted user&rdquo;</li>
        <li>Existing message threads remain visible to the other participant</li>
        <li>You no longer appear in matches or searches</li>
      </ul>

      <h2>What SwiftLift Stores</h2>
      <p>SwiftLift stores:</p>
      <ul>
        <li>Your email address</li>
        <li>Your hashed password</li>
        <li>Your display name</li>
        <li>Profile information you choose to provide</li>
        <li>Saved journeys</li>
        <li>Messages and lift requests</li>
      </ul>

      <h2>Third Party Services</h2>
      <p>SwiftLift uses:</p>
      <ul>
        <li>OpenStreetMap and Carto for map tiles</li>
        <li>OSRM for routing</li>
        <li>Nominatim for address search</li>
      </ul>
      <p>SwiftLift does not use advertising, analytics, or tracking systems.</p>
      <p>
        The only cookie used is the login session required to keep you
        signed in.
      </p>

      <h2>Contact</h2>
      <p>For privacy requests, corrections, or questions about your data:</p>
      <p>
        <a href="mailto:hello@swiftlift.gg">hello@swiftlift.gg</a>
      </p>
    </article>
  );
}

/**
 * <summary>
 * Renders the Terms of Use article body. Plain prose, no state.
 * </summary>
 * <remarks>
 * Covers what SwiftLift is and isn't, the 18+ requirement, the no payment
 * rule, user and driver responsibilities, the absence of verification,
 * account suspension, liability disclaimers, and contact details.
 * </remarks>
 */
function TermsBody() {
  return (
    <article className="legal">
      <h2>What SwiftLift Is</h2>
      <p>
        SwiftLift is a community noticeboard for sharing regular journeys
        around Guernsey.
      </p>
      <p>
        SwiftLift puts users in touch with each other, but the actual lift
        arrangement is entirely between the users involved.
      </p>
      <p>
        SwiftLift is not a taxi service, ride hailing platform, transport
        provider, or insurer.
      </p>

      <h2>Adults Only</h2>
      <p>You must be 18 years or older to use SwiftLift.</p>
      <p>
        By creating an account, you confirm that you are at least 18 years
        old. Accounts found to belong to under 18s may be removed without
        warning.
      </p>
      <p>
        Children may travel as passengers in a lift arranged through
        SwiftLift, but lift arrangements must always be made between adults.
      </p>

      <h2>No Payment or Compensation</h2>
      <p>Lifts arranged through SwiftLift must be completely free.</p>
      <p>
        No money, goods, services, gifts, or any other form of compensation
        may be requested, offered, or exchanged through the platform.
      </p>
      <p>
        Users offering or requesting payment may have their account
        suspended or permanently removed.
      </p>
      <p>
        SwiftLift exists as a community lift sharing noticeboard only and
        not as a commercial transport service.
      </p>

      <h2>Your Responsibilities</h2>
      <p>Users are responsible for:</p>
      <ul>
        <li>Providing accurate information on their profile and journeys</li>
        <li>Treating other users respectfully</li>
        <li>
          Not using SwiftLift for harassment, spam, scams, or illegal
          activity
        </li>
        <li>Making their own judgement about who they travel with</li>
      </ul>
      <p>Drivers are additionally responsible for ensuring they have:</p>
      <ul>
        <li>A valid driving licence</li>
        <li>Appropriate insurance</li>
        <li>A safe and roadworthy vehicle</li>
        <li>Full legal ability to drive</li>
      </ul>
      <p>
        Driving while impaired by alcohol, drugs, or anything else is
        strictly prohibited.
      </p>

      <h2>Safety and Verification</h2>
      <p>
        SwiftLift does not carry out identity checks, driving licence
        checks, insurance checks, or criminal background checks on users.
      </p>
      <p>
        Users arrange and participate in lifts entirely at their own
        discretion and risk.
      </p>

      <h2>Account Suspension</h2>
      <p>
        SwiftLift may suspend or permanently remove accounts for misuse,
        abuse, suspicious behaviour, breaches of these terms, or behaviour
        considered harmful to other users or the service.
      </p>

      <h2>Liability</h2>
      <p>
        SwiftLift is provided as is without guarantees of availability,
        reliability, or suitability.
      </p>
      <p>
        To the maximum extent permitted by law, SwiftLift is not
        responsible for any loss, damage, injury, dispute, or incident
        arising from journeys or interactions arranged through the service.
      </p>

      <h2>Changes to the Service</h2>
      <p>
        SwiftLift may change, suspend, or discontinue parts of the service
        at any time without notice.
      </p>

      <h2>Contact</h2>
      <p>For questions about these terms:</p>
      <p>
        <a href="mailto:hello@swiftlift.gg">hello@swiftlift.gg</a>
      </p>
    </article>
  );
}
