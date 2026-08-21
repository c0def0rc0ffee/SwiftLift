import { useCallback, useEffect, useState } from 'react';
import { api, GroupSummary, GroupDetail, GroupDriverDay } from '../api';
import { Avatar } from '../components/Avatar';
import { PinPicker } from '../components/PinPicker';
import { ConfirmModal } from '../components/ConfirmModal';

/**
 * <summary>
 * Short day names in ISO week order (Monday first). Drives the day toggle
 * buttons and the rotation grid.
 * </summary>
 */
const DAY_LABELS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

/**
 * <summary>
 * Which sub view of <see cref="GroupsView"/> is currently active.
 * </summary>
 * <remarks>
 * "list" shows the user's groups plus a join by code input, "create" is
 * the new group form, and "detail" is the management screen for the
 * given group id.
 * </remarks>
 */
type Mode =
  | { kind: 'list' }
  | { kind: 'create' }
  | { kind: 'detail'; id: number };

/**
 * <summary>
 * Shared journeys page where the user can browse their existing groups,
 * create a new one, join one by invite code, or drill into a single group
 * to manage its rotation, members, and their own pickup pin.
 * </summary>
 * <param name="onClose">Returns to the map view.</param>
 * <param name="myUserId">The signed in user's id, used by the detail view
 * to highlight the current user inside the members list.</param>
 * <remarks>
 * Built around a single tagged Mode state so we can swap between list,
 * create, and detail without remounting the surrounding chrome. After a
 * create the user is sent straight to the detail page for the new group.
 * </remarks>
 */
export function GroupsView({ onClose, myUserId }: { onClose: () => void; myUserId: number }) {
  const [mode, setMode]     = useState<Mode>({ kind: 'list' });
  const [groups, setGroups] = useState<GroupSummary[] | null>(null);
  const [error, setError]   = useState<string | null>(null);

  /**
   * <summary>
   * Re-fetches the user's group list and replaces the local state.
   * Errors surface in the page level error banner.
   * </summary>
   */
  const refresh = useCallback(async () => {
    try {
      const r = await api.listGroups();
      setGroups(r.groups);
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e));
    }
  }, []);

  useEffect(() => { refresh(); }, [refresh]);

  return (
    <div className="page groups-page">
      <div className="page-inner">
        <header className="page-header">
          <button className="link" onClick={onClose}>← Back to map</button>
          <div className="page-title">
            <h1>Shared journeys</h1>
            <div className="muted small">School runs, workplace carpools: group trips with rotating drivers.</div>
          </div>
        </header>

        {error && <div className="error" onClick={() => setError(null)}>{error}</div>}

        {mode.kind === 'list' && (
          <GroupsList
            groups={groups}
            onCreate={() => setMode({ kind: 'create' })}
            onOpen={id => setMode({ kind: 'detail', id })}
            onJoined={refresh}
          />
        )}

        {mode.kind === 'create' && (
          <CreateGroupForm
            onCancel={() => setMode({ kind: 'list' })}
            onCreated={async id => { await refresh(); setMode({ kind: 'detail', id }); }}
          />
        )}

        {mode.kind === 'detail' && (
          <GroupDetailView
            id={mode.id}
            myUserId={myUserId}
            onBack={async () => { await refresh(); setMode({ kind: 'list' }); }}
          />
        )}
      </div>
    </div>
  );
}

/**
 * <summary>
 * The default sub view: shows the user's existing groups, a button to
 * create a new one, and an input for joining an existing group by code.
 * </summary>
 * <param name="groups">List of group summaries or null while loading.
 * Empty array means the user is not in any groups yet.</param>
 * <param name="onCreate">Switches to the create form.</param>
 * <param name="onOpen">Opens the detail view for the given group id.</param>
 * <param name="onJoined">Refresh callback fired after a successful join
 * so the new group shows up in the list.</param>
 */
