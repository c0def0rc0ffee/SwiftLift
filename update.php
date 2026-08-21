<?php
declare(strict_types=1);

/**
 * <summary>
 * Release helper. A small operator page to bump the live app version and
 * add a changelog entry in one step, instead of editing code and
 * redeploying just for a version + "what's new" line.
 * </summary>
 * <remarks>
 * Access mirrors admin.php: you must be a logged-in admin, or hold the
 * ADMIN_TOKEN. The token is accepted only as a one-shot GET login that
 * sets a session flag and immediately redirects to a token-free URL, so
 * it never lingers in history or logs. All mutating POSTs ride the
 * session and carry the shared admin CSRF token.
 *
 * The version is stored in app_meta['version']; Version::current() reads
 * it with a fallback to the Version::CURRENT constant. The changelog
 * entry is a normal issues row (status = fixed, fixed_in_version = the
 * new version), so it shows up in the About page's Improvements list and
 * in the admin Issues editor like any other.
 *
 * This file is safe to leave on the server (it's gated), but you can
 * delete it between releases if you prefer a smaller surface.
 * </remarks>
 */

require __DIR__ . '/src/bootstrap.php';

use SwiftLift\{Auth, Db, Env, IssueRepo, Version};

$denyAsNotFound = static function (): never {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><html><head><title>404 Not Found</title></head>"
       . "<body><h1>Not Found</h1><p>The requested URL was not found on this server.</p></body></html>";
    exit;
};

Auth::start();

// One-shot ADMIN_TOKEN login (GET only), then strip the token from the URL.
$expected = (string) (Env::get('ADMIN_TOKEN') ?? '');
$supplied = (string) ($_GET['token'] ?? '');
// A placeholder or too-short token is not a credential: the example value
// is published in the repository.
if ($_SERVER['REQUEST_METHOD'] === 'GET'
    && !Env::isPlaceholderSecret($expected) && hash_equals($expected, $supplied)) {
    $_SESSION['admin_token_ok'] = true;
    $clean = $_GET;
    unset($clean['token']);
    $self = strtok((string) $_SERVER['REQUEST_URI'], '?');
    header('Location: ' . $self . ($clean ? '?' . http_build_query($clean) : ''));
    exit;
}

// Admin via the one-shot token flag, or a logged-in is_admin user.
$isAdmin = !empty($_SESSION['admin_token_ok']);
if (!$isAdmin && ($_SESSION['uid'] ?? null) !== null) {
    try {
        $stmt = Db::pdo()->prepare(
            "SELECT is_admin FROM users WHERE id = ? AND deleted_at IS NULL AND banned_at IS NULL AND disabled_at IS NULL"
        );
        $stmt->execute([(int) $_SESSION['uid']]);
        $isAdmin = (bool) $stmt->fetchColumn();
    } catch (\Throwable $e) { /* swallow */ }
}
if (!$isAdmin) $denyAsNotFound();

// Shared admin CSRF token (same one admin.php uses).
if (empty($_SESSION['admin_csrf'])) $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
$csrf = (string) $_SESSION['admin_csrf'];

// Make sure the settings table exists so this works without a separate
// setup.php run.
try {
    Db::pdo()->exec(
        "CREATE TABLE IF NOT EXISTS app_meta (
            k          VARCHAR(64) PRIMARY KEY,
            v          VARCHAR(255) NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
} catch (\Throwable $e) { /* surfaced on save if the DB is truly broken */ }

$flash = null;
$flashErr = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
        $flash = 'Security check failed. Reload the page and try again.';
        $flashErr = true;
    } else {
        $newVersion = trim((string) ($_POST['version'] ?? ''));
        $title      = trim((string) ($_POST['title'] ?? ''));
        $desc       = trim((string) ($_POST['description'] ?? ''));
        $kind       = (string) ($_POST['kind'] ?? 'feature');
        $isPublic   = !empty($_POST['is_public']);
        $addLog     = !empty($_POST['add_log']);

        if (!preg_match('/^\d{1,3}(\.\d{1,3}){1,3}$/', $newVersion)) {
            $flash = 'Enter a version like 1.2.0';
            $flashErr = true;
        } elseif ($addLog && $title === '') {
            $flash = 'Give the changelog entry a title, or untick "add changelog entry".';
            $flashErr = true;
        } else {
            try {
                Db::pdo()->prepare(
                    "INSERT INTO app_meta (k, v) VALUES ('version', ?)
                     ON DUPLICATE KEY UPDATE v = VALUES(v)"
                )->execute([$newVersion]);

                $msg = "Version set to $newVersion.";
                if ($addLog) {
                    $id = IssueRepo::create([
                        'kind'             => $kind,
                        'title'            => $title,
                        'description'      => $desc !== '' ? $desc : null,
                        'status'           => 'fixed',
                        'fixed_in_version' => $newVersion,
                        'is_public'        => $isPublic,
                        'sort_order'       => 0,
                    ]);
                    $msg .= " Added changelog entry #$id.";
                }
                $flash = $msg;
            } catch (\Throwable $e) {
                $flash = 'Could not save: ' . $e->getMessage();
                $flashErr = true;
            }
        }
    }
}

