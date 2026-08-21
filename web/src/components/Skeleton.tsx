import { CSSProperties } from 'react';

/**
 * <summary>
 * Props for <see cref="Skeleton"/>.
 * </summary>
 */
type Props = {
  /** Height. Numbers are treated as pixels; strings are passed through. Defaults to 16. */
  height?: number | string;
  /** Width. Numbers are treated as pixels; strings are passed through. Defaults to 100%. */
  width?: number | string;
  /** Border radius. Defaults to 4. */
  rounded?: number | string;
  /** Extra class names merged onto the element. */
  className?: string;
  /** Inline style overrides applied after the size and radius. */
  style?: CSSProperties;
};

/**
 * <summary>
 * Single shimmer block used as a loading placeholder.
 * </summary>
 * <param name="height">Block height.</param>
 * <param name="width">Block width.</param>
 * <param name="rounded">Corner radius.</param>
 * <param name="className">Extra class names.</param>
 * <param name="style">Inline style overrides.</param>
 * <remarks>
 * Pairs with the <c>.skeleton</c> class in <c>styles.css</c> for the
 * shimmer animation. Rendered with <c>aria-hidden="true"</c> so screen
 * readers skip it.
 * </remarks>
 */
export function Skeleton({ height = 16, width = '100%', rounded = 4, className = '', style }: Props) {
  return (
    <span
      className={`skeleton ${className}`}
      style={{ height, width, borderRadius: rounded, ...style }}
      aria-hidden="true"
    />
  );
}

/**
 * <summary>
 * Loading placeholder shaped like a collapsed <see cref="JourneyCard"/> head.
 * </summary>
 */
export function JourneySkeletonRow() {
  return (
    <div className="journey-card skeleton-card">
      <div className="journey-card-head">
        <div className="journey-card-title">
          <Skeleton height={16} width="60%" />
          <Skeleton height={12} width="35%" style={{ marginTop: 6 }} />
        </div>
        <Skeleton height={20} width={20} rounded={10} />
      </div>
    </div>
  );
}

/**
 * <summary>
 * Loading placeholder shaped like a single match row (avatar plus
 * three lines of text).
 * </summary>
 */
export function MatchSkeletonRow() {
  return (
    <div className="match-row">
      <Skeleton height={32} width={32} rounded={16} />
      <div className="match-body" style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
        <Skeleton height={14} width="40%" />
        <Skeleton height={12} width="80%" />
        <Skeleton height={12} width="55%" />
      </div>
    </div>
  );
}

/**
 * <summary>
 * Loading placeholder shaped like a short message thread: three chat
 * bubbles in alternating sides.
 * </summary>
 */
export function MessageSkeleton() {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
      <Skeleton className="bubble theirs" height={28} width="55%" rounded={12} />
      <Skeleton className="bubble mine"   height={28} width="40%" rounded={12} style={{ alignSelf: 'flex-end' }} />
      <Skeleton className="bubble theirs" height={28} width="60%" rounded={12} />
    </div>
  );
}
