import { FormEvent, useEffect, useRef, useState } from 'react';
import { api, PASSWORD_MIN, PetsPref, ProfileUpdate, SmokingPref, Theme, User } from '../api';
import { Avatar } from '../components/Avatar';
import { BlockList } from '../components/BlockList';
import { Distance } from '../components/Distance';
import { fmtMinutes } from '../lib/units';
import { AVATARS_ENABLED } from '../lib/features';

/**
 * <summary>
 * Deep link hint: which setting the user came to the Profile view to fix.
 * </summary>
 * <remarks>
 * RideApp sets this when the user clicks "Open Profile" from somewhere
 * that points at a specific control. Currently only the Away mode notice
 * on <see cref="JourneyCard"/> and <see cref="MatchesModal"/> uses it. On
 * mount the matching control is scrolled into view and pulsed so the
 * user can see exactly where to act.
 * </remarks>
 */
export type ProfileFocus = 'away';

/**
 * <summary>
 * Props for <see cref="ProfileView"/>.
 * </summary>
 */
type Props = {
  /** The current signed in user, source of truth for every form's
   *  initial values. */
  user: User;
  /** Returns to the map view. */
  onClose: () => void;
  /** Called with the updated user after a successful profile save so the
   *  parent can keep its own copy in sync. */
  onUpdated: (user: User) => void;
  /** Called after a successful account deletion. The parent typically
   *  signs the user out and returns to the auth screen. */
  onDeleted: () => void;
  /** Signs the user out of this device without deleting any data. */
  onLogout: () => void;
  /** Optional deep link hint, see <see cref="ProfileFocus"/>. */
  focus?: ProfileFocus | null;
};

/**
 * <summary>
 * Profile and settings page where the user can edit their identity (name,
 * email, avatar, bio), their match filter defaults (age, sex, preferences),
 * their vehicle and ride preferences, their general preferences (Away
 * mode, theme, default radius and window), change their password, manage
 * blocked users, export their data, sign out, or permanently delete their
 * account.
 * </summary>
 * <param name="user">The current user, used to seed every form.</param>
 * <param name="onClose">Returns to the map view.</param>
 * <param name="onUpdated">Hands the updated user back to the parent.</param>
 * <param name="onDeleted">Fires after a successful account deletion.</param>
 * <param name="onLogout">Signs the user out without deleting data.</param>
 * <param name="focus">Optional deep link target, scrolls and pulses the
 * referenced control on mount.</param>
 * <remarks>
 * Holds many independent slices of state because each section can save on
 * its own. The big <see cref="saveProfile"/> form builds a single
 * <see cref="ProfileUpdate"/> patch and sends it in one round trip.
 * Password changes are a separate form so the user does not have to
 * scroll back to the main form after entering the current password.
 *
 * The deep link focus mechanism uses the same <c>.field-highlight</c>
 * animation the journey editor uses for missing field feedback, so the
 * visual language is consistent across the app.
 *
 * Empty strings in optional fields (age, car details, music preference,
 * etc.) coerce to null on save so the server treats them as "not set"
 * rather than literal empty values.
 * </remarks>
 */
