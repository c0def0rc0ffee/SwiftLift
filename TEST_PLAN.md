# SwiftLift: detailed pre-launch test plan

A walkthrough to do on real devices once the IONOS deployment is in place,
DNS is pointing at swiftlift.gg, and HTTPS is on. The goal is to catch the
class of bugs you can't catch in dev with Apache and a spoofed UA: real
keyboards, real-network errors, real Apple/Google quirks, real email
delivery.

Tick each box once you've done it. If something fails, write it down with a
screenshot, don't just retry until it works.

## Devices to use

Pick at least one from each row.

| Class      | Examples                                     |
|------------|----------------------------------------------|
| iPhone     | Safari (iOS 16+), Chrome iOS                 |
| Android    | Chrome (Android 11+), Samsung Internet       |
| Desktop    | Chrome on Windows + Safari on macOS          |
| Throttled  | DevTools "Slow 3G" preset, or your phone on a poor signal |

A free tool: BrowserStack live tier if you don't have the real devices.

---

## 1. Cold start & install

### 1.1 First load
- [ ] Open `https://swiftlift.gg` on each device for the very first time
      (clear cache / incognito).
- [ ] The landing page loads within 5 seconds on a normal connection.
- [ ] No flash of unstyled content (FOUC), the CSS loads before the page
      paints.
- [ ] The landing page shows the SEO/marketing content (not the logged-in
      app view). This is the unauthenticated path in `.htaccess`.

### 1.2 Service worker
- [ ] After the first load, open DevTools → Application → Service Workers.
      Confirm `sw.js` is registered and active.
- [ ] Turn on Airplane Mode (or DevTools offline). Reload the page.
      The cached `index.html` and hashed assets serve from cache.
- [ ] API calls fail with a friendly error message (not a blank screen or
      browser-level "no internet" page).
- [ ] Turn off Airplane Mode. The app reconnects without a manual refresh.

### 1.3 PWA install: Android
- [ ] On Android Chrome, tap the three-dot menu → "Install app" or look
      for the install banner.
- [ ] The app installs and appears on the home screen with the SwiftLift
      icon.
- [ ] Opening the icon launches the app in standalone mode (no browser
      chrome / address bar).
- [ ] After installing, check the notifications dropdown in the app, the
      install hint should have disappeared.

### 1.4 PWA install: iOS
- [ ] On iOS Safari, tap Share → "Add to Home Screen".
- [ ] The shortcut appears on the home screen with the SwiftLift icon.
- [ ] Tapping the icon opens the app full-screen with no Safari chrome
      (status bar still visible).
- [ ] The install hint in the notifications dropdown is gone after adding.

---

## 2. Registration

### 2.1 Happy path
- [ ] Tap "Sign up" / navigate to the register form.
- [ ] Enter a valid email (use a real inbox you can check, Gmail, Outlook,
      or iCloud), a display name, and a password (10+ characters).
- [ ] Submit. The API responds with `{ ok: true, check_email: true }`.
      The UI shows a "Check your email" screen, **not** an auto-login.
- [ ] Check the inbox. The verification email should arrive within a few
      minutes. Subject: "Confirm your SwiftLift email".
      If it doesn't arrive, check:
      - Spam / Junk folder
      - `storage/outbox/*.eml` on the server (fallback if `mail()` fails)
- [ ] Click the verification link in the email.
- [ ] The app opens with a green "Email confirmed, welcome!" toast.
- [ ] You are now logged in. The app shows the main ride view.

### 2.2 Validation errors
- [ ] Try registering with an invalid email (e.g. "notanemail") →
      **"Invalid email"** error.
- [ ] Try registering with an empty display name →
      **"Display name required"** error.
- [ ] Try a 9-character password →
      **"Password too short. Please use at least 10 characters."**
      (Code: `PasswordSecurity::MIN_LENGTH = 10`)
- [ ] Try a known-breached password (e.g. "password1234") →
      **"This password has appeared in a data breach"** warning from
      HIBP check (if the HIBP API is reachable; if not, it fails open).

### 2.3 Duplicate email
- [ ] Try registering again with the **same email** as 2.1.
- [ ] The API returns the **same shape** (`{ ok: true, check_email: true }`)
      indistinguishable from a fresh registration (anti-enumeration).
