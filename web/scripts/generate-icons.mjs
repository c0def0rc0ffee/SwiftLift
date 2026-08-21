// Rasterise web/public/icon.svg into the PNG variants the browser/PWA needs.
//
// Run manually whenever icon.svg changes:
//   node scripts/generate-icons.mjs
//
// Outputs (overwrites existing files in web/public/):
//   favicon-32.png         32x32   transparent
//   apple-touch-icon.png   180x180 dark-green rounded square (iOS doesn't honour PNG transparency on the home screen)
//   icon-192.png           192x192 dark-green rounded square (PWA, "any maskable")
//   icon-512.png           512x512 dark-green rounded square (PWA splash + launcher)
//
// The PWA / Apple icons need a solid background, launchers often render PNG
// transparency as black or whatever the OS theme picks, which clashes with
// the brand. The favicon stays transparent so it sits cleanly in light or
// dark browser chrome.

import sharp from 'sharp';
import { readFileSync, writeFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const publicDir = resolve(__dirname, '..', 'public');
const svgBuffer = readFileSync(resolve(publicDir, 'icon.svg'));

const BG       = '#0e1a08';            // accent-ink, matches the site
const RADIUS   = 0.227;                // ~iOS-style 22.7% corner radius
const SAFE     = 0.84;                 // pin fills 84% of the inside (some breathing room)

async function transparent(size, { fill = 1 } = {}) {
  // Rasterise the SVG into a square, preserving aspect ratio. `fill` lets
  // callers override the safe-area: 1 = pin touches the canvas edges (good
  // for the favicon, where the tab area is already small), <1 = leaves
  // breathing room around the shape.
  // SVG viewBox is 547x630 (taller than wide), sharp's fit:'contain'
  // automatically pads the shorter side with transparency.
  const innerSize = Math.round(size * fill);
  const inner = await sharp(svgBuffer, { density: 600 })
    .resize({ width: innerSize, height: innerSize, fit: 'contain', background: { r: 0, g: 0, b: 0, alpha: 0 } })
    .png()
    .toBuffer();
  return sharp({
    create: {
      width: size, height: size, channels: 4,
      background: { r: 0, g: 0, b: 0, alpha: 0 },
    },
  }).composite([{ input: inner, gravity: 'center' }]).png().toBuffer();
}

async function rounded(size) {
  // Inner pin at 84% of the canvas, on a dark-green rounded square.
  const r = Math.round(size * RADIUS);
  const mask = Buffer.from(
    `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}">
       <rect width="${size}" height="${size}" rx="${r}" ry="${r}" fill="white"/>
     </svg>`
  );

  const inner = await sharp(svgBuffer, { density: 600 })
    .resize({ width: Math.round(size * SAFE), height: Math.round(size * SAFE), fit: 'contain', background: { r: 0, g: 0, b: 0, alpha: 0 } })
    .png()
    .toBuffer();

  const square = await sharp({
    create: {
      width: size, height: size, channels: 4,
      background: BG,
    },
  })
    .composite([
      { input: inner, gravity: 'center' },
      { input: mask, blend: 'dest-in' },
    ])
    .png()
    .toBuffer();

  return square;
}

async function main() {
  // Favicon notes:
  //   - We render at 64×64 not 32×32 so retina/2x screens still get a sharp
  //     pin in the tab area (browsers downscale a 64px source cleanly to a
  //     ~16-20px tab bar slot). Filename keeps the "-32" suffix because the
  //     <link sizes="32x32"> attribute in index.html stays the same, the
  //     `sizes` is a hint to the browser, not a hard contract.
  //   - fill: 1 means no safe-area padding; the pin uses the full canvas so
  //     it doesn't look postage-stamp small in the browser tab.
  const tasks = [
    { name: 'favicon-32.png',       size: 64,  fn: (s) => transparent(s, { fill: 1 }) },
    { name: 'apple-touch-icon.png', size: 180, fn: rounded },
    { name: 'icon-192.png',         size: 192, fn: rounded },
    { name: 'icon-512.png',         size: 512, fn: rounded },
  ];

  for (const { name, size, fn } of tasks) {
    const out = await fn(size);
    writeFileSync(resolve(publicDir, name), out);
    console.log(`[icons] wrote ${name} (${size}x${size})`);
  }
}

main().catch(err => { console.error(err); process.exit(1); });