function GroupsList({ groups, onCreate, onOpen, onJoined }: {
  groups: GroupSummary[] | null;
  onCreate: () => void;
  onOpen: (id: number) => void;
  onJoined: () => void;
}) {
  const [code, setCode]       = useState('');
  const [joinErr, setJoinErr] = useState<string | null>(null);
  const [joining, setJoining] = useState(false);

  /**
   * <summary>
   * Submits the typed invite code to the API and refreshes the list on
   * success. Trims whitespace and ignores empty input.
   * </summary>
   * <remarks>
   * The local error is rendered inline beneath the join input rather than
   * surfaced as a page level error since it is bound to that one action.
   * </remarks>
   */
  async function join() {
    if (!code.trim()) return;
    setJoining(true);
    setJoinErr(null);
    try {
      await api.joinGroupByCode(code.trim());
      setCode('');
      onJoined();
    } catch (e) {
      setJoinErr(e instanceof Error ? e.message : String(e));
    } finally {
      setJoining(false);
    }
  }

  return (
    <div className="groups-list">
      <section className="groups-section">
        <button className="primary" onClick={onCreate}>+ New shared journey</button>
        <div className="groups-join-row">
          <input
            placeholder="Have an invite code? Paste it"
            value={code}
            onChange={e => setCode(e.target.value)}
            maxLength={32}
          />
          <button className="link" onClick={join} disabled={!code.trim() || joining}>
            {joining ? 'Joining…' : 'Join'}
          </button>
        </div>
        {joinErr && <div className="error small">{joinErr}</div>}
      </section>

      {groups === null && <div className="muted">Loading…</div>}
      {groups && groups.length === 0 && (
        <div className="empty">
          <div className="empty-headline">No shared journeys yet</div>
          <div className="empty-body">
            Create one for your school run or workplace carpool, then share the
            invite code with others. Whoever drives that day, everyone meets at
            their place.
          </div>
        </div>
      )}

      {groups && groups.length > 0 && (
        <div className="group-cards">
          {groups.map(g => (
            <GroupCard key={g.id} group={g} onOpen={() => onOpen(g.id)} />
          ))}
        </div>
      )}
    </div>
  );
}

/**
 * <summary>
 * Card representation of a single group inside <see cref="GroupsList"/>.
 * Shows the group name, summary line (days and time), today's and
 * tomorrow's driver, and a footer hint about the user's role.
 * </summary>
 * <param name="group">The summary record for this card.</param>
 * <param name="onOpen">Opens the detail page for this group.</param>
 */
function GroupCard({ group, onOpen }: { group: GroupSummary; onOpen: () => void }) {
  return (
    <button className="group-card" onClick={onOpen}>
      <div className="group-card-top">
        <strong>{group.name}</strong>
        <span className="muted small">{daysSummary(group.days_mask)} · {group.start_time}</span>
      </div>
      <DriverLine label="Today"    day={group.today} />
      <DriverLine label="Tomorrow" day={group.tomorrow} />
      <div className="group-card-foot muted small">
        {group.is_creator ? 'You created this group' : 'Member'}
      </div>
    </button>
  );
}

/**
 * <summary>
 * Compact "Today" or "Tomorrow" line on the group card. Describes who is
 * driving, where everyone meets, and the start time.
 * </summary>
 * <param name="label">Heading text such as "Today" or "Tomorrow".</param>
 * <param name="day">The rotation entry, or null when the group does not
 * run on this day.</param>
 * <remarks>
 * Three rendering paths: no day at all ("not running"), a day with no
 * driver assigned, and a fully populated day. The meeting place prefers
 * a saved label, falls back to a four decimal lat/lng, and finally to
 * a friendly "{name}'s place".
 * </remarks>
 */
function DriverLine({ label, day }: { label: string; day: GroupDriverDay | null }) {
  if (!day) {
    return <div className="group-day-line muted small">{label}: not running</div>;
  }
  if (!day.driver) {
    return <div className="group-day-line muted small">{label}: no driver assigned</div>;
  }
  const where = day.driver.home_label
    ? day.driver.home_label
    : day.driver.home_lat != null
      ? `${day.driver.home_lat.toFixed(4)}, ${day.driver.home_lng?.toFixed(4)}`
      : `${day.driver.display_name}'s place`;
  return (
    <div className="group-day-line">
      <b>{label}:</b> {day.driver.display_name} driving · meet at {where} by {day.start_time}
    </div>
  );
}

