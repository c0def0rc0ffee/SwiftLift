# SwiftLift

A community lift sharing noticeboard for the Bailiwick of Guernsey. If you
regularly make the same journey each week (the school run, the commute into
St Peter Port, a weekly trip across the island), you can drop your start and
end on the map and meet other islanders heading the same way at similar
times. No money changes hands. SwiftLift simply puts neighbours in touch.

Current version: see `src/Version.php` (and the live value in
`app_meta`, which `update.php` writes).

## Stack

- **Frontend**: React 18 + TypeScript + Vite + Leaflet (`web/`)
- **Backend**: PHP 8.3 JSON API, single front controller + per-feature routes (`api/` + `src/`)
- **Database**: MariaDB 10.2+ with spatial types (`POINT` pins, `ST_Distance_Sphere` for match radius)
- **Hosting**: IONOS shared hosting, deployed by FTP from a pre-built bundle. No CI.

## Repository layout

```
api/                  PHP JSON endpoints (front controller + per-feature routes/)
src/                  PHP domain classes (Auth, Db, Http, *Repo, Mailer, OAuth/...)
config/               OAuth secrets (oauth.php, gitignored). Shipped WITH its
                      .htaccess deny rule, see DEPLOY.md
web/                  React source (Vite). Never uploaded to production.
  src/                Components, views, lib helpers, the api client
  dist/               Build output. Produced by `npm run build`.
admin.php             Operator dashboard for users, messages, reports, issues
setup.php             One-click DB bootstrap. Delete after first run.
indexSoon.html        Coming-soon page. Not deployed by the recipe, a manual
                      lever: FTP it up as index.html to park the site
maintenance.html      Standalone maintenance page, same manual-lever status.
                      The live maintenance path is MAINTENANCE_MODE=1 in .env,
                      which makes the API return 503 and the SPA render
                      MaintenancePage.tsx, this file is the break-glass copy
                      for when PHP itself is down
update.php            Bumps the live version + adds a changelog entry
.htaccess             SPA fallback, ?page=foo rewrite, security rules
.env.example          Template. Copy to .env on the server with real values.
DEPLOY.md             Canonical FTP deploy recipe for IONOS
Swiftlift Dist/       Pre-built deploy folder. Upload its CONTENTS via FTP.
```

## Quick start (local development)

```bash
# 1. Database. Run the setup page in the browser: it is the authoritative
#    schema (an idempotent inline definition, plus column migrations):
#    http://swiftlift.local/setup.php?token=<your SETUP_TOKEN>

# 2. Backend. Apache with mod_php is recommended (matches production).
#    PHP's built-in server also works:
php -S localhost:8000 -t . api/index.php

# 3. Frontend
cd web
npm install
npm run dev            # http://localhost:5173 (Vite proxies /api to the PHP backend)
```

Copy `.env.example` to `.env` at the project root and fill in DB credentials,
mail settings, and OAuth client IDs if you want social login.

## Build and deploy

The deploy folder is **pre-built** at `Swiftlift Dist/`; upload its
**contents** to the IONOS webroot.

**[DEPLOY.md](DEPLOY.md) is the only build recipe.** It is deliberately not
duplicated here: this README once carried its own copy, the two drifted, and
the stale one omitted `config/` (which carries the `.htaccess` that keeps the
OAuth secrets unreadable) and `update.php`. One source of truth avoids a
repeat.

Files never uploaded: `web/`, `database/`, `node_modules/`, `.git/`,
`README.md`, `DEPLOY.md`.

Files never uploaded: `web/`, `database/`, `node_modules/`, `.git/`,
`README.md`, `DEPLOY.md`.

## Features

- Map-based journey entry with start and end pins, drop on map or address search
- Day-of-week bitmask plus a start time, with a configurable time window for matches
- Spatial matching by start and end radius using `ST_Distance_Sphere`
- Optional per-journey age range and sex preference filters
- Driver and passenger directions, with seat capacity counted from accepted lift requests
- Lift request flow: request, accept, decline, cancel, with daily rate limits
- In-app messaging on accepted lifts, never an email exchange
- Shared journey groups (rotating-driver carpools)
- User blocking and reporting
- Away mode that hides the user from matches while keeping their journeys saved
- Email verification ladder (banner, forced gate, auto-disable)
- Google and Facebook OAuth (optional)
- Password reset by email, soft-delete tombstoning that preserves message threads
- Operator dashboard at `admin.php` (users, messages, issues, reports)
- One-click DB setup and migration at `setup.php`
- Maintenance mode toggle via env var, with a friendly themed page

## Matching, in plain English

For each of your journeys, SwiftLift looks for OTHER users' journeys where:

- the days bitmask overlaps yours (`AND != 0`)
- their start time is within your time window
- their start pin is within your radius of yours AND their end pin is within your radius of yours
- they are not the same user and not blocked either way
- they are active (not deleted, banned, disabled, or in Away mode)

Distances are computed server-side with MariaDB's `ST_Distance_Sphere`.
Surfaced to the UI as approximate distances ("start 140 m away"). The exact
pin coordinates of other users are never sent to the client.

## Privacy basics

- Email addresses are never shown to other users
- Map pin coordinates of other users are never sent to the client; only approximate distances are
- Recommended: drop your pin on a nearby junction or landmark rather than on your home
- Messages are visible only to the two people in the conversation
- See the in-app Privacy page for the full statement

## Code style

Source files carry C# style XML doc comments (`<summary>`, `<param>`,
`<returns>`, `<remarks>`, `<exception cref="">`, `<see cref="">`) inside the
language's native block comment markers. UK spellings throughout prose.

## Contact

Bug reports, suggestions, account recovery, general enquiries:
[hello@swiftlift.gg](mailto:hello@swiftlift.gg)

## Licence

GNU General Public License v3.0. See [LICENSE](LICENSE) for the full text.
