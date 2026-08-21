<?php
declare(strict_types=1);

/**
 * <summary>
 * Application bootstrap. Registers a PSR-4 style autoloader for the
 * SwiftLift namespace and loads the .env file (preferring above the
 * webroot, falling back to inside it).
 * </summary>
 * <remarks>
 * Sessions are deliberately not started here. Auth::start() handles
 * lazy session boot inside routes that need it, which keeps lightweight
 * endpoints (e.g. /api/__ping) from touching the session store at all.
 * </remarks>
 */

/**
 * <summary>
 * Resolve a class name in the SwiftLift namespace to a file under
 * src/ and require it. Other namespaces are ignored.
 * </summary>
 * <param name="class">The fully qualified class name being autoloaded.</param>
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'SwiftLift\\';
    if (strpos($class, $prefix) !== 0) return;
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require $file;
});

// .env discovery. Try ABOVE the webroot first (best practice, secrets
// outside the document root can't be served even if Apache config
// breaks or .htaccess is ignored), then fall back to inside the
// webroot for simple setups where there's no parent directory the user
// can write to.
//
// Layout 1 (preferred, IONOS, cPanel, anywhere with a writable
// parent directory of the webroot):
//   SwiftLift/
//   ├── .env              ← lives here, NOT web-accessible
//   └── app/              ← webroot (this is "dirname(__DIR__)")
//       ├── index.html
//       ├── api/
//       └── src/          ← __DIR__
//
// Layout 2 (legacy / single-folder hosting):
//   webroot/
//   ├── .env              ← lives here, .htaccess denies direct access
//   ├── index.html
//   └── src/              ← __DIR__
$webroot = dirname(__DIR__);            // parent of src/ = the webroot
foreach ([dirname($webroot) . '/.env', $webroot . '/.env'] as $envPath) {
    if (is_file($envPath)) { \SwiftLift\Env::load($envPath); break; }
}

// Sessions are now started lazily by Auth::start() inside routes that need them,
// keeps /api/__ping and other lightweight endpoints from touching sessions at all.
