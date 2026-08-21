# SwiftLift: first-time deploy to IONOS

This is the **new install** procedure: blank IONOS hosting → working
SwiftLift at `https://swiftlift.gg`. End to end, allow ~1 hour, mostly
waiting for IONOS panels to load.

For updates to an already-deployed site, see "Re-deploying after a
code change" at the bottom.

---

## What you need before you start

- IONOS hosting plan with PHP 8.0+ and a MariaDB / MySQL database
- The `swiftlift.gg` domain pointed at the hosting (DNS via IONOS or
  another registrar, A record to the IONOS IP, or a CNAME they
  give you)
- HTTPS certificate enabled in the IONOS panel (free Let's Encrypt
  works fine)
- An FTP client (FileZilla, WinSCP, Cyberduck, the IONOS web FTP)
- This repo cloned locally, with Node 18+ and npm installed

---

## 1. Build the deploy bundle locally

```bash
# from the repo root
cd web
npm install            # only needed first time / when package.json changed
npm run build          # outputs web/dist/
cd ..

# Re-assemble the Swiftlift Dist/ folder from scratch
rm -rf "Swiftlift Dist"
mkdir "Swiftlift Dist"
cp -r web/dist/*    "Swiftlift Dist/"
cp -r api           "Swiftlift Dist/"
cp -r src           "Swiftlift Dist/"
cp .htaccess        "Swiftlift Dist/"
cp setup.php        "Swiftlift Dist/"
cp admin.php        "Swiftlift Dist/"
cp update.php       "Swiftlift Dist/"   # version bump + changelog helper
cp robots.txt       "Swiftlift Dist/"
cp .env.example     "Swiftlift Dist/"   # fill in on the server, save as .env
# config/ ships WITH its .htaccess: config/oauth.php holds the OAuth secrets
# and the deny rule is what keeps it unreadable.
cp -r config        "Swiftlift Dist/"
mkdir -p "Swiftlift Dist/storage/outbox"
mkdir -p "Swiftlift Dist/storage/errorlog"
```

After this, `Swiftlift Dist/` should contain roughly:

```
Swiftlift Dist/
├── index.html              ← React entry
├── landing.html            ← SEO page for unauthenticated visitors
├── assets/                 ← hashed JS+CSS bundle
├── manifest.webmanifest    ← PWA manifest
├── sw.js                   ← service worker
├── favicon-32.png  icon-192.png  icon-512.png  icon.svg
├── apple-touch-icon.png
├── og-image.png            ← 1200x630 link-preview card
├── landing.js              ← landing-page fallbacks (external: the CSP
│                             forbids inline script)
├── robots.txt
├── .htaccess               ← SPA fallback + security headers
├── setup.php               ← one-click DB bootstrap (DELETE AFTER USE)
├── admin.php               ← server-rendered admin dashboard
├── update.php              ← version bump + changelog entry
├── .env.example            ← fill in and save as .env on the server
├── api/
│   ├── index.php           ← front controller
│   └── routes/             ← per-feature route handlers
├── config/                 ← OAuth secrets + the .htaccess that denies them
├── src/                    ← namespaced PHP business logic
└── storage/
    ├── outbox/             ← Mailer fallback when mail() isn't configured
    └── errorlog/           ← JS error boundary destination
```

---

## 2. Create the database on IONOS

In the IONOS control panel:

1. Go to **Hosting → Databases → Create database**.
2. Choose the MariaDB / MySQL flavour (either works, schema uses
   spatial types that both support).
3. Note down the four values IONOS gives you:
   - Host (e.g. `db1234567890.hosting-data.io`)
   - Database name (e.g. `dbs1234567890`)
   - Username (e.g. `dbu1234567890`)
   - Password (set by you, or generated)
4. Optional but recommended: enable **daily automatic backups** for
   the database.

The schema gets created in step 6 below by `setup.php`, no need to
run any SQL by hand.

---

## 3. Configure `.env`

On your laptop, in the `Swiftlift Dist/` folder:

1. **Rename** `.env.example` to `.env`, then fill in every value.
2. Open `.env` in a text editor.
3. Fill in the four DB values from step 2.
4. Set the **required** application values:
   - `APP_BASE_URL=https://swiftlift.gg`, used in outbound email
     links. Cannot be blank, leaving it empty used to let an
     attacker hijack the password-reset link via the Host header.
   - `SETUP_TOKEN=`, generate a long random string (e.g.
     `openssl rand -hex 24` or `python -c "import secrets;
     print(secrets.token_urlsafe(40))"`). `setup.php` refuses to run
     without it.
   - `ADMIN_TOKEN=`, generate a **different** long random string.
     Recommended (gives you a recovery path if every admin user
     gets locked out).
5. Keep `APP_DEBUG=0`.
6. Leave OAuth values blank for now, you can add them later without
   redeploying.

### Where to put the `.env` file on the server

You have two options. **Pick the first one if your IONOS layout
allows it**, it's strictly safer.

**Option A, above the webroot (preferred).** Put `.env` in the
parent directory of the webroot, where the webserver can't serve it
under any circumstance. The app looks here FIRST:

```
SwiftLift/                ← parent (you can put files here)
├── .env                  ← REAL credentials live here
└── app/                  ← webroot (everything from Swiftlift Dist/ goes here)
    ├── index.html
    ├── api/
    ├── src/
    ├── .env.example      ← uploaded but unused
    └── ...
```

Why this is better: if `.htaccess` ever gets ignored (some hosts
strip it during PHP-FPM hot-swaps), the file simply can't be reached
by HTTP. There's no `Require all denied` rule to forget.

**Option B, inside the webroot (legacy / single-folder hosting).**
If IONOS doesn't give you a writable parent directory:

```
app/                      ← FTP root, same as webroot
├── .env                  ← REAL credentials, protected by .htaccess
├── index.html
├── api/
├── src/
└── ...
```

The `.htaccess` shipped with SwiftLift has a `<FilesMatch>` rule
that returns 403 for any direct `.env` access, robust as long as
Apache is honouring `.htaccess`.

The code tries above-the-webroot first, then falls back to inside.
If both exist, the above-the-webroot one wins.

---

## 4. FTP the bundle to the webroot

Connect via FTP using the credentials from your IONOS hosting panel.
Upload the **contents** of `Swiftlift Dist/` (not the folder itself)
into the webroot, usually `httpdocs/`, `htdocs/`, or whatever folder
IONOS calls the document root for `swiftlift.gg`.

If the webroot already has files (e.g. the old `update_message/`
"under construction" page), back them up and then clear the webroot
first.

Do **not** upload:
- the `web/` source folder
- `node_modules/`
- `.git/`
- the markdown docs (`DEPLOY.md`, `README.md`, `TEST_PLAN.md`, and any
  local-only notes)
- the `.claude/` worktree directory
- **`.env`** if it exists in `Swiftlift Dist/`, that's the LOCAL dev
  copy (`DB_HOST=localhost`, `APP_BASE_URL=http://swiftlift`). The
  production server uses its OWN `.env` (the one you'll create from
  `.env.example` in step 3, with real IONOS DB credentials). Most
  FTP clients let you "exclude pattern", set it to skip `.env`.
  Only `.env.example` should be uploaded.

After upload, confirm via FTP that you can see `index.html`, `api/`,
`src/`, `setup.php`, `.htaccess`, `.env` (note: some FTP clients hide
dotfiles by default, enable "show hidden files").

---

## 5. Verify HTTPS is on

In a browser, visit `https://swiftlift.gg/`. You should see the
SwiftLift landing page. The lock icon should show a valid certificate.

If HTTP works but HTTPS doesn't, fix that in the IONOS panel before
continuing, the session cookies are set with `Secure` (HTTPS-only)
and outbound email links use the `APP_BASE_URL` you set in step 3.

---

## 6. Create the database tables

Visit:

```
https://swiftlift.gg/setup.php?token=<your SETUP_TOKEN>
```

You should see the **SwiftLift, Database Setup** page. It lists the
DB connection (Host / Database / User) and the current tables (which
should be empty).

Click **Run setup**. The page reloads with a green list of
`CREATE TABLE` and `ALTER TABLE ADD COLUMN` results. Every line should
have a `✓` next to it.

If any line shows `✗`, copy the message, usually it's a permissions
issue with the DB user (IONOS DBs occasionally need a panel toggle to
allow `CREATE TABLE`).

---

## 7. Delete `setup.php` from the server

