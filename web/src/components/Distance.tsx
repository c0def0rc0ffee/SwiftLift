import { CSSProperties } from 'react';
import { fmtDistance, toggleUnit, useUnit } from '../lib/units';

/**
 * <summary>
 * Props for <see cref="Distance"/>.
 * </summary>
 */
type Props = {
  /** Distance in metres. */
  m: number;
  /** Extra class names merged onto the span. */
  className?: string;
  /** Inline style overrides. */
  style?: CSSProperties;
};

/**
 * <summary>
 * Click to toggle distance label. Renders the value in the current
 * unit preference (km or miles); a tap flips the global preference
 * and every other <c>Distance</c> on screen updates immediately.
 * </summary>
 * <param name="m">Distance in metres.</param>
 * <param name="className">Extra class names.</param>
 * <param name="style">Inline style overrides.</param>
 * <remarks>
 * Uses a span with <c>role="button"</c> so it can be nested inside
 * other clickable surfaces (buttons, card rows) without producing
 * invalid HTML. Click events stop propagation so the parent row does
 * not also fire its own onClick.
 * </remarks>
 */
export function Distance({ m, className = '', style }: Props) {
  const unit = useUnit();
  return (
    <span
      role="button"
      tabIndex={0}
      className={`unit-toggle ${className}`}
      style={style}
      title={unit === 'km' ? 'Click to switch to miles' : 'Click to switch to kilometres'}
      onClick={e => { e.stopPropagation(); toggleUnit(); }}
      onKeyDown={e => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault(); e.stopPropagation();
          toggleUnit();
        }
      }}
    >
      {fmtDistance(m, unit)}
    </span>
  );
}