export function ProfileView({ user, onClose, onUpdated, onDeleted, onLogout, focus }: Props) {
  const [displayName, setDisplayName] = useState(user.display_name);
  const [email, setEmail]             = useState(user.email);
  const [avatarUrl, setAvatarUrl]     = useState(user.avatar_url ?? '');
  const [bio, setBio]                 = useState(user.bio ?? '');
  const [isAway, setIsAway]           = useState(user.is_away);
  const [notifyMatches, setNotifyMatches] = useState(user.notify_matches ?? true);
  const [theme, setTheme]             = useState<Theme>(user.theme);
  const [radiusKm, setRadiusKm]       = useState(user.default_radius_m / 1000);
  const [windowMin, setWindowMin]     = useState(user.default_window_min);

  const [age, setAge]             = useState<number | ''>(user.age ?? '');
  const [ageMin, setAgeMin]       = useState<number | ''>(user.age_min ?? '');
  const [ageMax, setAgeMax]       = useState<number | ''>(user.age_max ?? '');
  // Sex: '' (= prefer not to say) | 'male' | 'female'
  const [sex, setSex]             = useState<'' | 'male' | 'female'>(user.sex ?? '');
  // Match filter, the kind of people you want to ride with.
  const [prefSex, setPrefSex]     = useState<'any' | 'male' | 'female'>(user.pref_sex ?? 'any');

  // Vehicle / preferences
  const [carMake, setCarMake]     = useState(user.car_make ?? '');
  const [carColour, setCarColour] = useState(user.car_colour ?? '');
  const [carSeats, setCarSeats]   = useState<number | ''>(user.car_seats ?? '');
  const [smoking, setSmoking]     = useState<SmokingPref | ''>(user.pref_smoking ?? '');
  const [pets, setPets]           = useState<PetsPref | ''>(user.pref_pets ?? '');
  const [music, setMusic]         = useState(user.pref_music ?? '');
  const [detourM, setDetourM]     = useState<number>(user.detour_m ?? 0);

  const [currentPw, setCurrentPw] = useState('');
  const [newPw, setNewPw]         = useState('');

  const [busy, setBusy]     = useState(false);
  const [err, setErr]       = useState<string | null>(null);
  const [ok, setOk]         = useState<string | null>(null);

  // Deep-link focus: scroll the targeted control into view and pulse it.
  // The pulse re-uses the same .field-highlight animation the journey
  // editor uses for missing-field feedback, so the visual language is
  // consistent across the app.
  const awayRef = useRef<HTMLLabelElement>(null);
  const [highlightAway, setHighlightAway] = useState(false);
  useEffect(() => {
    if (focus !== 'away') return;
    // Defer one tick so the element is in the DOM before we scroll.
    const id = requestAnimationFrame(() => {
      awayRef.current?.scrollIntoView({ behavior: 'smooth', block: 'center' });
      setHighlightAway(true);
    });
    const t = setTimeout(() => setHighlightAway(false), 1700);
    return () => { cancelAnimationFrame(id); clearTimeout(t); };
  }, [focus]);

  /**
   * <summary>
   * Builds a <see cref="ProfileUpdate"/> patch from every form field and
   * posts it as a single update. On success calls
   * <see cref="onUpdated"/> with the server's view of the new user.
   * </summary>
   * <param name="e">Form submission event, default prevented.</param>
   * <remarks>
   * Trims string fields, coerces empty optionals to null, and converts
   * the radius slider from kilometres back to metres so the wire format
   * stays in metres regardless of how the slider is displayed.
   * </remarks>
   */
  async function saveProfile(e: FormEvent) {
    e.preventDefault();
    setErr(null); setOk(null); setBusy(true);
    try {
      const patch: ProfileUpdate = {
        display_name: displayName.trim(),
        email: email.trim(),
        // Avatar images are disabled (no moderation). Omit the field so the
        // server never sees it; the server ignores it anyway as a backstop.
        ...(AVATARS_ENABLED ? { avatar_url: avatarUrl.trim() || null } : {}),
        bio: bio.trim() || null,
        age: age === '' ? null : Number(age),
        age_min: ageMin === '' ? null : Number(ageMin),
        age_max: ageMax === '' ? null : Number(ageMax),
        sex: sex === '' ? null : sex,
        pref_sex: prefSex,
        is_away: isAway,
        notify_matches: notifyMatches,
        theme,
        default_radius_m: Math.round(radiusKm * 1000),
        default_window_min: windowMin,
        car_make:    carMake.trim() || null,
        car_colour:  carColour.trim() || null,
        car_seats:   carSeats === '' ? null : Number(carSeats),
        pref_smoking: smoking || null,
        pref_pets:    pets    || null,
        pref_music:   music.trim() || null,
        detour_m:     detourM,
      };
      const { user: updated } = await api.updateProfile(patch);
      onUpdated(updated);
      setOk('Saved.');
    } catch (e) {
      setErr(e instanceof Error ? e.message : String(e));
    } finally {
      setBusy(false);
    }
  }

  /**
   * <summary>
   * Posts a password change. The current password is required so a
   * stolen session cannot silently swap credentials.
   * </summary>
   * <param name="e">Form submission event, default prevented.</param>
   * <remarks>
   * Clears both password fields on success and shows a green confirmation
   * line. Errors surface in the shared <c>err</c> banner above the
   * forms.
   * </remarks>
   */
  async function savePassword(e: FormEvent) {
    e.preventDefault();
    setErr(null); setOk(null); setBusy(true);
    try {
      await api.changePassword(currentPw, newPw);
      setCurrentPw(''); setNewPw('');
      setOk('Password changed.');
    } catch (e) {
      setErr(e instanceof Error ? e.message : String(e));
    } finally {
      setBusy(false);
    }
  }

  /**
   * <summary>
   * Permanently deletes the user's account after two explicit
   * <c>window.confirm</c> prompts. On success calls
   * <see cref="onDeleted"/> so the parent can sign the user out.
   * </summary>
   * <remarks>
   * The double prompt is intentional friction, account deletion is
   * irreversible and removes every journey. Existing message threads
   * remain visible to the other participant but the deleted user shows
   * as "(deleted user)".
   * </remarks>
   */
  async function deleteAccount() {
    if (!confirm('Delete your account? This removes all your journeys permanently.')) return;
    if (!confirm('Really delete? This cannot be undone.')) return;
    setBusy(true);
    try {
      await api.deleteAccount();
      onDeleted();
    } catch (e) {
      setErr(e instanceof Error ? e.message : String(e));
      setBusy(false);
    }
  }

  return (
    <div className="page profile-page">
      <div className="page-inner">
        <header className="page-header">
          <button className="link" onClick={onClose}>← Back to map</button>
          <div className="page-title">
            <Avatar name={displayName || 'You'} url={avatarUrl || user.avatar_url} size={56} />
            <div>
              <h1>Profile &amp; settings</h1>
              <div className="muted small">{user.email}</div>
            </div>
          </div>
        </header>

        {err && <div className="error">{err}</div>}
        {ok  && <div className="success">{ok}</div>}

        <form className="profile-form" onSubmit={saveProfile}>
          <section>
            <h3>About you</h3>

            <label>Display name
              <input value={displayName} onChange={e => setDisplayName(e.target.value)} required />
            </label>

            <label>Email
              <input type="email" value={email} onChange={e => setEmail(e.target.value)} required />
            </label>

            {AVATARS_ENABLED && (
              <label>Avatar image URL
                <input type="url" value={avatarUrl} onChange={e => setAvatarUrl(e.target.value)}
                       placeholder="https://… (leave blank for initials)" />
              </label>
            )}

            <label>Short bio
              <textarea value={bio} maxLength={280} rows={2}
                        onChange={e => setBio(e.target.value)}
                        placeholder="Morning person, no music, tea not coffee" />
            </label>
          </section>

          <section>
            <h3>Who I'll ride with</h3>
            <p className="muted small">
              <b>Age and sex are both optional.</b> Heads up though: if other
              users set a filter on age or sex, they only see people who
              match it. Leaving yours blank means those people won't see
              you at all. The age range and sex preference below are the
              <b> default</b> filter on other people. Each new journey
              you create starts with these values, and can be tightened or
              loosened from the journey editor.
            </p>

            <h4 className="subhead">Age</h4>
            <div className="age-grid">
              <label>My age
                <input type="number" inputMode="numeric" min={18} max={120}
                       value={age}
                       onChange={e => setAge(e.target.value === '' ? '' : Number(e.target.value))}
                       placeholder="optional" />
              </label>
              <label>Min. age of others
                <input type="number" inputMode="numeric" min={18} max={120}
                       value={ageMin}
                       onChange={e => setAgeMin(e.target.value === '' ? '' : Number(e.target.value))}
                       placeholder="any" />
              </label>
              <label>Max. age of others
                <input type="number" inputMode="numeric" min={18} max={120}
                       value={ageMax}
                       onChange={e => setAgeMax(e.target.value === '' ? '' : Number(e.target.value))}
                       placeholder="any" />
              </label>
            </div>
            {ageMin !== '' && ageMax !== '' && Number(ageMin) > Number(ageMax) && (
              <div className="error small">Minimum can't be greater than maximum.</div>
            )}
            <p className="muted xsmall">Minimum is 18.</p>

            <h4 className="subhead">Sex</h4>
            <div className="sex-grid">
              <label>My sex
                <select value={sex} onChange={e => setSex(e.target.value as '' | 'male' | 'female')}>
                  <option value="">Prefer not to say</option>
                  <option value="male">Male</option>
                  <option value="female">Female</option>
                </select>
              </label>
              <label>Match preference
                <select value={prefSex} onChange={e => setPrefSex(e.target.value as 'any' | 'male' | 'female')}>
                  <option value="any">Anyone</option>
                  <option value="male">Men only</option>
                  <option value="female">Women only</option>
                </select>
              </label>
            </div>
          </section>

          <section>
            <h3>Vehicle &amp; ride preferences</h3>
            <p className="muted small">
              Shown to people you've connected with. Drivers fill in the car details;
              passengers can leave the vehicle blank but still set their preferences.
            </p>

            <div className="age-grid">
              <label>Car make / model
                <input value={carMake} maxLength={60}
                       onChange={e => setCarMake(e.target.value)}
                       placeholder="e.g. Honda Jazz" />
              </label>
              <label>Colour
                <input value={carColour} maxLength={40}
                       onChange={e => setCarColour(e.target.value)}
                       placeholder="e.g. silver" />
              </label>
              <label>Seats (excl. driver)
                <input type="number" inputMode="numeric" min={1} max={8}
                       value={carSeats}
                       onChange={e => setCarSeats(e.target.value === '' ? '' : Number(e.target.value))}
                       placeholder="any" />
              </label>
            </div>

            <div className="age-grid">
              <label>Smoking
                <select value={smoking} onChange={e => setSmoking(e.target.value as SmokingPref | '')}>
                  <option value="">No preference</option>
                  <option value="no">No smoking</option>
                  <option value="outside">Outside the car only</option>
                  <option value="yes">OK with smoking</option>
                </select>
              </label>
              <label>Pets
                <select value={pets} onChange={e => setPets(e.target.value as PetsPref | '')}>
                  <option value="">No preference</option>
                  <option value="no">No pets</option>
                  <option value="small_only">Small pets only</option>
                  <option value="yes">Pets welcome</option>
                </select>
              </label>
              <label>Music / chat
                <input value={music} maxLength={80}
                       onChange={e => setMusic(e.target.value)}
                       placeholder="e.g. quiet please" />
              </label>
            </div>

            <label>Willing to detour up to: <strong>{detourM === 0 ? 'no detour' : <Distance m={detourM} />}</strong>
              <input type="range" min={0} max={5000} step={100}
                     value={detourM} onChange={e => setDetourM(Number(e.target.value))} />
            </label>
          </section>

          <section>
            <h3>Preferences</h3>

            <label
              ref={awayRef}
              className={`inline-check ${highlightAway ? 'field-highlight' : ''}`}
            >
              <input type="checkbox" checked={isAway} onChange={e => setIsAway(e.target.checked)} />
              <span><b>Away mode</b>: hide me and my journeys from matches</span>
            </label>

            <label>Map style
              <select value={theme} onChange={e => setTheme(e.target.value as Theme)}>
                <option value="dark">Dark</option>
                <option value="light">Light</option>
                <option value="auto">Auto (follow system)</option>
              </select>
            </label>

            <label>Default match radius: <strong><Distance m={Math.min(radiusKm, 2) * 1000} /></strong>
              <input type="range" min={0.1} max={2} step={0.1}
                     value={Math.min(radiusKm, 2)} onChange={e => setRadiusKm(Number(e.target.value))} />
            </label>

            <label>Default time window: <strong>±{fmtMinutes(windowMin)}</strong>
              <input type="range" min={5} max={240} step={5}
                     value={windowMin} onChange={e => setWindowMin(Number(e.target.value))} />
            </label>
          </section>

          <section>
            <h3>Notifications</h3>
            <label className="inline-check">
              <input type="checkbox" checked={notifyMatches}
                     onChange={e => setNotifyMatches(e.target.checked)} />
              <span>
                <b>Match alerts</b>: email me when a new journey matches one of mine,
                so I don't have to keep checking back. We'll only ever email about a
                genuine match, and never share your address.
              </span>
            </label>
            {!user.email_verified && (
              <p className="muted small">
                Match alert emails start once your email address is verified.
              </p>
            )}
          </section>

          <div className="page-actions">
            <button type="submit" className="primary" disabled={busy}>{busy ? '…' : 'Save profile'}</button>
            <button type="button" onClick={onClose}>Back to map</button>
          </div>
        </form>

        <form className="profile-form" onSubmit={savePassword}>
          <section>
            <h3>Change password</h3>
            <label>Current password
              <input type="password" value={currentPw} onChange={e => setCurrentPw(e.target.value)} />
            </label>
            <label>New password (min {PASSWORD_MIN} chars)
              <input type="password" minLength={PASSWORD_MIN} value={newPw} onChange={e => setNewPw(e.target.value)} />
            </label>
            <div className="page-actions">
              <button type="submit" className="primary" disabled={busy || !currentPw || newPw.length < PASSWORD_MIN}>
                Update password
              </button>
            </div>
          </section>
        </form>

        <BlockList />

        <section className="data-export">
          <h3>Your data</h3>
          <p className="muted small">
            Download everything SwiftLift holds about you (your profile, journeys,
            lift requests, messages, blocks and reports) as a single JSON file.
          </p>
          {/* Plain &lt;a&gt; with download attribute, the browser writes the
              file straight to disk based on the server's Content-Disposition. */}
          <a className="small primary" href="/api/index.php?p=profile/export" download>
            Download my data
          </a>
        </section>

        <section>
          <h3>Sign out</h3>
          <p className="muted small">
            Sign out of SwiftLift on this device. Your data and journeys stay
            put; you'll just need your password to come back.
          </p>
          <button type="button" className="small" onClick={onLogout}>Sign out</button>
        </section>

        <section className="danger-zone">
          <h3>Danger zone</h3>
          <p className="muted small">
            Deleting your account anonymises your profile and removes all your
            journeys. Existing connections will see you as <em>(deleted user)</em>;
            the conversation history they already have stays intact.
          </p>
          <button type="button" className="danger" disabled={busy} onClick={deleteAccount}>Delete my account</button>
        </section>
      </div>
    </div>
  );
}
