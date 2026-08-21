<?php
declare(strict_types=1);

namespace SwiftLift\OAuth;

use SwiftLift\Config;

/**
 * <summary>
 * Facebook Login (OAuth 2.0) server-side flow helpers.
 * </summary>
 * <remarks>
 * Usage:
 *
 *   1. isConfigured() returns true once both app_id and app_secret are
 *      present, in either env vars (FACEBOOK_APP_ID and
 *      FACEBOOK_APP_SECRET) or config/oauth.php. The login button
 *      only renders when this returns true, so an unconfigured server
 *      simply hides it.
 *   2. authorizeUrl() builds the URL to redirect the user's browser to.
 *   3. exchangeCode() swaps the ?code=... callback parameter for an
 *      access token.
 *   4. profile() fetches id, name and email from the Graph API.
 * </remarks>
 */
final class FacebookProvider
{
    /**
     * <summary>
     * Pinned Graph API version. Bumping this requires re-testing the
     * auth flow against Facebook's release notes.
     * </summary>
     */
    private const API_VERSION = 'v18.0';

    /**
     * <summary>
     * True when both Facebook app id and secret are configured (via
     * env or config/oauth.php).
     * </summary>
     * <returns>True when login through Facebook is available.</returns>
     */
    public static function isConfigured(): bool
    {
        return self::appId() !== null && self::appSecret() !== null;
    }

    /**
     * <summary>
     * Read the configured Facebook app id, or null when unset.
     * </summary>
     */
    private static function appId(): ?string
    {
        $v = Config::get('oauth', 'facebook.app_id');
        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * <summary>
     * Read the configured Facebook app secret, or null when unset.
     * </summary>
     */
    private static function appSecret(): ?string
    {
        $v = Config::get('oauth', 'facebook.app_secret');
        return is_string($v) && $v !== '' ? $v : null;
    }

    /**
     * <summary>
     * Build the Facebook authorisation URL to redirect the user to,
     * carrying an opaque state string for CSRF protection and the
     * site's redirect URI.
     * </summary>
     * <param name="state">CSRF / round-trip nonce. The caller is responsible for storing it in the session.</param>
     * <param name="redirectUri">The full URL Facebook should send the user back to.</param>
     * <returns>An absolute URL to redirect the user's browser to.</returns>
     */
    public static function authorizeUrl(string $state, string $redirectUri): string
    {
        return 'https://www.facebook.com/' . self::API_VERSION . '/dialog/oauth?' . http_build_query([
            'client_id'     => self::appId(),
            'redirect_uri'  => $redirectUri,
            'state'         => $state,
            'scope'         => 'email,public_profile',
            'response_type' => 'code',
        ]);
    }

    /**
     * <summary>
     * Exchange an authorisation code for a Facebook access token.
     * </summary>
     * <param name="code">The code returned by Facebook on the redirect.</param>
     * <param name="redirectUri">Must exactly match the redirect URI used in authorizeUrl().</param>
     * <returns>The access token string.</returns>
     * <exception cref="\RuntimeException">If Facebook responds without an access token.</exception>
     */
    public static function exchangeCode(string $code, string $redirectUri): string
    {
        // POST with the credentials in the body, never the query string:
        // a URL carrying client_secret can surface in proxy logs, error
        // reports and Referer headers. Mirrors GoogleProvider.
        $body = self::httpPost(
            'https://graph.facebook.com/' . self::API_VERSION . '/oauth/access_token',
            [
                'client_id'     => self::appId(),
                'client_secret' => self::appSecret(),
                'redirect_uri'  => $redirectUri,
                'code'          => $code,
            ]
        );
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['access_token'])) {
            throw new \RuntimeException('Facebook did not return an access token');
        }
        return (string) $data['access_token'];
    }

    /**
     * <summary>
     * Fetch the Facebook profile (id, optional name, optional email)
     * for a given access token.
     * </summary>
     * <param name="accessToken">The token returned by exchangeCode().</param>
     * <returns>An associative array with at least an "id" key.</returns>
     * <exception cref="\RuntimeException">If the Graph API does not return a usable profile.</exception>
     * @return array{id:string, name?:string, email?:string}
     */
    public static function profile(string $accessToken): array
    {
        $url = 'https://graph.facebook.com/' . self::API_VERSION . '/me?' . http_build_query([
            'fields'       => 'id,name,email',
            'access_token' => $accessToken,
        ]);
        $body = self::http($url);
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['id'])) {
            throw new \RuntimeException('Could not fetch Facebook profile');
        }
        return $data;
    }

    /**
     * <summary>
     * Issue an HTTP GET to a Facebook endpoint, preferring cURL when
     * available and falling back to file_get_contents with a stream
     * context otherwise.
     * </summary>
     * <param name="url">The fully-built URL to fetch.</param>
     * <returns>The response body as a string.</returns>
     * <exception cref="\RuntimeException">On any HTTP failure or non-2xx status.</exception>
     */
    private static function http(string $url): string
    {
        if (!function_exists('curl_init')) {
            $ctx = stream_context_create(['http' => ['timeout' => 10, 'user_agent' => 'SwiftLift/1.0']]);
            $body = @file_get_contents($url, false, $ctx);
            if ($body === false) throw new \RuntimeException('HTTP request failed');
            return $body;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'SwiftLift/1.0',
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false)             throw new \RuntimeException("HTTP request failed: $err");
        if ($code < 200 || $code >= 300) throw new \RuntimeException("HTTP $code from Facebook: " . substr((string) $body, 0, 200));
        return (string) $body;
    }

    /**
     * <summary>
     * Issue an HTTP POST with a form-encoded body, preferring cURL when
     * available and falling back to a stream context otherwise.
     * </summary>
     * <param name="url">The endpoint to POST to.</param>
     * <param name="form">Associative array of form fields to send.</param>
     * <returns>The response body as a string.</returns>
     * <exception cref="\RuntimeException">On any HTTP failure or non-2xx status.</exception>
     * <remarks>
     * Used for the token exchange so the app secret travels in the
     * request body rather than the URL.
     * </remarks>
     */
    private static function httpPost(string $url, array $form): string
    {
        $payload = http_build_query($form);
        if (!function_exists('curl_init')) {
            $ctx = stream_context_create(['http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: SwiftLift/1.0",
                'content'       => $payload,
                'timeout'       => 10,
                'ignore_errors' => true,
            ]]);
            $body = @file_get_contents($url, false, $ctx);
            if ($body === false) throw new \RuntimeException('HTTP request failed');
            return $body;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_USERAGENT      => 'SwiftLift/1.0',
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false)             throw new \RuntimeException("HTTP request failed: $err");
        if ($code < 200 || $code >= 300) throw new \RuntimeException("HTTP $code from Facebook: " . substr((string) $body, 0, 200));
        return (string) $body;
    }
}
