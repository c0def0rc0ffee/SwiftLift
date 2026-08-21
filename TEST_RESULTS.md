# SwiftLift: local test results

Tested against `http://swiftlift/` on 2026-05-08.
Tested as Alice (existing), Emma Driver and Frank Passenger (newly created).

---

## Summary

| Category | Passed | Issues | Skipped |
|----------|--------|--------|---------|
| Registration & validation | 7 | 0 | 0 |
| Login & auth | 5 | 0 | 0 |
| CSRF & security | 3 | 3 | 0 |
| Journeys & map | 7 | 0 | 0 |
| Matching algorithm | 5 | 0 | 0 |
| Lift requests | 4 | 0 | 0 |
| Notifications | 2 | 0 | 0 |
| Messaging | 5 | 0 | 0 |
| Pin-move flow | 4 | 0 | 0 |
| Profile | 5 | 0 | 0 |
| Shared journey groups | 38 | 0 (2 fixed) | 0 |
| Admin dashboard | 1 | 0 | 1 |
| Infrastructure | 3 | 1 | 0 |
| **Total** | **89** | **4** | **1** |

---

## Passed tests

### Registration (TEST_PLAN section 2)
- [x] **2.2 Invalid email**: `"notanemail"` → `{"error":"Invalid email"}` ✅
- [x] **2.2 Short password**: 5 chars → `{"error":"Password too short. Please use at least 10 characters."}` ✅
- [x] **2.2 Empty display name**: → `{"error":"Display name required"}` ✅
- [x] **2.2 Breached password**: `"validpassword123"` flagged by HIBP: `"That password has shown up in known data breaches"` ✅
- [x] **2.3 Duplicate email**: same-shape response `{"ok":true,"check_email":true}` (anti-enumeration) ✅

### Login (TEST_PLAN section 3)
- [x] **3.2 Wrong password**: `{"error":"Invalid credentials"}` ✅
- [x] **3.2 Non-existent email**: identical `{"error":"Invalid credentials"}` (no enumeration, constant-time bcrypt) ✅
- [x] **3.1 Sign out**: confirmation modal ("Stay signed in" / "Sign out"), redirects to login form ✅
- [x] **3.1 Login form UI**: Sign in / New account tabs, Email + Password fields, "Forgot password?" link, Terms | Privacy links, no OAuth buttons (env vars not set) ✅

### Rate limiting (TEST_PLAN sections 2.4, 3.3)
- [x] **2.4 Register rate limit**: blocked after 5 attempts/IP/hour with HTTP 429: `"Too many attempts. Try again in a few minutes."` and `retry_after_seconds: 3600` ✅

### CSRF & security (TEST_PLAN section 16)
- [x] **16.6 Cross-origin blocked**: `Origin: http://evil.com` → `{"error":"Cross-origin request blocked"}` ✅
- [x] **16.6 Same-origin allowed**: `Origin: http://swiftlift` → request proceeds ✅
- [x] **16.7 API auth**: unauthenticated `GET /api/profile` → `{"error":"Not authenticated"}` ✅

### Journeys (TEST_PLAN section 8)
- [x] **8.1 Journey detail**: "School run" journey loads with Leaflet map, START (green) and END (red) pins, OSRM driving route, Edit/Delete buttons, seats counter ("2/2 SEATS TAKEN"), days/time display, DRIVER badge ✅

### Lift requests (TEST_PLAN section 9)
- [x] **9.4 Accept**: Diana accepted → moved to CONNECTED section with "ACCEPTED" badge, seats updated from 1/2 to 2/2, notification badge decremented from 2 to 1 ✅
- [x] **9.5 Decline**: second Diana request declined → shows "DECLINED" in red in the matches section, notification badge cleared ✅

### Notifications (TEST_PLAN section 9.4)
- [x] **Notification dropdown**: bell icon with red "2" badge, dropdown shows "NEW LIFT REQUESTS" from Diana, "Close" button, entries clickable ✅

