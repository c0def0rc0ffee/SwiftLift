import { useEffect, useState } from 'react';
import { api, Issue } from '../api';

/**
 * <summary>
 * Props for <see cref="AboutView"/>.
 * </summary>
 */
type Props = {
  /** Closes the About page and returns to whatever was behind it. */
  onClose: () => void;
  /** Opens the Terms or Privacy page from the inline footer links. */
  onShowLegal: (page: 'terms' | 'privacy') => void;
};

/**
 * <summary>
 * About page where signed in users can read the SwiftLift pitch (what it
 * is, how it works, who it is for), see the build version, and skim the
 * public changelog / roadmap of recent improvements and known issues.
 * </summary>
 * <param name="onClose">Closes the About page.</param>
 * <param name="onShowLegal">Opens either the Terms or Privacy page.</param>
 * <remarks>
 * Reachable from the footer of the logged in shell. Mirrors the
 * landing.html marketing copy so signed in users have somewhere to point
 * friends at without leaving the app. Terms and Privacy links live at the
 * bottom of this page rather than the global footer to keep chrome
 * quieter.
 *
 * Also surfaces the public changelog and roadmap (the Improvements
 * section, server side gated to <c>is_public=1</c> rows) so users can see
 * what has shipped lately, what is coming next, and any open bugs already
 * known about.
 * </remarks>
 */
export function AboutView({ onClose, onShowLegal }: Props) {
  const [version, setVersion] = useState<string | null>(null);
  const [issues, setIssues]   = useState<Issue[] | null>(null);

  useEffect(() => {
    api.listIssues()
      .then(r => { setVersion(r.version); setIssues(r.issues); })
      .catch(() => { setVersion(null); setIssues([]); });
  }, []);

  return (
    <div className="page about-page">
      <div className="page-inner">
        <header className="page-header">
          <button className="link" onClick={onClose}>← Back</button>
          <div className="page-title">
            <h1>About SwiftLift</h1>
            <div className="muted small">
              <div>A small Guernsey lift sharing noticeboard.</div>
              {version && (
                <div style={{ marginTop: '.15rem' }}>Version {version}</div>
              )}
            </div>
          </div>
        </header>

        <article className="legal">
          <h2>What SwiftLift Is</h2>
          <p>
            SwiftLift is a community lift sharing noticeboard for the Bailiwick
            of Guernsey.
          </p>
          <p>
            If you regularly make the same journey each week, whether that is
            the school run, the commute into St Peter Port, or a trip across
            the island, you can add your route to the map and connect with
            other islanders travelling the same way at similar times.
          </p>
          <p>
            SwiftLift is simple by design. No gimmicks. Just neighbours helping
            neighbours by sharing journeys.
          </p>
          <p>
            The service is completely free to use. No money changes hands, and
            SwiftLift is not a taxi or ride hailing service. It simply helps
            people connect. Any lift arrangement is entirely between the users
            involved.
          </p>

          <h2>How It Works</h2>

          <h3>Add Your Journey</h3>
          <p>
            Choose your start and end points on the Guernsey map, then select
            the days and times you usually travel.
          </p>

          <h3>Find Matches</h3>
          <p>
            SwiftLift shows people whose journeys overlap with yours within a
            distance and time range you choose. Optional filters let you narrow
            matches by age range and sex if preferred.
          </p>

          <h3>Chat Safely</h3>
          <p>
            If someone looks like a good match, send them a request with a
            short message. Once accepted, you can chat within SwiftLift to
            arrange the details. Email addresses are never shared.
          </p>

          <h2>Who It&rsquo;s For</h2>
          <p>
            SwiftLift is for anyone in Guernsey who would rather drive less.
          </p>
          <p>
            That includes commuters travelling into or out of St Peter Port,
            parents doing regular school runs, and anyone making repeat
            journeys around the island each week.
          </p>
          <p>
            If you regularly travel the same route more than once a week,
            SwiftLift is designed for you.
          </p>

          <h2>Important Information</h2>
          <p>You must be 18 or older to use SwiftLift.</p>
          <p>
            Drivers are responsible for ensuring they hold a valid driving
            licence, appropriate insurance, and that their vehicle is
            roadworthy.
          </p>
          <p>
            SwiftLift is not a taxi service, ride hailing platform, or insurer.
          </p>

          <h2>Contact</h2>
          <p>
            For bug reports, suggestions, account recovery, or general
            enquiries:
          </p>
          <p>
            <a href="mailto:hello@swiftlift.gg">hello@swiftlift.gg</a>
          </p>

          <details className="about-changelog" style={{ marginTop: '2rem' }}>
            <summary style={{ cursor: 'pointer' }}>
              <b>Improvements &amp; known issues</b>
              <span className="muted small" style={{ marginLeft: '.5rem' }}>
                (click to expand)
              </span>
            </summary>
            <ChangelogList issues={issues} />
          </details>

          <hr style={{ borderColor: 'var(--border)', margin: '2rem 0 1rem' }} />
          <p className="muted small">
            <button type="button" className="link inline" onClick={() => onShowLegal('terms')}>
              Terms of Use
            </button>
            {' · '}
            <button type="button" className="link inline" onClick={() => onShowLegal('privacy')}>
              Privacy Policy
            </button>
          </p>
        </article>
      </div>
    </div>
  );
}