This is the single most important post-install step. Even though
`setup.php` is now gated behind `SETUP_TOKEN`, the file shouldn't
sit on a live server long-term, if the token ever leaks (logs, a
shared screen, a copied URL), an attacker can DROP every table.

In FTP, navigate to the webroot and delete `setup.php`.

Verify by visiting `https://swiftlift.gg/setup.php`, you should get
a 404.

---

## 8. Register the first user and promote to admin

The very first SwiftLift account becomes the admin. There's no admin
account by default.

1. Visit `https://swiftlift.gg/` and click **New account**.
2. Register with the email you want as the admin email and a 10+
   character password.
3. Check that inbox for the verification email (subject: "Confirm your
   SwiftLift email"). Click the link. The app should load with a green
   "Email confirmed, welcome!" toast.
   - If the email never arrives, check the spam folder, then look in
     `storage/outbox/` on the server via FTP, if `.eml` files are
     being written there, IONOS PHP `mail()` isn't routing. Either
     configure IONOS SMTP or set up a relay (Mailgun / SendGrid).
4. With the new account logged in, visit:
   ```
   https://swiftlift.gg/admin.php?bootstrap=<your-email>
   ```
   You should see a plain-text response: **"Promoted <email> to admin..."**.
   This works because:
   - You're logged in
   - The email being promoted matches your session's email
   - Zero admin users currently exist
5. Visit `https://swiftlift.gg/admin.php`, the dashboard should load.

If something went wrong, you can also fall back on the
`ADMIN_TOKEN` from `.env`:

```
https://swiftlift.gg/admin.php?token=<your ADMIN_TOKEN>
```

This works without a session, useful for break-glass access.

---

## 9. Smoke test

In an incognito window, with a second test email:

- Register a second account
- Confirm the email
- Create a journey on the map
- Drop both pins, save
- Open Profile, set display name and a vehicle
- Sign out, sign back in

Now sign back in as the admin and open `/admin.php`. The test user
should appear in the users list. Confirm you can ban / unban them
from the dashboard.

For the full walkthrough see **TEST_PLAN.md** (devices to test on,
edge cases, security spot-check).

---

## 10. Backups & monitoring

Before announcing the site:

1. **Backups**: IONOS panel → Database → enable daily automatic
   export. Once a week, download the latest dump and confirm it
   restores into a local MariaDB. A backup you've never restored
   isn't a backup.
2. **Uptime**: set up an UptimeRobot (free) HTTP check on
   `https://swiftlift.gg/` every 5 min and on
   `https://swiftlift.gg/api/index.php?p=health` every 15 min. Add
   your email as alert channel and trigger a test alert.
3. **Error log**: FTP-download `storage/errorlog/errors-YYYY-MM.log`
   weekly. Empty file = nothing to worry about; entries are JS errors
   from real user sessions and usually warrant a look.
4. **Outbox**: `storage/outbox/*.eml` should be empty in production.
   If files appear there, IONOS `mail()` is failing and emails aren't
   reaching users.

---

## Re-deploying after a code change

After fixing a bug or shipping a feature:

```bash
cd web && npm run build && cd ..
# Re-run the cp recipe from step 1
```

Then FTP-overwrite the contents of `Swiftlift Dist/` into the webroot.

**Things to be careful about during a re-deploy:**

- **`.env`**: DO NOT overwrite. Your local `.env.example` is a
  template; only the server's `.env` has the real DB password.
  Most FTP clients skip identically-named-or-newer files by default
  but configure your sync to "skip" `.env` to be safe.
- **`storage/`**: don't wipe. It holds outgoing mail fallbacks and
  the JS error log.
- **`setup.php`**: should NOT be on the server after the first
  install. If you need to re-run a schema migration, upload it
  temporarily, run, then delete again.
- **Schema migrations**: `setup.php` is idempotent (uses
  `CREATE TABLE IF NOT EXISTS` and a column-by-column `ADD COLUMN`
  list). If a new release adds a column, ship `setup.php` once,
  hit `/setup.php?token=...`, then delete it again.
- **Service worker**: the file is `sw.js` at the webroot. Each
  build stamps a new `CACHE` version into it, so users automatically
  pick up the new bundle on next page load (no manual hard refresh
  needed).
