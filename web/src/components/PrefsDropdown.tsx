import { useEffect, useRef, useState } from 'react';
import { PetsPref, SmokingPref } from '../api';
import { VehicleInfo } from './VehicleInfo';

/**
 * <summary>
 * Props for <see cref="PrefsDropdown"/>. The name and age render on the
 * clickable trigger; the remaining fields populate the dropdown panel.
 * </summary>
 */
type Props = {
  /** Display name shown on the trigger. */
  name: string;
  /** Age shown as a small chip next to the name, when known. */
  age?: number | null;
  /** Short free-text bio, shown at the top of the panel when present. */
  bio?: string | null;
  /** Vehicle make. */
  car_make?: string | null;
  /** Vehicle colour. */
  car_colour?: string | null;
  /** Smoking preference. */
  pref_smoking?: SmokingPref | null;
  /** Pets preference. */
  pref_pets?: PetsPref | null;
  /** Free-text music preference. */
  pref_music?: string | null;
  /** Acceptable pick-up detour in metres. */
  detour_m?: number;
};

/**
 * <summary>
 * A person's name rendered as a button that toggles a small dropdown of
 * their travelling preferences (vehicle, smoking, pets, music, detour).
 * </summary>
 * <param name="p">Props bag (see <see cref="Props"/>).</param>
 * <remarks>
 * Used in the matches list so a passenger can quickly check a driver's
 * preferences without cluttering the row. Closes on outside click or
 * Escape. Falls back to a "no preferences shared yet" note when the
 * person has set none.
 * </remarks>
 */
export function PrefsDropdown({ name, age, bio, ...prefs }: Props) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLSpanElement>(null);

  // Close on outside click or Escape while open.
  useEffect(() => {
    if (!open) return;
    /**
     * <summary>Closes the dropdown on a mousedown outside it.</summary>
     * <param name="e">The document-level mousedown event.</param>
     */
    function onDoc(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false);
    }
    /**
     * <summary>Closes the dropdown on Escape.</summary>
     * <param name="e">The document-level keydown event.</param>
     */
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape') setOpen(false);
    }
    document.addEventListener('mousedown', onDoc);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDoc);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  const hasPrefs = !!(
    prefs.car_make || prefs.car_colour || prefs.pref_smoking ||
    prefs.pref_pets || prefs.pref_music || (prefs.detour_m && prefs.detour_m > 0)
  );

  return (
    <span className="prefs-dd" ref={ref}>
      <button
        type="button"
        className={`prefs-dd-trigger ${open ? 'open' : ''}`}
        onClick={() => setOpen(o => !o)}
        aria-expanded={open}
        title="See preferences"
      >
        <strong>{name}</strong>
        {age != null && <span className="age-chip"> · {age}</span>}
        <span className="prefs-dd-caret" aria-hidden="true">▾</span>
      </button>

      {open && (
        <div className="prefs-dd-panel" role="menu">
          <div className="prefs-dd-title">Preferences</div>
          {bio && <p className="prefs-dd-bio">&ldquo;{bio}&rdquo;</p>}
          {hasPrefs && <VehicleInfo {...prefs} className="prefs-dd-chips" />}
          {!hasPrefs && !bio && <div className="muted small">Nothing shared yet.</div>}
        </div>
      )}
    </span>
  );
}