$current = Version::current();
$recent  = [];
try { $recent = array_slice(IssueRepo::listAll(), 0, 8); } catch (\Throwable $e) { /* fresh DB */ }

$h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>SwiftLift: Release</title>
<style>
  :root { color-scheme: dark; }
  body { font-family: ui-sans-serif, system-ui, sans-serif; background: #0f172a; color: #f1f5f9;
         max-width: 760px; margin: 2rem auto; padding: 0 1rem; line-height: 1.5; }
  h1 { margin-top: 0; }
  code { font-family: ui-monospace, Menlo, Consolas, monospace; }
  .panel { background: #1e293b; border-radius: 10px; padding: 1rem 1.25rem; margin: 1rem 0; border: 1px solid #334155; }
  label { display: block; margin: .75rem 0 .25rem; font-weight: 600; }
  input[type=text], textarea, select {
    width: 100%; background: #0b1221; color: #f1f5f9; border: 1px solid #334155;
    border-radius: 6px; padding: .55rem .65rem; font: inherit;
  }
  .row { display: flex; gap: 1rem; flex-wrap: wrap; align-items: center; }
  .row > label { margin: 0; }
  .check { display: inline-flex; gap: .4rem; align-items: center; font-weight: 400; }
  button { background: #2563eb; color: #fff; border: 0; padding: .7rem 1.2rem;
           border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 1rem; margin-top: 1rem; }
  button:hover { filter: brightness(1.08); }
  .flash { padding: .6rem .85rem; border-radius: 6px; margin: 1rem 0; }
  .flash.ok  { background: rgba(34,197,94,.15);  border: 1px solid #22c55e; color: #86efac; }
  .flash.err { background: rgba(239,68,68,.15);  border: 1px solid #ef4444; color: #fca5a5; }
  .muted { color: #94a3b8; font-size: .9rem; }
  table { width: 100%; border-collapse: collapse; margin-top: .5rem; font-size: .9rem; }
  th, td { text-align: left; padding: .35rem .5rem; border-bottom: 1px solid #334155; vertical-align: top; }
  th { color: #94a3b8; font-weight: 600; font-size: .8rem; text-transform: uppercase; letter-spacing: .05em; }
</style>
</head>
<body>
<h1>SwiftLift: Release</h1>
<p class="muted">Bump the app version and add a changelog entry in one step.
  Current version: <code><?= $h($current) ?></code>.</p>

<?php if ($flash !== null): ?>
  <div class="flash <?= $flashErr ? 'err' : 'ok' ?>"><?= $h($flash) ?></div>
<?php endif; ?>

<form method="post" class="panel">
  <input type="hidden" name="csrf" value="<?= $h($csrf) ?>">

  <label for="version">New version</label>
  <input type="text" id="version" name="version" value="<?= $h($current) ?>"
         placeholder="e.g. 1.2.0" required>

  <label class="check" style="margin-top:1rem;">
    <input type="checkbox" name="add_log" value="1" checked>
    Also add a changelog entry for this release
  </label>

  <label for="title">Changelog title</label>
  <input type="text" id="title" name="title" maxlength="160"
         placeholder="e.g. Email alerts when a new journey matches yours">

  <label for="description">Details (optional)</label>
  <textarea id="description" name="description" rows="3"
            placeholder="A sentence or two about what changed and why."></textarea>

  <div class="row" style="margin-top:1rem;">
    <label for="kind">Kind</label>
    <select id="kind" name="kind" style="width:auto;">
      <option value="feature">Feature</option>
      <option value="improvement">Improvement</option>
      <option value="bug">Bug fix</option>
    </select>
    <label class="check"><input type="checkbox" name="is_public" value="1" checked> Show on the public About page</label>
  </div>

  <button type="submit">Save release</button>
</form>

<div class="panel">
  <h2 style="margin-top:0; font-size:1.05rem;">Recent changelog entries</h2>
  <?php if (!$recent): ?>
    <p class="muted">No entries yet.</p>
  <?php else: ?>
    <table>
      <thead><tr><th>Title</th><th>Kind</th><th>Status</th><th>Fixed in</th><th>Public</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $iss): ?>
        <tr>
          <td><?= $h((string) $iss['title']) ?></td>
          <td><?= $h((string) $iss['kind']) ?></td>
          <td><?= $h((string) $iss['status']) ?></td>
          <td><?= $iss['fixed_in_version'] ? $h((string) $iss['fixed_in_version']) : '-' ?></td>
          <td><?= !empty($iss['is_public']) ? 'yes' : 'no' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
  <p class="muted">Manage entries in detail from <code>admin.php</code> &rarr; Issues.</p>
</div>

</body>
</html>
