// Single source of truth for the SwiftLift logo path. Used inline in the
// AppBar and on the Login screen so both render the same shape and keep in
// sync if the design changes.

/**
 * <summary>
 * Props for <see cref="BrandMark"/>.
 * </summary>
 * <remarks>
 * Two flavours are supported:
 * <c>solid</c> fills the pin with <c>currentColor</c> (inherits the
 * surrounding text colour) and is used in the AppBar so it picks up the
 * accent green from the brand button.
 * <c>gradient</c> uses the brand green gradient, optionally on a
 * rounded dark green tile (set <c>tile</c> to true), and is used on the
 * Login card.
 *
 * The path data is the user's exported roadworks pin SVG, viewBox 547x630.
 * </remarks>
 */
type Props = {
  /** Rendering style. See <see cref="Props"/> remarks. */
  variant?: 'solid' | 'gradient';
  /** Wrap the gradient mark in a dark green rounded square. Has no effect on <c>solid</c>. */
  tile?: boolean;
  /** Unique id suffix for the SVG <c>linearGradient</c> so multiple instances on the same page don't collide. */
  idSuffix?: string;
};

/**
 * <summary>
 * Raw SVG path data for the SwiftLift roadworks pin logo. ViewBox is 547x630.
 * </summary>
 */
const PIN_PATH = "M 265 16 L 190 37 L 154 61 L 125 91 L 91 155 L 82 231 L 96 291 L 126 353 L 268 587 L 288 594 L 303 581 L 458 316 L 478 262 L 483 201 L 468 137 L 448 101 L 415 64 L 349 26 L 310 17 Z M 224 56 L 302 48 L 360 64 L 409 102 L 441 154 L 452 201 L 442 273 L 358 428 L 317 400 L 290 361 L 295 339 L 314 319 L 358 296 L 387 304 L 404 285 L 402 192 L 380 174 L 362 132 L 346 116 L 236 111 L 214 119 L 186 173 L 162 196 L 161 284 L 170 300 L 188 305 L 204 295 L 208 274 L 349 278 L 254 316 L 178 377 L 122 272 L 114 199 L 128 145 L 174 85 Z M 211 176 L 225 145 L 231 139 L 236 137 L 329 137 L 333 139 L 340 146 L 349 169 L 352 173 L 353 179 L 352 180 L 212 180 Z M 264 339 L 267 340 L 267 343 L 261 362 L 261 375 L 268 396 L 279 413 L 293 428 L 315 447 L 335 461 L 335 465 L 321 487 L 319 487 L 300 473 L 276 453 L 254 429 L 242 408 L 238 389 L 240 372 L 249 354 Z M 374 214 L 374 215 L 375 216 L 375 220 L 376 221 L 376 224 L 375 225 L 375 227 L 374 228 L 374 230 L 369 235 L 368 235 L 367 236 L 364 236 L 363 237 L 343 237 L 342 236 L 338 236 L 337 235 L 336 235 L 331 230 L 331 229 L 330 228 L 330 220 L 335 215 L 338 214 L 340 212 L 341 212 L 344 210 L 346 210 L 347 209 L 349 209 L 350 208 L 353 208 L 354 207 L 366 207 L 367 208 L 368 208 Z M 189 220 L 191 217 L 191 215 L 192 214 L 192 213 L 197 208 L 198 208 L 199 207 L 211 207 L 212 208 L 215 208 L 216 209 L 218 209 L 219 210 L 221 210 L 222 211 L 223 211 L 225 213 L 226 213 L 227 214 L 230 215 L 234 219 L 234 220 L 235 221 L 235 228 L 234 229 L 233 232 L 231 234 L 230 234 L 227 236 L 224 236 L 223 237 L 203 237 L 202 236 L 199 236 L 198 235 L 196 235 L 192 231 L 192 230 L 190 227 L 190 224 L 189 223 Z";

/**
 * <summary>
 * SwiftLift logo mark. Renders the pin as either a solid mono colour
 * (inheriting <c>currentColor</c>) or as a brand green gradient,
 * optionally inside a rounded dark green tile.
 * </summary>
 * <param name="variant">Choose <c>solid</c> (inherits colour) or <c>gradient</c> (brand green).</param>
 * <param name="tile">When true and variant is gradient, wrap the pin in a dark green rounded square.</param>
 * <param name="idSuffix">Suffix used on the SVG gradient id so multiple instances on the same page don't collide.</param>
 * <remarks>
 * Single source of truth for the logo: AppBar and Login render through
 * the same component so the shape stays in sync if the design changes.
 * </remarks>
 */
export function BrandMark({ variant = 'solid', tile = false, idSuffix = 'main' }: Props) {
  if (variant === 'solid') {
    return (
      <svg viewBox="0 0 547 630" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <path d={PIN_PATH} fill="currentColor" fillRule="evenodd" />
      </svg>
    );
  }

  const gradId = `brand-grad-${idSuffix}`;
  // Gradient runs across the pin's natural box (547x630). When tiled we drop
  // a dark-green rounded square underneath.
  return (
    <svg viewBox={tile ? '0 0 64 64' : '0 0 547 630'} xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
      <defs>
        <linearGradient id={gradId} x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%"   stopColor="#8fd66a" />
          <stop offset="100%" stopColor="#6ec546" />
        </linearGradient>
      </defs>
      {tile ? (
        <>
          <rect width="64" height="64" rx="14" fill="#0e1a08" />
          {/* Centre the pin inside the tile with a bit of padding. The pin's
              natural width:height ratio is 547:630 ≈ 0.87. We use a 48px
              inner box for the pin so it sits comfortably inside the 64px
              tile, then translate to centre it. */}
          <g transform="translate(8 8) scale(0.0878)">
            <path d={PIN_PATH} fill={`url(#${gradId})`} fillRule="evenodd" />
          </g>
        </>
      ) : (
        <path d={PIN_PATH} fill={`url(#${gradId})`} fillRule="evenodd" />
      )}
    </svg>
  );
}
