/**
 * Landing page progressive enhancements.
 *
 * Lives in its own file rather than an inline <script> because the site CSP
 * sets `script-src 'self'` with no 'unsafe-inline', an inline block here is
 * parsed and then silently refused, so both fallbacks below never ran.
 *
 * Everything here is a belt-and-braces backup for the Apache rewrite rules
 * in .htaccess; the page is fully functional without it.
 */
// If the visitor already has a session cookie they almost certainly want
// the app, not the marketing page. Route them straight in. Crawlers and
// signed out humans never run this branch and so see the static copy
// above. The Apache layer also routes by cookie when it can; this is the
// belt for the braces, in case the cookie name changes or Apache is in a
// mood.
try {
  if (document.cookie.indexOf('swiftlift_sid=') !== -1) {
    location.replace('/?app=1' + location.hash);
  }
} catch (_) { /* ignore */ }

// If a group invite arrived via /?join=CODE and the .htaccess bypass
// didn't catch it for any reason, propagate the code through the CTA
// links so it survives the click into the SPA. Belt for the braces.
try {
  var sp = new URLSearchParams(location.search);
  var join = sp.get('join');
  if (join) {
    var links = document.querySelectorAll('a[href^="/?app=1"]');
    for (var i = 0; i < links.length; i++) {
      var a = links[i];
      var u = new URL(a.getAttribute('href'), location.origin);
      u.searchParams.set('join', join);
      a.setAttribute('href', u.pathname + u.search + u.hash);
    }
  }
} catch (_) { /* ignore */ }
  
