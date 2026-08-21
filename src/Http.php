<?php
declare(strict_types=1);

namespace SwiftLift;

/**
 * <summary>
 * Tiny helpers for JSON request and response handling, method
 * enforcement and resolving the canonical app base URL used in
 * outbound emails.
 * </summary>
 * <remarks>
 * Every helper terminates the request when emitting a response so
 * route handlers can return early without manually calling exit.
 * </remarks>
 */
final class Http
{
    /**
     * <summary>
     * Emit a JSON response with the given status code and end the
     * request.
     * </summary>
     * <param name="data">Any JSON-serialisable value to send as the body.</param>
     * <param name="status">HTTP status code. Defaults to 200.</param>
     * @param mixed $data
     */
    public static function json($data, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * <summary>
     * Emit a JSON error response and end the request.
     * </summary>
     * <param name="message">User-facing error string sent as the "error" field of the body.</param>
     * <param name="status">HTTP status code. Defaults to 400. Anything 500 or above is downgraded to 400.</param>
     * <remarks>
     * Status codes of 500 or above can be intercepted by shared-host
     * ErrorDocuments that overwrite the body, so we keep errors in 4xx
     * where possible.
     * </remarks>
     */
    public static function error(string $message, int $status = 400): void
    {
        // Status >=500 can be intercepted by shared-host ErrorDocuments that
        // overwrite our body. Keep errors in 4xx where possible.
        if ($status >= 500) $status = 400;
        self::json(['error' => $message], $status);
    }

    /**
     * <summary>
     * Read the request body as JSON and return it as an associative
     * array, or an empty array when the body is missing or not a JSON
     * object.
     * </summary>
     * <returns>The decoded JSON payload, or an empty array on any failure to parse.</returns>
     */
    public static function body(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '') return [];
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * <summary>
     * Reject requests that don't match the expected HTTP method by
     * emitting a 405 JSON error.
     * </summary>
     * <param name="method">Expected method, e.g. "POST".</param>
     */
    public static function requireMethod(string $method): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
            self::error('Method not allowed', 405);
        }
    }

    /**
     * <summary>
     * Return the canonical base URL used to build links in outbound
     * emails (verify, password reset, OAuth callbacks).
     * </summary>
     * <returns>The configured APP_BASE_URL with any trailing slash trimmed.</returns>
     * <exception cref="\RuntimeException">If APP_BASE_URL is missing or does not parse as an http(s) URL.</exception>
     * <remarks>
     * Returns the value of APP_BASE_URL and only that value. Falling
     * back to $_SERVER['HTTP_HOST'] (the older behaviour) lets an
     * attacker inject a Host header into a /api/auth/forgot POST and
     * trick the victim's reset email into pointing at attacker.com,
     * where the leaked URL contains the password reset token. Operators
     * must set APP_BASE_URL in .env.
     * </remarks>
     */
    public static function appBaseUrl(): string
    {
        $base = trim((string) (Env::get('APP_BASE_URL') ?? ''));
        if ($base === '') {
            throw new \RuntimeException(
                'APP_BASE_URL is not configured. Set it in .env to the public URL of this site (e.g. https://swiftlift.gg).'
            );
        }
        $base = rtrim($base, '/');
        $parts = parse_url($base);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
        ) {
            throw new \RuntimeException('APP_BASE_URL must be a full http(s) URL.');
        }
        return $base;
    }
}
