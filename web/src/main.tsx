import React from 'react';
import ReactDOM from 'react-dom/client';
import 'leaflet/dist/leaflet.css';
import './styles.css';
import { App } from './App';

/**
 * <summary>
 * Application entry point. Mounts the top level App component into the
 * #root DOM node under React.StrictMode and, in production, registers
 * the service worker that backs the PWA install and offline shell.
 * </summary>
 * <remarks>
 * Leaflet's stylesheet and the project's global styles are imported
 * here so they apply to the whole tree before React commits its first
 * render. The service worker registration is gated on `import.meta.env.PROD`
 * because the Vite dev server runs its own client and a stale `sw.js`
 * would interfere with hot module replacement. Registration failures
 * are logged through `console.warn` rather than thrown so a broken
 * service worker never blocks the SPA from booting.
 * </remarks>
 */
ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>,
);

// Register the service worker for PWA install / offline shell.
// Skipped on the dev server because Vite has its own client.
if ('serviceWorker' in navigator && import.meta.env.PROD) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(err => {
      console.warn('SW registration failed:', err);
    });
  });
}