- [ ] The **real** account owner gets a different email: "Someone tried to
      register using this email" with a password-reset link. The new
      registrant does NOT get a verification email.

### 2.4 Rate limiting
- [ ] Register 5 accounts rapidly from the same IP (use different emails).
      All 5 should succeed.
- [ ] On the **6th attempt** within the same hour → HTTP 429.
      Message: **"Too many attempts. Try again in a few minutes."**
      (Code: `RateLimit::guard('register', 5, 3600)`, 5 per IP per hour)

---

## 3. Login

### 3.1 Happy path
- [ ] Sign out if logged in.
- [ ] Enter the email and password from section 2.1.
- [ ] Submit → logged in, main app view loads with your display name.
- [ ] Check DevTools → Application → Cookies. The session cookie should
      have:
      - Name: `swiftlift_sid` (from `SESSION_NAME` env var)
      - `HttpOnly` flag: **yes**
      - `Secure` flag: **yes**
      - `SameSite`: **Lax**
      - Lifetime: ~30 days

### 3.2 Wrong credentials
- [ ] Try logging in with the correct email but wrong password →
      **"Invalid credentials"** (HTTP 401).
- [ ] Try logging in with a non-existent email →
      **"Invalid credentials"** (same error, no email enumeration).
      Response time should be similar (constant-time bcrypt via dummy hash).

### 3.3 Rate limiting: per IP
- [ ] Fire 10 login attempts rapidly from the same IP (any email/password).
      All 10 should get responses (either success or 401).
- [ ] On the **11th attempt** within 10 minutes → HTTP 429.
      (Code: `RateLimit::guard('login', 10, 600)`, 10 per IP per 10 min)

### 3.4 Rate limiting: per account
- [ ] Fire 5 login attempts against the **same email** (wrong passwords).
      All 5 should get 401.
- [ ] On the **6th attempt** against the same email within 15 minutes →
      HTTP 429. **"Too many attempts on this account."**
      (Code: `RateLimit::guardKey('login_account', ..., 5, 900)`)

### 3.5 Banned user
- [ ] (Requires admin) Ban a test account from the admin dashboard.
- [ ] Try logging in as the banned user →
      **"This account has been suspended. Contact hello@swiftlift.gg"**
      (HTTP 403).

### 3.6 Deleted user
- [ ] (After testing account deletion in section 11) Try logging in with
      the deleted account's credentials →
      **"Invalid credentials"** (same as non-existent, no enumeration).

---

## 4. Password reset

### 4.1 Happy path
- [ ] From the login screen, tap "Forgot password".
- [ ] Enter a registered email. Submit.
- [ ] The UI shows a generic "Check your email" message (same shape
      whether the email exists or not, anti-enumeration).
- [ ] Check the inbox. Subject: "Reset your SwiftLift password".
      The link is valid for **60 minutes**.
- [ ] Click the reset link → lands on the reset form in the app.
- [ ] Enter a new password (10+ characters). Submit → success.
- [ ] Sign in with the new password, works.
- [ ] Sign in with the **old** password, fails ("Invalid credentials").

### 4.2 Invalid token
- [ ] Try submitting a password reset with a garbage token →
      error (expired/invalid token message).
- [ ] Try reusing the same valid reset token a second time →
      error (tokens are single-use via `AuthTokenRepo::consume`).

### 4.3 Rate limiting
- [ ] Submit 5 forgot-password requests from the same IP within an hour.
      All 5 should return `{ ok: true }`.
- [ ] The **6th** → HTTP 429.
      (Code: `RateLimit::guard('forgot', 5, 3600)`)

---

## 5. Facebook OAuth (skip if `FACEBOOK_APP_ID` is not set)

### 5.1 First-time sign-in
- [ ] From the login screen, the "Continue with Facebook" button is
      visible (only appears when both `FACEBOOK_APP_ID` and
      `FACEBOOK_APP_SECRET` are set in `.env`).
- [ ] Tap it → Facebook consent screen opens.
- [ ] Approve → redirected back to the app, logged in.
- [ ] A new SwiftLift account was created linked to your Facebook identity.

