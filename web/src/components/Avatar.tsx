import { useEffect, useState } from 'react';
import { AVATARS_ENABLED } from '../lib/features';

/**
 * <summary>
 * Props for <see cref="Avatar"/>.
 * </summary>
 */
type Props = {
  /** Display name. Used for the alt text and as the seed for the fallback colour / initials. */
  name: string;
  /** Optional image URL. When missing, broken, or blocked, the initials fallback is shown. */
  url?: string | null;
  /** Square pixel size of the avatar. Defaults to 36. */
  size?: number;
  /** Extra class names merged onto the rendered element. */
  className?: string;
};

/**
 * <summary>
 * Shows a circular avatar for a person: a remote image when one is
 * available, falling back to a coloured circle with their initials.
 * </summary>
 * <param name="name">Display name (used for alt text, initials and a stable colour seed).</param>
 * <param name="url">Optional avatar URL.</param>
 * <param name="size">Square pixel size, default 36.</param>
 * <param name="className">Extra class names.</param>
 * <remarks>
 * Tracks an error state so a broken <c>url</c> (404, network failure,
 * CSP block) falls back to the initials circle instead of showing an
 * empty space. The earlier version set <c>display:none</c> on the
 * <c>img</c>, which left a blank slot at the avatar's size.
 *
 * The fallback colour is derived from a stable hash of the name so each
 * person keeps a consistent colour across the app.
 * </remarks>
 */
export function Avatar({ name, url, size = 36, className }: Props) {
  // Track an error state so a broken `url` (404 / network fail / blocked
  // by CSP) falls back to the initials circle instead of showing an
  // empty space. The earlier version set display:none on the <img>,
  // which left a blank slot at the avatar's size.
  const [failed, setFailed] = useState(false);
  useEffect(() => { setFailed(false); }, [url]);

  const initials = name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map(s => s[0]?.toUpperCase() ?? '')
    .join('') || '?';

  // Stable hue from the name so each person has a consistent colour.
  let h = 0;
  for (let i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) >>> 0;
  const hue = h % 360;

  const style: React.CSSProperties = {
    width: size, height: size, fontSize: Math.max(10, size * 0.38),
    background: `hsl(${hue}, 45%, 30%)`,
    color: `hsl(${hue}, 65%, 88%)`,
  };

  // While avatar images are disabled (no moderation, abuse vector) every
  // avatar renders as initials, regardless of any url already on the row.
  if (AVATARS_ENABLED && url && !failed) {
    return (
      <img
        src={url}
        alt={name}
        className={`avatar ${className ?? ''}`}
        style={{ width: size, height: size }}
        onError={() => setFailed(true)}
      />
    );
  }

  return <div className={`avatar avatar-fallback ${className ?? ''}`} style={style}>{initials}</div>;
}