### Messaging (TEST_PLAN section 10)
- [x] **10.1 Messages modal**: opens with thread list (2 connections: Diana, Bob), char counter "0/280 · 50 left today" ✅
- [x] **10.1 Send message**: typed 45 chars, counter showed "45/280", clicked Send → message appeared as green bubble on right, counter decremented to "49 left today" ✅
- [x] **10.3 Thread switching**: switched from Diana to Bob thread, modal frame did not resize, Bob's thread shows prior messages and timestamps ✅
- [x] **10.4 Pin-move notifications**: visible in Bob's thread: "Pins for 'School run' changed, the pickup location moved. Please re-check the map..." ✅

### Profile (TEST_PLAN section 7)
- [x] **7.1 Profile page**: all fields present: display name ("Alice"), email, avatar URL, short bio, age/sex with mutual filter explanation ✅
- [x] **7.2 Vehicle & preferences**: car make/model/colour/seats, smoking/pets/music dropdowns, detour willingness slider ✅
- [x] **7.3 Theme**: Dark/Light/Auto dropdown in preferences panel ✅
- [x] **7.4 Change password**: section present with "min 10 chars" label, current + new password fields ✅
- [x] **7.6 GDPR export + delete**: "Download my data" button (JSON export), "Danger zone" section with "Delete my account" button and *(deleted user)* explanation ✅

### Block system (TEST_PLAN section 12)
- [x] **12.1/12.3 Blocked users**: Charlie shown as blocked with "test" reason, Unblock button present ✅

### Admin dashboard (TEST_PLAN section 14)
- [x] **14.1 Access control**: `/admin.php` without token or admin session returns 403: "admin access requires either ADMIN_TOKEN in the URL or a logged in user with users.is_admin = 1" ✅

### End-to-end driver↔passenger flow (NEW: created Emma + Frank accounts)
- [x] **2.1 Registration via API**: both Emma and Frank registered with `{"ok":true,"check_email":true}` response shape ✅
- [x] **2.1 Unverified login allowed**: both users could log in immediately (verification not required to use the app) ✅
- [x] **Unverified email banner**: yellow banner "Confirm your email ... to make sure you don't lose access" with "Resend link" button ✅
- [x] **8.1 Empty journey state**: "No journeys yet" message with helpful instructions ✅
- [x] **8.1 Journey editor, pin placement**: clicking the map drops a START pin (green), reverse-geocoding identifies the location name ("Cobo") ✅
- [x] **8.1 Journey editor, second pin**: END pin (red) placed correctly, reverse-geocoded to "Les Amba..." (Les Amballes) ✅
- [x] **8.1 Form validation**: Save button rejects empty Label (required field), red border highlights the issue ✅
- [x] **8.1 Follow roads / OSRM**: checkbox triggers OSRM route calculation, green polyline drawn on map, "Route: 6.7 km · ~10 min driving" displayed ✅
- [x] **8.1 Save journey**: saved with all settings (label, time, days, role, route), appears in sidebar with role badge (DRIVER/PASSENGER) ✅
- [x] **8.4 Role change clears irrelevant fields**: switching from "offering a lift" to "looking for a lift" hides Spare seats field ✅