/**
 * <summary>
 * Form for creating a new shared journey. Collects the name, start time,
 * day mask, optional destination pin and label, and the creator's own
 * pickup pin and label.
 * </summary>
 * <param name="onCancel">Cancels and returns to the list view.</param>
 * <param name="onCreated">Called with the new group id after a successful
 * create. The parent typically jumps straight to the detail view.</param>
 * <remarks>
 * Defaults to weekdays (Mon to Fri) and an 08:15 start, which fits the
 * most common school run / commute use cases. The submit button is gated
 * by the <c>ready</c> guard, which requires a non empty name, at least
 * one day, and a syntactically valid HH:MM time.
 * </remarks>
 */
function CreateGroupForm({ onCancel, onCreated }: { onCancel: () => void; onCreated: (id: number) => void }) {
  const [name, setName]           = useState('');
  const [time, setTime]           = useState('08:15');
  const [daysMask, setDaysMask]   = useState(0b0011111); // Mon-Fri
  const [destLabel, setDestLabel] = useState('');
  const [destPin, setDestPin]     = useState<{ lat: number; lng: number } | null>(null);
  const [homeLabel, setHomeLabel] = useState('');
  const [homePin, setHomePin]     = useState<{ lat: number; lng: number } | null>(null);
  const [busy, setBusy]           = useState(false);
  const [err, setErr]             = useState<string | null>(null);

  /**
   * <summary>
   * Posts the group payload to the API and hands the new id back to the
   * parent via <see cref="onCreated"/>.
   * </summary>
   * <remarks>
   * Trims string fields, coerces empty pin labels to null, and only
   * forwards pin coordinates when the user actually placed a pin. The
   * early return on missing required fields is a defensive guard, the
   * submit button's disabled state should already prevent this case.
   * </remarks>
   */
  async function submit() {
    if (!name.trim() || !daysMask || !time) return;
    setBusy(true);
    setErr(null);
    try {
      const r = await api.createGroup({
        name: name.trim(),
        start_time: time,
        days_mask: daysMask,
        dest_lat:   destPin?.lat ?? null,
        dest_lng:   destPin?.lng ?? null,
        dest_label: destLabel.trim() || null,
        home_lat:   homePin?.lat ?? null,
        home_lng:   homePin?.lng ?? null,
        home_label: homeLabel.trim() || null,
      });
      onCreated(r.id);
    } catch (e) {
      setErr(e instanceof Error ? e.message : String(e));
    } finally {
      setBusy(false);
    }
  }

  const ready = name.trim() !== '' && daysMask !== 0 && /^\d{2}:\d{2}$/.test(time);

  return (
    <div className="profile-form">
      <section>
        <h3>New shared journey</h3>
        <label>Name
          <input
            placeholder="e.g. School run to Forest Primary"
            value={name}
            onChange={e => setName(e.target.value)}
            maxLength={120}
          />
        </label>

        <label>Start time
          <input type="time" value={time} onChange={e => setTime(e.target.value)} />
        </label>

        <fieldset className="days">
          <legend>Days</legend>
          {DAY_LABELS.map((d, i) => {
            const bit = 1 << i;
            const on  = (daysMask & bit) !== 0;
            return (
              <button type="button" key={d}
                      className={on ? 'day on' : 'day'}
                      onClick={() => setDaysMask(m => m ^ bit)}>
                {d}
              </button>
            );
          })}
        </fieldset>
      </section>

      <section>
        <h3>Destination (optional)</h3>
        <p className="muted small">Where the journey ends, e.g. the school. You can skip and add later.</p>
        <label>Label
          <input
            placeholder="e.g. Forest Primary School"
            value={destLabel}
            onChange={e => setDestLabel(e.target.value)}
            maxLength={160}
          />
        </label>
        <PinPicker value={destPin} onChange={setDestPin} />
      </section>

      <section>
        <h3>My pickup point</h3>
        <p className="muted small">Where everyone meets when it's your day to drive.</p>
        <label>Label
          <input
            placeholder="e.g. 15 Glategny Esplanade"
            value={homeLabel}
            onChange={e => setHomeLabel(e.target.value)}
            maxLength={160}
          />
        </label>
        <PinPicker value={homePin} onChange={setHomePin} />
      </section>

      {err && <div className="error">{err}</div>}

      <div className="groups-form-actions">
        <button type="button" className="link" onClick={onCancel} disabled={busy}>Cancel</button>
        <button type="button" className="primary" onClick={submit} disabled={!ready || busy}>
          {busy ? 'Creating…' : 'Create group'}
        </button>
      </div>
    </div>
  );
}