### 5.2 Returning sign-in
- [ ] Sign out, then tap "Continue with Facebook" again.
- [ ] No new account is created, you're logged into the same account.

### 5.3 Email collision
- [ ] If your Facebook email differs from an existing password-based
      SwiftLift account: a **new** SwiftLift account is created (not
      silently merged). Two accounts will exist with different emails.

---

## 6. Google OAuth (skip if `GOOGLE_CLIENT_ID` is not set)

### 6.1 First-time sign-in
- [ ] From the login screen, the "Continue with Google" button is visible.
- [ ] Tap it → Google consent screen opens.
- [ ] Approve → redirected back, logged in, new account created.

### 6.2 Returning sign-in
- [ ] Sign out, tap "Continue with Google" again → same account, no dupe.

---

## 7. User profile

### 7.1 View & edit
- [ ] Navigate to your profile (settings / account page).
- [ ] All fields are visible: display name, email, bio, age, sex.
- [ ] Edit display name → save → confirm it updates throughout the app
      (sidebar cards, message headers, etc.).
- [ ] Try setting display name to empty → **"Display name cannot be empty"**.
- [ ] Edit bio, age, sex → save → values persist on page refresh.

### 7.2 Vehicle & preferences
- [ ] Set vehicle info (make/model/colour/reg).
- [ ] Set lifestyle preferences: smoking, pets, music, detour willingness.
- [ ] Save → confirm values persist.
- [ ] These preferences are visible to matched users on journey cards.

### 7.3 Theme
- [ ] Toggle between Dark / Light / Auto themes.
- [ ] Each applies instantly. "Auto" follows the system setting (test by
      toggling OS dark mode).
- [ ] The theme selection persists across page refreshes and sessions.

### 7.4 Change password
- [ ] Go to profile → change password section.
- [ ] Enter current password + a new password (10+ chars). Submit → success.
- [ ] Verify the session is regenerated (security measure, old sessions
      on other devices are invalidated).
- [ ] Try changing password with wrong current password →
      **"Current password is incorrect"** (HTTP 401).

### 7.5 Change email
- [ ] Change your email to a new valid address. Save.
- [ ] Try changing email to one already used by another account →
      **"Email already in use"** (HTTP 409).

### 7.6 GDPR data export
- [ ] Go to profile → find the data export / download option.
- [ ] Tap it → a JSON file downloads: `swiftlift-export-<id>-<date>.json`.
- [ ] Open the file. It should contain: your user record (minus password
      hash), journeys, lift requests, messages, blocks, reports, and
      OAuth accounts.

---

## 8. Journeys

### 8.1 Create a journey (driver)
- [ ] Tap "+ New journey" or the create button.
- [ ] The Leaflet map loads centred on Guernsey.
- [ ] Drop a start pin by tapping the map. Drop an end pin.
- [ ] Pinch-zoom works on mobile. The map can't be panned beyond Guernsey
      (bounding box constraint).
- [ ] An OSRM driving route appears between the two pins.
- [ ] Set the time (e.g. 08:00), days (e.g. Mon-Fri), direction
      (offering / seeking), and seats (1-8).
- [ ] The collapse handle on the editor folds the sheet down to just
      Start/End buttons. Tapping the chevron expands it again.
