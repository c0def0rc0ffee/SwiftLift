// Minimal service worker: cache the app shell on install, serve from cache
// when offline, and update in the background. Versioned so a new build
// wipes the old cache cleanly.

// __SW_VERSION__ is replaced by scripts/postbuild.mjs after every Vite build,
// so each release gets a unique cache name and the old one is wiped.
const CACHE = 'swiftlift-__SW_VERSION__';
// /favicon-32.png matches the real filename in web/public/. Earlier this
// listed /favicon.png which didn't exist, cache.addAll() is atomic, so a
// single 404 failed the whole shell precache and offline navigation got
// no fallback at all.
const SHELL = ['/', '/index.html', '/manifest.webmanifest', '/favicon-32.png'];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((cache) => cache.addAll(SHELL).catch(() => null))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);

  // Never cache API responses, always go to network.
  if (url.pathname.startsWith('/api/')) return;

  // For navigations, use network-first, fall back to cached index for offline.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(() => caches.match('/index.html'))
    );
    return;
  }

  // For built assets (hashed), cache-first.
  if (url.pathname.startsWith('/assets/')) {
    event.respondWith(
      caches.match(req).then((cached) =>
        cached || fetch(req).then((res) => {
          // Only cache real successes. A mid-deploy 404, or an HTML
          // error page served with status 200, would otherwise be
          // pinned for the lifetime of this cache version and served
          // cache-first forever, leaving the app permanently broken
          // for that user until the next version bump.
          if (res.ok && res.status === 200 && res.type === 'basic') {
            const copy = res.clone();
            caches.open(CACHE).then((c) => c.put(req, copy));
          }
          return res;
        })
      )
    );
    return;
  }
});
