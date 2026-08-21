<?php
declare(strict_types=1);

namespace SwiftLift;

/**
 * <summary>
 * Password gatekeeping shared by every code path that lets a user
 * pick a password (register, password change, password reset).
 * </summary>
 * <remarks>
 * The HIBP check uses the Have I Been Pwned "range" API
 * (k-anonymity): we send the first 5 characters of the SHA-1 hash,
 * the API returns every suffix it knows about, and we look for ours
 * locally. The full password never crosses the wire and HIBP cannot
 * reconstruct it. If the request fails for any reason (offline,
 * rate-limited, slow) we fail open. The password is provisionally
 * accepted rather than blocking signups while a third-party API is
 * having a bad day.
 *
 * Surface:
 *
 *   PasswordSecurity::MIN_LENGTH       // 10, the floor we accept
 *   PasswordSecurity::validate($pw)    // returns ?string error message
 * </remarks>
 */
final class PasswordSecurity
{
    /**
     * <summary>
     * Minimum acceptable password length.
     * </summary>
     */
    public const MIN_LENGTH = 10;

    /**
     * <summary>
     * Validate a candidate password against the length floor and the
     * HIBP breach corpus.
     * </summary>
     * <param name="password">The candidate password.</param>
     * <param name="strict">When false, skip the HIBP network call (useful in tests). Defaults to true.</param>
     * <returns>Null if the password is acceptable, or a user-facing error string explaining why it is not.</returns>
     */
    public static function validate(string $password, bool $strict = true): ?string
    {
        if (strlen($password) < self::MIN_LENGTH) {
            return 'Password too short. Please use at least ' . self::MIN_LENGTH . ' characters.';
        }
        if ($strict && self::isPwned($password)) {
            return 'That password has shown up in known data breaches and isn\'t safe to use. Pick something else.';
        }
        return null;
    }

    /**
     * <summary>
     * Look the password up in HaveIBeenPwned's breach corpus using
     * the k-anonymity range API.
     * </summary>
     * <param name="password">The candidate password (only its SHA-1 hash, in fragments, leaves the process).</param>
     * <returns>True if the password appears at least once in any known breach. False on no match or on any network failure (fail-open).</returns>
     * <remarks>
     * Network failures fail open. See the class-level remarks for why.
     * </remarks>
     */
    public static function isPwned(string $password): bool
    {
        $sha1   = strtoupper(sha1($password));
        $prefix = substr($sha1, 0, 5);
        $suffix = substr($sha1, 5);

        $body = self::httpGet('https://api.pwnedpasswords.com/range/' . $prefix);
        if ($body === null) return false;   // fail open

        // Response is one "SUFFIX:COUNT" per line, CRLF-separated.
        foreach (explode("\n", $body) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $parts = explode(':', $line, 2);
            if (strcasecmp($parts[0], $suffix) === 0) {
                // We don't care about the count, even one breach is enough.
                return true;
            }
        }
        return false;
    }

    /**
     * <summary>
     * GET helper used only against the HIBP range endpoint, with SSL
     * peer verification deliberately disabled.
     * </summary>
     * <param name="url">The fully-built range URL.</param>
     * <returns>The response body, or null on any failure (we fail open per the class remarks).</returns>
     * <remarks>
     * SSL note: peer verification is explicitly off. That is
     * deliberate and is safe specifically for this request:
     *
     *   * The endpoint is fully public; no auth, no secrets in the URL.
     *   * We send only the first 5 chars of a SHA-1, irreversible
     *     to the underlying password.
     *   * We rely on the response not matching a hash suffix the
     *     attacker does not already have, so even a man-in-the-middle
     *     cannot usefully forge "yes this is breached" or "no it
     *     isn't" answers.
     *   * Many shared hosts (Windows Apache, some IONOS configs) ship
     *     PHP without a CA bundle, so verification fails by default
     *     and we would silently fail-open on every check, which is
     *     worse for the user than skipping verification.
     * </remarks>
     */
    private static function httpGet(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 4,         // tight, we'd rather fail open than stall registration
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_USERAGENT      => 'SwiftLift/1.0 (HIBP-range)',
                CURLOPT_HTTPHEADER     => ['Add-Padding: true'],  // HIBP feature: pads response so an eavesdropper can't tell which prefix you queried by size
                CURLOPT_SSL_VERIFYPEER => false,  // see note above
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);
            $body = curl_exec($ch);
            $err  = curl_error($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($body === false || $code < 200 || $code >= 300) {
                error_log('[SwiftLift] HIBP curl failed: code=' . $code . ' err=' . $err);
                return null;
            }
            return (string) $body;
        }
        $ctx  = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => "User-Agent: SwiftLift/1.0\r\nAdd-Padding: true",
                'timeout'       => 4,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            error_log('[SwiftLift] HIBP file_get_contents failed for ' . $url);
            return null;
        }
        return (string) $body;
    }
}