- [ ] Save the journey.
- [ ] The sidebar shows it with the next-occurrence label (e.g. "Tomorrow
      at 08:00" or "Monday at 08:00").

### 8.2 Edit a journey
- [ ] Tap an existing journey in the sidebar → it opens in the editor.
- [ ] Move the start pin by dragging it. The route recalculates.
- [ ] Change the time or days. Save.
- [ ] The sidebar card updates to reflect the changes.

### 8.3 Delete a journey
- [ ] Open a journey with no active connections.
- [ ] Delete it. Confirm the prompt. The journey disappears from the
      sidebar and map.

### 8.4 Journey filters
- [ ] When creating/editing a journey, set age range and sex preference
      filters.
- [ ] These are seeded from profile defaults but can be overridden per
      journey.
- [ ] Filters are **mutual**: both sides must pass for a match to appear.

---

## 9. Matching & lift requests

Use **two test accounts** (one driver, one passenger). Open them in two
browser windows or two devices.

### 9.1 Create matching journeys
- [ ] **Account A (driver)**: create a journey offering a lift on the same
      days/time as Account B's journey, with start and end points within
      500m of each other (default match radius).
- [ ] **Account B (passenger)**: create a journey seeking a lift with
      overlapping days and a close time window.

### 9.2 Find matches
- [ ] On Account B, open the matches panel for the journey.
- [ ] Account A's journey should appear in the results.
- [ ] The match card shows: distance to start/end, time difference,
      driver's vehicle info and preferences.
- [ ] Matches respect:
      - Days bitmask overlap (at least one shared day)
      - Time window (default ±10 min, adjustable)
      - Start-point proximity (default 500m radius, adjustable 100m-2km)
      - End-point proximity (same)
      - Mutual age/sex filters (both sides must pass)
      - Blocked users excluded

### 9.3 Send a lift request
- [ ] From Account B's matches, tap "Request lift" on Account A's journey.
- [ ] Optionally add a message (max 280 characters, the char counter
      should count down). The daily remaining count is visible.
- [ ] Submit → returns HTTP 201 with the new request ID.
- [ ] Daily limit: **20 lift requests per day** per user.
      (`LiftRequestRepo::DAILY_LIMIT = 20`)

### 9.4 Receive & accept
- [ ] Switch to **Account A**. A red badge should appear on the bell icon
      (notification).
- [ ] Open the notifications dropdown (drops down full-width on phone).
- [ ] The lift request from Account B is listed. Tap it.
- [ ] Tap "Accept" → the status changes to accepted.
- [ ] Both accounts now show a "connected" state on their journey cards.

### 9.5 Decline & cancel
- [ ] Create another lift request from Account B.
- [ ] On Account A, "Decline" it → status changes to declined.
- [ ] Have Account B create a third request. Before Account A responds,
      Account B cancels it → status changes to cancelled.

---

## 10. Messaging

### 10.1 Send messages
- [ ] With an accepted lift request (from 9.4), open Messages.
- [ ] The conversation list shows the connection with Account B.
- [ ] Open the thread. Type a message (max 280 chars, char counter
      visible). Send.
- [ ] The message appears in the thread immediately.
- [ ] Daily limit: **50 messages per day** per user.
      (`MessageRepo::DAILY_LIMIT = 50`)

### 10.2 Receive messages
- [ ] Switch to Account B. The unread badge should appear:
      - Chat icon in the top nav
      - The journey card pill in the sidebar
      - The conversation row badge
- [ ] Open the thread. The message from Account A is visible.
- [ ] Reply. Switch back to Account A, the reply appears (via polling).

### 10.3 Threading
- [ ] If you have multiple connections, switch between threads in the
      messages modal. The modal frame shouldn't resize or jump.
- [ ] Unread counts update correctly as you open each thread.

### 10.4 Pin-move notifications
- [ ] On Account A (the driver), edit the connected journey and move a
      pickup or dropoff pin.
- [ ] Save the journey.
- [ ] Check Account B's message thread, an automated system message
      should have been sent notifying them of the pin move.
- [ ] The message is system-generated (not from the user), so it bypasses
      the daily limit and access checks.

---

## 11. Account deletion

### 11.1 Soft delete
- [ ] On Account B, go to Profile → Danger zone → Delete account.
- [ ] A confirmation prompt appears. Confirm.
- [ ] The API calls `DELETE /api/profile` → `UserRepo::delete()` runs a
      soft-delete: scrubs PII (`display_name` → "(deleted user)", email
      randomised, bio/vehicle cleared), stamps `deleted_at`, but leaves
      the user row and messages intact.
- [ ] You are logged out.

### 11.2 Counterparty experience
- [ ] Switch to Account A. Open the message thread with the now-deleted
      Account B.
- [ ] The user shows as **"(deleted user)"**: not a crash, not a blank.
- [ ] The conversation history is still readable.

### 11.3 Re-login attempt
- [ ] Try logging in with Account B's old credentials →
      **"Invalid credentials"** (treated as non-existent).

---

## 12. Block & report

### 12.1 Block a user
- [ ] From a connected journey card, tap Block & Report.
- [ ] Pick a reason from the list. Confirm.
- [ ] `POST /api/blocks` fires with `{ user_id, reason }`.
- [ ] The blocked user's journey vanishes from your matches immediately.
- [ ] The block is mutual, they can't see your journeys in their matches
      either.

### 12.2 Report a user
- [ ] The block action optionally files a report too.
- [ ] `POST /api/reports` fires with `{ user_id, reason, detail }`.
- [ ] The report appears in the admin dashboard's report queue.

### 12.3 Unblock
- [ ] Go to your blocks list. Unblock the user.
- [ ] `DELETE /api/blocks/{userId}` fires.
- [ ] Their journeys reappear in your matches.

---

## 13. Shared journey groups (school runs)

### 13.1 Create a group
- [ ] Tap "New group" or navigate to the groups section.
- [ ] Set a name, time, days, and a destination (e.g. the school).
- [ ] `POST /api/groups` → returns the new group ID.
- [ ] An invite code is generated automatically.

### 13.2 Join via invite code
- [ ] On Account A, copy the group's invite code.
- [ ] On another account, navigate to "Join group" and paste the code.
- [ ] `POST /api/groups/join` → the account joins the group.

### 13.3 Driver rotation
- [ ] In the group settings, set the Mon-Sun driver rotation map.
- [ ] `PUT /api/groups/{id}/rotation` saves the schedule.
- [ ] The group view shows "today's driver" and "tomorrow's driver"
      resolved from the rotation.

### 13.4 Edit & leave
- [ ] Edit the group name/time/days → `PATCH /api/groups/{id}`.
- [ ] Update your home pin for the group → `PATCH /api/groups/{id}/me`.
- [ ] Leave the group → `DELETE /api/groups/{id}/me`.
- [ ] Delete the group (creator only) → `DELETE /api/groups/{id}`.

---

## 14. Admin dashboard

### 14.1 Access
- [ ] **Token-gated**: If `ADMIN_TOKEN` is set in `.env`, visit
      `/admin.php?token=<ADMIN_TOKEN>` → dashboard loads without login.
- [ ] **Session-based**: If `ADMIN_TOKEN` is blank, only users with
      `users.is_admin = 1` can access. Non-admins get a 403.

### 14.2 Bootstrap first admin
- [ ] With zero admins in the database, visit
      `/admin.php?bootstrap=your@email.com`.
- [ ] This sets `is_admin = 1` on that user. Only works once (when zero
      admins exist).
- [ ] Sign in and verify the admin dashboard loads.

### 14.3 User management
- [ ] The users list is sortable (by name, email, date, etc.).
- [ ] Ban a user → `banned_at` is stamped. They can't log in (see 3.5).
- [ ] Unban a user → `banned_at` is cleared.
- [ ] Promote a user to admin → `is_admin = 1`.
- [ ] Revoke admin → `is_admin = 0`.

### 14.4 Message viewer
- [ ] Open the message viewer. Filter by user.
- [ ] Messages between the selected user and their counterparties are
      visible for moderation.

### 14.5 Report queue
- [ ] Open the reports view.
- [ ] Reports filed by users (from section 12.2) appear here with reason
      and detail.
- [ ] Dismiss a report → `resolved_at` is stamped.

---

## 15. Edge cases

### 15.1 Time zones / DST
- [ ] Create a journey set to 08:00.
- [ ] Check it on the day of the BST↔GMT transition (last Sunday of
      March / October). It should still say 08:00, not 07:00 or 09:00.
- [ ] (Note: if drift is observed, file it as a follow-up bug.)

### 15.2 Long messages
- [ ] In a message thread, type exactly 280 characters. The char counter
      should show 0 remaining and stop accepting input.
- [ ] Send → the message bubble wraps correctly, no overflow or JS error.
- [ ] Try typing 281+ characters → the input clamps at 280.

### 15.3 Empty / whitespace messages
- [ ] Try sending a message that's only spaces or empty → should be
      rejected (the backend trims and checks).

### 15.4 Large seats value
- [ ] Create a journey with seats set to 8 (the max). Confirm it saves.
- [ ] Try setting seats to 0 or 99 via the API → should clamp to 1-8.
      (Code: `max(1, min(8, (int) $in['seats']))`)

### 15.5 Radius boundaries
- [ ] Set match radius to the minimum (100m). Only very close journeys
      match.
- [ ] Set to maximum (2000m / 2km). Wider area matches.
- [ ] Try setting to 0 or 9999 via the API → should clamp to 100-2000.
      (Code: `max(100, min(2000, (int) $in['radius_m']))`)

### 15.6 Poor signal
- [ ] In DevTools, throttle to "Slow 3G".
- [ ] Send a lift request. It should either succeed (slowly) or show a
      friendly error, never an infinite spinner or blank screen.
- [ ] Send a message. Same expectation.

---

## 16. Security spot-check

### 16.1 Response headers
Open DevTools → Network panel. Load any page and check the response headers:

- [ ] `Strict-Transport-Security: max-age=31536000; includeSubDomains`
- [ ] `Content-Security-Policy: default-src 'self'; ...`
      (with script-src, style-src, img-src, connect-src, font-src)
- [ ] `X-Content-Type-Options: nosniff`
- [ ] `X-Frame-Options: DENY`
- [ ] `Referrer-Policy: strict-origin-when-cross-origin`
- [ ] `X-Powered-By` header is **absent** (removed via `.htaccess`).

### 16.2 iframe block
- [ ] On a different site, try embedding `https://swiftlift.gg` in an
      `<iframe>`. It should be blocked (X-Frame-Options: DENY).

### 16.3 Session cookie
- [ ] In DevTools → Application → Cookies, inspect the `swiftlift_sid`
      cookie.
- [ ] `HttpOnly`: yes (not accessible via JS)
- [ ] `Secure`: yes (only sent over HTTPS)
- [ ] `SameSite`: Lax

### 16.4 Setup page
- [ ] Visit `/setup.php` directly.
- [ ] If `SETUP_TOKEN` is set in `.env` → requires `?token=<value>` or
      returns 403.
- [ ] Best practice: **delete setup.php from the server** after DB setup.

### 16.5 robots.txt
- [ ] Visit `/robots.txt` → confirms it resolves.
- [ ] Check it disallows: `/api/`, `/setup.php`, and query params for
      verify/reset/oauth paths.

### 16.6 CSRF / cross-origin
- [ ] From a JS console on a **different origin**, try:
      ```js
      fetch('https://swiftlift.gg/api/index.php?p=messages', {
        credentials: 'include'
      })
      ```
- [ ] The request should be rejected by the Origin/Referer check in
      `api/index.php` (CSRF protection on all state-changing requests).

### 16.7 API auth enforcement
- [ ] In an incognito window (no session), try hitting any protected
      endpoint (e.g. `GET /api/index.php?p=profile`).
- [ ] Should return HTTP 401, not leak any user data.

---

## 17. Error boundary

### 17.1 Trigger the boundary
- [ ] In DevTools console while logged in, run:
      ```js
      setTimeout(() => { throw new Error('test boundary'); }, 100)
      ```
      then navigate around to trigger a re-render.
      *(More reliable: temporarily edit `RideApp` to throw on a button
      click, build, test, revert.)*
- [ ] The **"Something went wrong"** screen appears (from
      `ErrorBoundary.tsx`).
- [ ] Two buttons are shown: "Refresh" (reloads the page) and "Try to
      continue" (resets the error state).

### 17.2 Error logging
- [ ] The error is sent to `/api/log-error` via `navigator.sendBeacon()`
      (or `fetch` fallback).
- [ ] FTP down `storage/errorlog/errors-YYYY-MM.log` from the server.
- [ ] Your test entry is in there as a JSON line with fields:
      `ts`, `ip`, `url`, `ua`, `msg`, `stk` (stack), `cs` (component
      stack).
- [ ] The log-error endpoint is rate-limited: 40 per IP per hour.
      (Code: `RateLimit::guard('log_error', 40, 3600)`)

---

## 18. Email delivery verification

### 18.1 Check real delivery
- [ ] Send emails to at least one of each: Gmail, Outlook/Hotmail, iCloud.
- [ ] Verify they arrive in the inbox (not spam).
- [ ] If emails land in `storage/outbox/*.eml` instead of being sent:
      PHP `mail()` isn't configured on IONOS. Options:
      - Configure IONOS SMTP settings
      - Use a relay service (e.g. Mailgun, SendGrid)
      - Check IONOS hosting panel for mail settings

### 18.2 Email content
- [ ] Verification email: subject "Confirm your SwiftLift email", contains
      a link with `?verify=<token>`, expires in 24 hours.
- [ ] Password reset email: subject "Reset your SwiftLift password",
      contains a link with `?reset=<token>`, expires in 60 minutes.
- [ ] Duplicate-registration email: subject "A SwiftLift sign-up attempt
      for your email", contains a password-reset link.

---

## 19. Deployment infrastructure

### 19.1 HTTPS
- [ ] `https://swiftlift.gg` loads with a valid certificate.
- [ ] `http://swiftlift.gg` redirects to HTTPS (may be handled by IONOS
      panel, not `.htaccess`).
- [ ] The HSTS header is present (see 16.1).

### 19.2 Old site cleanup
- [ ] The old "under construction" `.htaccess` redirect to
      `/update_message/index.html` is **gone**. The new `.htaccess` from
      the repo root is in place.
- [ ] The `update_message/` folder has been removed from the server.

### 19.3 Backups
- [ ] IONOS control panel → enable daily MariaDB export / automatic
      backups.
- [ ] Download the latest DB dump. Restore it into a local MariaDB
      instance to confirm it actually works. (A backup you've never
      restored isn't a backup.)

### 19.4 Monitoring
- [ ] Set up UptimeRobot (free tier):
      - HTTPS check on `https://swiftlift.gg/` every 5 minutes.
      - HTTPS check on `https://swiftlift.gg/api/index.php?p=health`
        every 15 minutes (the health endpoint pings the database).
- [ ] Add your email as the alert channel.
- [ ] Trigger a test alert to confirm notifications work.

---

## 20. Service worker update flow

### 20.1 Cache invalidation
- [ ] Deploy a new build (change something visible, rebuild, upload).
- [ ] On a device that has the old version cached, reload the page.
- [ ] The service worker should detect new hashed assets and update the
      cache.
- [ ] On the next page load (or after the SW activates), the new version
      should be visible without manual cache clearing.
- [ ] If users need a manual refresh to see changes after the SW update,
      that's acceptable for v1 but note it for follow-up.

---

## Quick reference: code-verified values

| What                          | Value     | Source                           |
|-------------------------------|-----------|----------------------------------|
| Password min length           | 10 chars  | `PasswordSecurity::MIN_LENGTH`   |
| Message max length            | 280 chars | `MessageRepo::MAX_BODY`          |
| Lift request message max      | 280 chars | `LiftRequestRepo::MAX_MESSAGE`   |
| Messages daily limit          | 50/day    | `MessageRepo::DAILY_LIMIT`       |
| Lift requests daily limit     | 20/day    | `LiftRequestRepo::DAILY_LIMIT`   |
| Register rate limit           | 5/IP/hr   | `guard('register', 5, 3600)`     |
| Login rate limit (IP)         | 10/IP/10m | `guard('login', 10, 600)`        |
| Login rate limit (account)    | 5/acct/15m| `guardKey('login_account',5,900)` |
| Forgot-password rate limit    | 5/IP/hr   | `guard('forgot', 5, 3600)`       |
| Reset-password rate limit     | 10/IP/10m | `guard('reset', 10, 600)`        |
| Error log rate limit          | 40/IP/hr  | `guard('log_error', 40, 3600)`   |
| Seats range                   | 1-8       | `max(1, min(8, ...))`            |
| Radius range                  | 100-2000m | `max(100, min(2000, ...))`       |
| Time window range             | 5-240 min | `max(5, min(240, ...))`          |
| Verify token expiry           | 24 hours  | `AuthTokenRepo::issue(...,1440)` |
| Reset token expiry            | 60 min    | `AuthTokenRepo::issue(..., 60)`  |
| Session cookie lifetime       | 30 days   | `Auth.php` cookie params         |
| Session cookie name           | swiftlift_sid | `SESSION_NAME` env var       |

---

When all of these are ticked, send the link to ten Guernsey friends and
ask them to break it. Ship after their reports stop being interesting.
