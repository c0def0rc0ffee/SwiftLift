// Bump the service-worker cache name to a unique value per build, so users
// that already have an older SW installed get a fresh cache when they next
// load the site.

import { readFileSync, writeFileSync, readdirSync } from 'node:fs';
import { resolve } from 'node:path';

const distDir = resolve('dist');
const swPath  = resolve(distDir, 'sw.js');

const assets = readdirSync(resolve(distDir, 'assets'));
const jsAsset = assets.find(f => f.endsWith('.js')) ?? '';
// e.g. "index-CgeN9lUM.js" → "CgeN9lUM"
const hashMatch = jsAsset.match(/-([A-Za-z0-9_-]+)\.js$/);
const version = hashMatch ? hashMatch[1] : String(Date.now());

let sw = readFileSync(swPath, 'utf8');
sw = sw.replace(/__SW_VERSION__/g, version);
writeFileSync(swPath, sw);

console.log(`[postbuild] Service worker cache versioned as swiftlift-${version}`);