/**
 * <summary>
 * Detail view for a single shared journey. Shows the group's invite code,
 * lets the creator pick who drives each day, lists members with their
 * pickup pins, and exposes the current user's own pickup pin for editing.
 * Also offers Leave (members) and Delete (creators) actions.
 * </summary>
 * <param name="id">Group id to load.</param>
 * <param name="myUserId">The signed in user's id, used to find their own
 * membership row.</param>
 * <param name="onBack">Returns to the list view, refreshing the summary
 * data on the way.</param>
 * <remarks>
 * The rotation grid uses ISO day of week numbers (Mon=1 to Sun=7) and
 * skips days that are not active in the group's <c>days_mask</c>. Only
 * the creator can change rotation entries, everyone else sees the
 * driver name as plain text.
 * </remarks>
 */
function GroupDetailView({ id, myUserId, onBack }: { id: number; myUserId: number; onBack: () => void }) {
  const [g, setG]               = useState<GroupDetail | null>(null);
  const [err, setErr]           = useState<string | null>(null);
  const [savingHome, setSavingHome] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState(false);
  const [confirmLeave, setConfirmLeave]   = useState(false);

  /**
   * <summary>
   * Re-fetches this group's full detail (members, rotation, invite code)
   * and replaces the local state. Errors surface in the section's own
   * error banner.
   * </summary>
   */
  const refresh = useCallback(async () => {
    try {
      setG(await api.getGroup(id));
    } catch (e) {
      setErr(e instanceof Error ? e.message : String(e));
    }
  }, [id]);

  useEffect(() => { refresh(); }, [refresh]);

  if (err) {
    return (
      <div className="profile-form">
        <div className="error">{err}</div>
        <button className="link" onClick={onBack}>← Back</button>
      </div>
    );
  }
  if (!g) return <div className="muted">Loading…</div>;

  const me = g.members.find(m => m.id === myUserId);
  const inviteUrl = `${window.location.origin}/?join=${g.invite_code}`;

  /**
   * <summary>
   * Assigns <paramref name="driverId"/> as the driver for ISO day of week
   * <paramref name="dow"/> on this group's rotation.
   * </summary>
   * <param name="dow">ISO day of week (1=Mon ... 7=Sun).</param>
   * <param name="driverId">Member id who will drive that day.</param>
   * <remarks>
   * Sends the whole rotation object, not just the diff, since the API
   * treats it as a replacement. Errors surface in the top level error
   * banner.
   * </remarks>
   */
  async function setDayDriver(dow: number, driverId: number) {
    if (!g) return;
    const next = { ...g.rotation, [dow]: driverId };
    try {
      const updated = await api.setGroupRotation(id, next);
      setG(updated);
    } catch (e) {
      setErr(e instanceof Error ? e.message : String(e));
    }
  }

  /**
   * <summary>
   * Saves the current user's pickup pin and optional label for this
   * group's membership.
   * </summary>
   * <param name="pin">New coordinates, or null to clear the pin.</param>
   * <param name="label">Friendly text describing the pickup point. Empty
   * strings are stored as null.</param>
   */
  async function saveMyPin(pin: { lat: number; lng: number } | null, label: string) {
    setSavingHome(true);
    try {
      const updated = await api.updateMyGroupMembership(id, {
        home_lat:   pin?.lat ?? null,
        home_lng:   pin?.lng ?? null,
        home_label: label.trim() || null,
      });
      setG(updated);
    } catch (e) {
      setErr(e instanceof Error ? e.message : String(e));
    } finally {
      setSavingHome(false);
    }
  }

  // Transient result of the last "Copy link" press.
  const [copyState, setCopyState] = useState<'ok' | 'fail' | null>(null);
  useEffect(() => {
    if (!copyState) return;
    const t = setTimeout(() => setCopyState(null), 3000);
    return () => clearTimeout(t);
  }, [copyState]);

  /**
   * <summary>
   * Copies the group's invite URL to the clipboard and reports the
   * outcome next to the button.
   * </summary>
   * <remarks>
   * Clipboard writes fail on insecure origins and when the permission is
   * denied. Swallowing that silently made "Copy link" indistinguishable
   * from a no-op, and users pasted an empty clipboard into a group chat.
   * On failure the URL is selected in a prompt-free fallback message so
   * they can copy it by hand.
   * </remarks>
   */
  async function copyInvite() {
    try {
      await navigator.clipboard.writeText(inviteUrl);
      setCopyState('ok');
    } catch {
      setCopyState('fail');
    }
  }

  return (
    <div className="profile-form group-detail">
      <button className="link" onClick={onBack}>← Back to groups</button>

      <section>
        <h3>{g.name}</h3>
        <div className="muted small">
          {daysSummary(g.days_mask)} · {g.start_time}
          {g.dest_label && <> · destination: {g.dest_label}</>}
        </div>
      </section>

      <section>
        <h3>Invite</h3>
        <p className="muted small">Share this code or link to add others.</p>
        <div className="invite-row">
          <code className="invite-code">{g.invite_code}</code>
          <button type="button" className="link" onClick={copyInvite}>Copy link</button>
          {copyState === 'ok'   && <span className="muted small" role="status"> Copied</span>}
          {copyState === 'fail' && (
            <span className="muted small" role="status">
              {' '}Couldn't copy automatically, select the link above and copy it.
            </span>
          )}
        </div>
      </section>

      <section>
        <h3>Rotation</h3>
        <p className="muted small">
          {g.is_creator
            ? 'Pick who drives each day.'
            : 'Only the group creator can change the rotation.'}
        </p>
        <div className="rotation-grid">
          {DAY_LABELS.map((d, i) => {
            const bit = 1 << i;
            const dow = i + 1;            // ISO: Mon=1
            const active = (g.days_mask & bit) !== 0;
            const driverId = g.rotation[dow] ?? null;
            const driver = driverId ? g.members.find(m => m.id === driverId) : null;
            return (
              <div key={d} className={`rotation-cell ${active ? '' : 'inactive'}`}>
                <div className="rotation-day">{d}</div>
                {!active ? (
                  <div className="muted small">-</div>
                ) : g.is_creator ? (
                  <select
                    value={driverId ?? ''}
                    onChange={e => setDayDriver(dow, parseInt(e.target.value, 10))}
                  >
                    <option value="" disabled>(unassigned)</option>
                    {g.members.map(m => (
                      <option key={m.id} value={m.id}>{m.display_name}</option>
                    ))}
                  </select>
                ) : (
                  <div className="rotation-driver">
                    {driver ? driver.display_name : <span className="muted">unassigned</span>}
                  </div>
                )}
              </div>
            );
          })}
        </div>
      </section>

      <section>
        <h3>Members ({g.members.length})</h3>
        <ul className="member-list">
          {g.members.map(m => (
            <li key={m.id} className="member-row">
              <Avatar name={m.display_name} url={m.avatar_url} size={36} />
              <div className="member-body">
                <strong>{m.display_name}{m.id === myUserId && <span className="muted small"> (you)</span>}</strong>
                <div className="muted small">
                  {m.home_label
                    ? `pickup: ${m.home_label}`
                    : m.home_lat != null
                      ? `pickup: ${m.home_lat.toFixed(4)}, ${m.home_lng?.toFixed(4)}`
                      : 'no pickup pin set'}
                </div>
              </div>
            </li>
          ))}
        </ul>
      </section>

      {me && (
        <MyPickupSection
          initialPin={me.home_lat != null && me.home_lng != null ? { lat: me.home_lat, lng: me.home_lng } : null}
          initialLabel={me.home_label ?? ''}
          saving={savingHome}
          onSave={saveMyPin}
        />
      )}

      <section className="groups-form-actions">
        {g.is_creator ? (
          <button type="button" className="danger" onClick={() => setConfirmDelete(true)}>Delete group</button>
        ) : (
          <button type="button" className="link" onClick={() => setConfirmLeave(true)}>Leave group</button>
        )}
      </section>

      {confirmDelete && (
        <ConfirmModal
          title="Delete this group?"
          message="All members will lose access to the rotation, pickup pins, and invite code. This can't be undone."
          confirmLabel="Delete"
          cancelLabel="Keep group"
          tone="danger"
          onConfirm={async () => {
            // ConfirmModal closes itself synchronously, so an unhandled
            // rejection here looked exactly like a successful delete.
            try {
              await api.deleteGroup(id);
            } catch (e) {
              alert(`Couldn't delete this group: ${(e as Error)?.message || 'Unknown error'}`);
              return;
            }
            setConfirmDelete(false);
            onBack();
          }}
          onClose={() => setConfirmDelete(false)}
        />
      )}
      {confirmLeave && (
        <ConfirmModal
          title="Leave this group?"
          message="You'll be removed from the rotation. The creator can re-invite you with the code."
          confirmLabel="Leave"
          cancelLabel="Stay"
          tone="danger"
          onConfirm={async () => {
            try {
              await api.leaveGroup(id);
            } catch (e) {
              alert(`Couldn't leave this group: ${(e as Error)?.message || 'Unknown error'}`);
              return;
            }
            setConfirmLeave(false);
            onBack();
          }}
          onClose={() => setConfirmLeave(false)}
        />
      )}
    </div>
  );
}

