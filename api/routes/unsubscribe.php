<?php
declare(strict_types=1);

use SwiftLift\{Db, Env};

/**
 * <summary>
 * GET /api/index.php?p=unsubscribe&u=&lt;id&gt;&t=&lt;token&gt;
 * One-click unsubscribe from match-alert emails.
 * </summary>
 * <remarks>
 * Unauthenticated by design: the link is clicked straight from an email,
 * possibly in a browser with no session. It verifies the stable per-user
 * unsub_token, turns off notify_matches, and renders a tiny confirmation
 * page. Invalid tokens get a generic "not valid" page rather than any
 * detail, and nothing here ever throws out to the JSON error handler.
 * </remarks>
 */

header('Content-Type: text/html; charset=utf-8');

$uid   = (int) ($_GET['u'] ?? 0);
$token = (string) ($_GET['t'] ?? '');

$ok = false;
if ($uid > 0 && $token !== '') {
    try {
        $stmt = Db::pdo()->prepare("SELECT unsub_token FROM users WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$uid]);
        $stored = (string) ($stmt->fetchColumn() ?: '');
        if ($stored !== '' && hash_equals($stored, $token)) {
            Db::pdo()->prepare("UPDATE users SET notify_matches = 0 WHERE id = ?")->execute([$uid]);
            $ok = true;
        }
    } catch (\Throwable $e) {
        error_log('[SwiftLift] unsubscribe error: ' . $e->getMessage());
    }
}

$base  = rtrim((string) (Env::get('APP_BASE_URL', '') ?? ''), '/');
$h     = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$title = $ok ? 'Unsubscribed' : 'Link not valid';
$msg   = $ok
    ? "Done. You won't get any more \"a new journey matches yours\" emails. You can turn them back on any time in your profile."
    : "That unsubscribe link doesn't look valid. You can manage email alerts in your profile instead.";

echo "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\">"
   . "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"
   . "<title>SwiftLift: " . $h($title) . "</title>"
   . "<style>body{font-family:system-ui,-apple-system,sans-serif;background:#1a1a1a;color:#ececec;"
   . "max-width:32rem;margin:4rem auto;padding:0 1.25rem;line-height:1.55}"
   . "h1{font-size:1.3rem;margin:0 0 .5rem}a{color:#8fd66a}</style></head><body>"
   . "<h1>" . $h($title) . "</h1><p>" . $h($msg) . "</p>";
if ($base !== '') echo "<p><a href=\"" . $h($base) . "\">Back to SwiftLift</a></p>";
echo "</body></html>";
