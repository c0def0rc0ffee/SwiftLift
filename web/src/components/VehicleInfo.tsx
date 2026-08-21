import { PetsPref, SmokingPref } from '../api';
import { fmtDistance, useUnit } from '../lib/units';

/**
 * <summary>
 * Maps a smoking preference enum to its human label.
 * </summary>
 */
const SMOKING_LABEL: Record<SmokingPref, string> = {
  yes: 'Smoking OK',
  no: 'No smoking',
  outside: 'Smoke breaks outside',
};

/**
 * <summary>
 * Maps a pets preference enum to its human label.
 * </summary>
 */
const PETS_LABEL: Record<PetsPref, string> = {
  yes: 'Pets OK',
  no: 'No pets',
  small_only: 'Small pets OK',
};

/**
 * <summary>
 * Props for <see cref="VehicleInfo"/>. All fields are optional; the
 * component shows only the chips that have data.
 * </summary>
 */
type Props = {
  /** Vehicle make, e.g. "Hyundai". Combined with colour into a single chip. */
  car_make?: string | null;
  /** Vehicle colour, e.g. "white". */
  car_colour?: string | null;
  /** Smoking preference. */
  pref_smoking?: SmokingPref | null;
  /** Pets preference. */
  pref_pets?: PetsPref | null;
  /** Free text music preference, prefixed with a musical note. */
  pref_music?: string | null;
  /** Acceptable detour, in metres, for picking the other party up. */
  detour_m?: number;
  /** Extra class names merged onto the wrapper. */
  className?: string;
};

/**
 * <summary>
 * Compact pill row showing the relevant vehicle and travelling
 * preferences for a person: car colour and make, smoking, pets, music
 * preference, and detour tolerance.
 * </summary>
 * <param name="p">Props bag (see <see cref="Props"/>).</param>
 * <remarks>
 * Renders <c>null</c> when there is nothing meaningful to show. The
 * detour value uses the user's current unit preference via
 * <c>useUnit</c>.
 * </remarks>
 */
export function VehicleInfo(p: Props) {
  const unit = useUnit();
  const carBits = [p.car_colour, p.car_make].filter(Boolean).join(' ').trim();
  const chips: string[] = [];
  if (carBits) chips.push(carBits);
  if (p.pref_smoking) chips.push(SMOKING_LABEL[p.pref_smoking]);
  if (p.pref_pets)    chips.push(PETS_LABEL[p.pref_pets]);
  if (p.pref_music)   chips.push(`♪ ${p.pref_music}`);
  if (p.detour_m && p.detour_m > 0) {
    chips.push(`${fmtDistance(p.detour_m, unit)} detour OK`);
  }

  if (chips.length === 0) return null;

  return (
    <div className={`vehicle-info ${p.className ?? ''}`}>
      {chips.map((c, i) => <span key={i} className="veh-chip">{c}</span>)}
    </div>
  );
}