/**
 * <summary>
 * Pickup pin and label editor for the current user's membership in a
 * group. Keeps local state so edits feel snappy but stays in sync with
 * the parent after a save.
 * </summary>
 * <param name="initialPin">Existing pin coordinates, or null when not
 * set.</param>
 * <param name="initialLabel">Existing pickup label, empty string when
 * not set.</param>
 * <param name="saving">True while a save is in flight, disables the save
 * button.</param>
 * <param name="onSave">Persists the edited pin and label.</param>
 * <remarks>
 * The <c>dirty</c> flag compares local state to the most recent props,
 * so the Save button only enables when there is something to save. Two
 * separate effects re-sync after the parent commits the new values.
 * </remarks>
 */
function MyPickupSection({ initialPin, initialLabel, saving, onSave }: {
  initialPin: { lat: number; lng: number } | null;
  initialLabel: string;
  saving: boolean;
  onSave: (pin: { lat: number; lng: number } | null, label: string) => void;
}) {
  const [pin, setPin]     = useState<{ lat: number; lng: number } | null>(initialPin);
  const [label, setLabel] = useState(initialLabel);

  // Re-sync if the parent state changes (e.g. after a save).
  useEffect(() => { setPin(initialPin); }, [initialPin?.lat, initialPin?.lng]);
  useEffect(() => { setLabel(initialLabel); }, [initialLabel]);

  const dirty =
    label !== initialLabel ||
    (pin?.lat ?? null) !== (initialPin?.lat ?? null) ||
    (pin?.lng ?? null) !== (initialPin?.lng ?? null);

  return (
    <section>
      <h3>My pickup point</h3>
      <p className="muted small">Where everyone meets when it's your day to drive.</p>
      <label>Label
        <input
          placeholder="e.g. 15 Glategny Esplanade"
          value={label}
          onChange={e => setLabel(e.target.value)}
          maxLength={160}
        />
      </label>
      <PinPicker value={pin} onChange={setPin} />
      <button
        type="button"
        className="primary"
        disabled={!dirty || saving}
        onClick={() => onSave(pin, label)}
      >
        {saving ? 'Saving…' : 'Save my pickup'}
      </button>
    </section>
  );
}

/**
 * <summary>
 * Renders a day mask as a compact human readable summary.
 * </summary>
 * <param name="mask">Bitmask of active days (bit 0 = Mon ... bit 6 = Sun).</param>
 * <returns>"no days", "every day", a "Mon to Fri" style range when the
 * active days form a contiguous run of three or more, or a space
 * separated list of individual labels otherwise.</returns>
 * <remarks>
 * The contiguous run check is over the original ISO order, so the typical
 * weekday and weekend patterns both render as ranges. Anything sparser
 * falls back to the listed form.
 * </remarks>
 */
function daysSummary(mask: number): string {
  const on = DAY_LABELS.filter((_, i) => (mask & (1 << i)) !== 0);
  if (on.length === 0) return 'no days';
  if (on.length === 7) return 'every day';
  // Compact "Mon-Fri" if it's a contiguous run.
  const indexes = DAY_LABELS.map((_, i) => (mask & (1 << i)) !== 0 ? i : -1).filter(i => i >= 0);
  const contiguous = indexes.every((v, k, arr) => k === 0 || v === arr[k - 1] + 1);
  if (contiguous && on.length >= 3) return `${on[0]}-${on[on.length - 1]}`;
  return on.join(' ');
}