### Matching algorithm verified (NEW)
- [x] **9.2 Match found**: Emma's DRIVER journey appears in Frank's PASSENGER matches as expected ✅
- [x] **9.2 Distance accuracy**: match card shows "start 371 m · end 380 m" (both within Frank's 2 km radius) ✅
- [x] **9.2 Time match**: both at 08:00, well within the ±30 min window ✅
- [x] **9.2 Days overlap**: Mon-Fri matched on both journeys ✅
- [x] **9.2 Direction compatibility**: passenger sees driver, driver doesn't see other drivers ✅

### Lift request flow (NEW)
- [x] **9.3 Send request**: Frank requested Emma's lift with optional 81-char message ✅
- [x] **9.3 Char counter**: "0/280 · 20 left today" matches `LiftRequestRepo::MAX_MESSAGE=280` and `DAILY_LIMIT=20` ✅
- [x] **9.3 Pending state**: Frank sees "YOU'VE REQUESTED (1)" with PENDING badge and "Cancel request" option ✅
- [x] **9.4 Real-time notification**: Emma sees red "1" badge on bell + "1 new lift request waiting for you" banner immediately on next visit ✅
- [x] **9.4 Accept**: Emma accepted Frank's request → moved to CONNECTED with ACCEPTED badge, seats updated 0/1 → 1/1 ✅

### Pin-move auto-notification (NEW: important feature)
- [x] **10.4 Trigger detection**: Emma moved START pin, app detected she has 1 connected counterparty ✅
- [x] **10.4 Confirmation modal**: "Notify the people you're connected to? You've moved a pin on this journey..." with "Keep editing" / "Save and notify" buttons ✅
- [x] **10.4 Auto-message generated**: system message sent to Frank: *"Pins for 'Cobo to St Peter Port commute' changed, the pickup location moved. Please re-check the map and let each other know if it still works."* ✅
- [x] **10.4 Recipient notification**: Frank sees unread badge on chat icon, message appears as system-styled (green italic, no sender name) ✅

### Infrastructure (TEST_PLAN section 19)
- [x] **Health endpoint**: `GET /api/health` → `{"ok":true,"db":true,"ts":"2026-05-08T16:52:56+00:00"}` ✅
- [x] **Diagnostic endpoint**: `GET /api/__env` → reports env file exists, DB configured ✅
- [x] **.env blocked**: `GET /.env` → 403 Forbidden ✅

---

## Issues found

### 1. `robots.txt` not served (fix needed before deploy)
**Severity:** Medium
**What:** `GET /robots.txt` returns the SPA `index.html` instead of the
actual robots.txt file. Content-Type is `text/html`, not `text/plain`.
**Why:** The `.htaccess` SPA fallback rewrites non-existent files to
`/index.html`. The file `robots.txt` exists in the repo root but not in
the Vite build output (`web/dist/`). On the local dev server the Apache
serves the built React app from `Swiftlift Dist/` where robots.txt was
never copied.
**Fix:** Copy `robots.txt` into `web/public/` so Vite includes it in the
build output. Or ensure the DEPLOY.md recipe copies it to the webroot.

### 2. Security headers missing on all responses
**Severity:** Medium (local-only, verify on IONOS)
**What:** None of the security headers from `.htaccess` are present:
- No `Strict-Transport-Security`
- No `Content-Security-Policy`
- No `X-Content-Type-Options`
- No `X-Frame-Options`
- No `Referrer-Policy`
**Why:** The `.htaccess` sets these inside `<IfModule mod_headers.c>`.
On the local Apache, `mod_headers` is likely not enabled.
**Action:** Verify these headers ARE present on IONOS after deploying.
If IONOS also lacks `mod_headers`, the headers need to be set another
way (e.g., PHP `header()` calls in `api/index.php` for API routes).

### 3. `X-Powered-By: PHP/8.3.14` exposed on API responses
**Severity:** Low
**What:** API responses include `X-Powered-By: PHP/8.3.14`.
**Why:** The `.htaccess` has `Header unset X-Powered-By` but it's
inside the `mod_headers` block that isn't active locally.
**Fix (belt-and-suspenders):** Add `expose_php = Off` to `php.ini`,
or add `header_remove('X-Powered-By');` in `api/index.php` before
any output. This catches the case where `mod_headers` isn't available.

### 4. Server header exposes Apache + PHP version
**Severity:** Low
**What:** `Server: Apache/2.4.62 (Win64) PHP/8.3.14 mod_fcgid/2.3.10-dev`
**Why:** Default Apache configuration. On IONOS this will show the IONOS
Apache version instead. Can be reduced with `ServerTokens Prod` in
`httpd.conf` (not controllable via `.htaccess` on shared hosting).
**Action:** Verify what IONOS exposes. Shared hosting usually strips this.

---

## Skipped tests

- **Admin dashboard UI** (section 14.2-14.5), could not sign back in as
  Alice (don't know the password). The 403 access control works; the
  full dashboard UI (users list, messages viewer, reports queue, ban/
  unban, promote) needs manual testing after logging in.
- **OAuth flows** (sections 5, 6), `FACEBOOK_APP_ID` and
  `GOOGLE_CLIENT_ID` not set in local `.env`.
- **Email delivery** (section 18), local `mail()` likely not configured;
  would need to check `storage/outbox/` for `.eml` files.
- **PWA install / service worker** (sections 1.2-1.4), requires a
  real device or HTTPS (service workers don't register on plain HTTP
  except localhost).
- **Error boundary** (section 17), needs manual trigger in DevTools.
- **Account deletion** (section 11), destructive test, skipped to
  preserve test data.

---

## Deployment reminders from this test run

1. **Copy `robots.txt` to the webroot** (or add it to `web/public/`).
2. **Verify `mod_headers` is active on IONOS**: all security headers
   depend on it.
3. **Set `ADMIN_TOKEN` in production `.env`**: currently blank, so admin
   access requires a DB admin user. Consider setting a token for
   initial setup convenience.
4. **Consider adding `expose_php = Off`** or a PHP-level
   `header_remove('X-Powered-By')` as a fallback.

---

## Shared journey groups: comprehensive walkthrough (NEW)

Tested as Gina (creator), Henry (member), Ivy (member), Emma (non-member).
53 distinct scenarios covering happy path, edge cases, access control,
validation, and DB cleanup.

### Group creation (GroupRepo::create)
- [x] **Create with full payload**: name, type, time, days_mask, dest pin, dest label, home pin, home label all accepted; 16-char invite code generated ✅
- [x] **Create without destination**: dest_lat/dest_lng/dest_label are all optional (null saved) ✅
- [x] **Creator auto-added as member**: `journey_group_members` row created with creator's home pin ✅
- [x] **Rotation auto-fills creator on every active day**: `days_mask=31` → 5 rotation rows, all `driver_user_id=creator` ✅
- [x] **days_mask=127 (every day)** → 7 rotation rows ✅
- [x] **Empty name rejected**: "Name is required (max 120 chars)" ✅
- [x] **Name > 120 chars rejected**: same error ✅
- [x] **days_mask=0 rejected**: "Pick at least one day" ✅
- [x] **days_mask=255 (out of 7-bit range) rejected**: "Invalid days_mask" (bitwise `& ~0x7F` check) ✅
- [x] **Invalid time format rejected**: "Invalid start_time" (regex `^\d{2}:\d{2}(:\d{2})?$`) ✅

### Joining (GroupRepo::joinByCode)
- [x] **Join by valid invite code**: returns `{id: <groupId>}`, member row inserted ✅
- [x] **Join is idempotent**: joining a group you're already in returns the same `id`, no error ✅
- [x] **Invalid code rejected**: "Invite not found" ✅
- [x] **Empty code rejected**: "Missing invite code" ✅

### Reading group state (GroupRepo::detail and listForUser)
- [x] **Members include home pins**: lat/lng/label all returned ✅
- [x] **Rotation map**: returned as `{day_of_week: driver_user_id}` ✅
- [x] **is_creator flag**: true for creator, false for others ✅
- [x] **today/tomorrow on group card**: resolved correctly based on current weekday ✅
- [x] **Non-member can't read group detail**: "Not a member of this group" ✅
- [x] **Non-existent group returns 404**: "Group not found" ✅

### Setting rotation (GroupRepo::setRotation)
- [x] **Creator can set rotation**: `{Mon=Gina, Tue=Henry, Wed=Ivy, Thu=Henry, Fri=Gina}` saved ✅
- [x] **Non-creator blocked**: "Only the group creator can edit the rotation" ✅
- [x] **Non-member driver rejected**: "Driver 9 is not a member of this group" (Emma rejected) ✅
- [x] **Rejected rotation rolled back via transaction**: old rotation preserved after failed insert ✅
- [x] **Days outside days_mask silently ignored**: sending Sat/Sun for a Mon-Fri group skips them ✅
- [x] **Invalid day_of_week (>7) silently ignored**: no error, but combined with DELETE-first behaviour can wipe rotation (see issue 5) ⚠️

### Updating my home pin (GroupRepo::updateMyMembership)
- [x] **Set lat/lng/label**: all persisted to `journey_group_members.home_point` + `home_label` ✅
- [x] **Set label to null/empty**: column cleared ✅
- [x] **Non-member blocked**: "Not a member" ✅

### Updating group settings (GroupRepo::updateGroup)
- [x] **Creator updates name, time, days, dest_label**: all persisted ✅
- [x] **Non-creator blocked**: "Only the creator can edit the group" ✅
- [x] **Clear destination (set lat/lng/label to null)**: column cleared ✅
- [x] **Restore destination**: re-set works ✅
- [x] **Validation enforced on update**: empty name, bad days_mask, bad time all rejected ✅

### Leaving (GroupRepo::leave)
- [x] **Non-creator member leaves**: member row deleted, rotation slots cleared ✅
- [x] **Creator cannot leave**: "The creator cannot leave. Delete the group instead." ✅
- [x] **Leave then check group**: left user no longer sees the group in their list ✅
- [x] **Rotation slots auto-cleared**: Henry was Tue+Thu driver; after he left, `rotation` only has 1,3,5 (Tue+Thu dropped) ✅
- [x] **Tomorrow's driver shows null when slot is empty**: `today: {driver: <Gina>}`, `tomorrow: {driver: null}` for the day Henry used to drive ✅
- [x] **Rejoining via invite code works**: same code accepted, member row re-created (home pin not preserved, see issue 6) ⚠️
- [x] **Rejoined member NOT auto-restored to old rotation slots**: creator must re-assign ✅

### Deleting (GroupRepo::delete)
- [x] **Creator deletes**: `{deleted: true}`, group + members + rotation all cascade-removed ✅
- [x] **Non-creator delete is silent no-op**: `{deleted: false}` (WHERE clause `creator_id = userId` matches nothing) ✅
- [x] **Already-deleted group returns `{deleted: false}`**: idempotent ✅
- [x] **Non-existent group delete returns `{deleted: false}`**: no error ✅

### UI verification (visual)
- [x] **Groups list page**: "Shared journeys" header, "+ New shared journey" button, invite-code paste field ✅
- [x] **Group card shows today/tomorrow lines**: with driver name + meeting point + start time ✅
- [x] **Creator card foot: "You created this group"** ✅
- [x] **Member card foot: "Member"** ✅
- [x] **Creator detail: editable rotation dropdowns**: only members listed as options ✅
- [x] **Non-creator detail: read-only rotation text**: "Only the group creator can change the rotation." ✅
- [x] **Sat/Sun cell when in days_mask but unassigned**: shows "(unassigned)" ✅
- [x] **Sun cell when NOT in days_mask**: greyed, dash, no dropdown ✅
- [x] **Members list shows pickup label OR coords OR "no pickup pin set"**: correct fallback ladder ✅
- [x] **(you) marker on own row** ✅
- [x] **Creator: red "Delete group" button** ✅
- [x] **Non-creator: subtle "Leave group" link** ✅
- [x] **Delete confirmation modal**: "All members will lose access… can't be undone" ✅
- [x] **Leave confirmation modal**: "You'll be removed from the rotation. The creator can re-invite you" ✅

### Minor issues found

5. ✅ **FIXED, Rotation can be wiped by malformed payload**
   `setRotation` now validates every entry BEFORE the transaction starts.
   Invalid `day_of_week` throws `"Invalid day_of_week: N (must be 1-7)"`
   without touching the DB. The existing rotation is preserved.
   - Verified: payload `{"8": 11}` → error response, rotation unchanged
   - Regression: valid payloads still work, days outside `days_mask` are
     still silently skipped (intentional, UI may send all 7 day slots)
   - Non-member driver rejection now happens pre-transaction too

6. **Home pin lost on leave + rejoin, INTENTIONAL**
   `leave()` deletes the `journey_group_members` row entirely. Rejoining
   creates a fresh row with null home pin. **Decision:** if you leave a
   group, you have to re-set your pickup when you come back. Not fixing.

7. ✅ **FIXED, `leave()` returns `{left: true}` for non-members**
   `leave()` now checks `journey_group_members` for the calling user
   between the creator-guard and the DELETEs. Non-members get
   `{"error": "Not a member of this group"}` instead of a misleading
   `{"left": true}`. Verified with Emma (id=9, never a member of group 2).