/**
 * <summary>
 * Renders the public issue list grouped by status (In progress, Planned,
 * Fixed). Each entry shows kind, title, a small description, and a version
 * tag where available.
 * </summary>
 * <param name="issues">The full list of public issues, or null while
 * loading. Empty array means nothing public yet.</param>
 * <remarks>
 * Group ordering matches the server side SQL: in_progress, planned, fixed,
 * rejected. Rejected items are intentionally omitted from the rendered
 * sections to avoid airing scrapped work. Loading and empty states render
 * muted placeholder copy.
 * </remarks>
 */
function ChangelogList({ issues }: { issues: Issue[] | null }) {
  if (issues === null) return <p className="muted small">Loading…</p>;
  if (issues.length === 0) {
    return (
      <p className="muted small" style={{ marginTop: '.5rem' }}>
        No public items yet. We'll start adding them as features ship.
      </p>
    );
  }

  // Group by status. Order matches the SQL: in_progress, planned, fixed, rejected.
  const groups: Record<string, Issue[]> = {};
  for (const it of issues) {
    (groups[it.status] = groups[it.status] || []).push(it);
  }

  const sections: { key: Issue['status']; label: string }[] = [
    { key: 'in_progress', label: 'In progress' },
    { key: 'planned',     label: 'Planned'     },
    { key: 'fixed',       label: 'Fixed'       },
  ];

  return (
    <div className="changelog">
      {sections.map(s => groups[s.key]?.length ? (
        <section key={s.key} style={{ marginTop: '1rem' }}>
          <h3 style={{ fontSize: '.95rem', margin: '0 0 .5rem' }}>{s.label}</h3>
          <ul className="changelog-list">
            {groups[s.key].map(it => <ChangelogItem key={it.id} item={it} />)}
          </ul>
        </section>
      ) : null)}
    </div>
  );
}

/**
 * <summary>
 * Single line item inside the changelog list. Renders a kind pill, the
 * title, an optional version tag, and an optional description.
 * </summary>
 * <param name="item">The issue to render.</param>
 * <remarks>
 * The version tag prefers <c>fixed_in_version</c> over
 * <c>target_version</c>, since something already fixed is more useful than
 * the version it was originally aimed at. When both are absent the tag is
 * omitted entirely.
 * </remarks>
 */
function ChangelogItem({ item }: { item: Issue }) {
  const tag = item.fixed_in_version
    ? `fixed in ${item.fixed_in_version}`
    : item.target_version
      ? `planned for ${item.target_version}`
      : null;
  return (
    <li style={{ padding: '.5rem 0', borderBottom: '1px dashed var(--border)' }}>
      <div style={{ display: 'flex', gap: '.5rem', alignItems: 'baseline', flexWrap: 'wrap' }}>
        <span className={`pill kind-${item.kind}`} style={{ fontSize: '.7rem' }}>{item.kind}</span>
        <b>{item.title}</b>
        {tag && <span className="muted small">· {tag}</span>}
      </div>
      {item.description && (
        <p className="muted small" style={{ margin: '.25rem 0 0' }}>{item.description}</p>
      )}
    </li>
  );
}
