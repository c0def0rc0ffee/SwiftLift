<?php
declare(strict_types=1);

namespace SwiftLift;

/**
 * <summary>
 * Tiny wrapper around PHP's mail() with an optional development
 * outbox for capturing messages on disk when delivery fails.
 * </summary>
 * <remarks>
 * Configuration is via .env:
 *
 *   MAIL_FROM=hello@swiftlift.gg
 *   MAIL_FROM_NAME=SwiftLift
 *   APP_BASE_URL=https://swiftlift.gg
 *   MAIL_DEV_OUTBOX=0   # set to 1 only in dev
 *
 * About MAIL_DEV_OUTBOX: when mail() returns false the message is
 * dropped by default with no copy on disk. Production hosts do not
 * want password reset and verify links sitting in .eml files waiting
 * to leak (the .htaccess blocks HTTP access to /storage but an
 * attacker with FTP credentials or a misconfigured server still
 * wins). For local dev, setting MAIL_DEV_OUTBOX=1 writes the message
 * to storage/outbox/ so you can inspect what would have been sent.
 * Do not enable in production.
 * </remarks>
 */
final class Mailer
{
    /**
     * <summary>
     * Send a plain-text email via PHP's mail(), falling back to either
     * a development outbox file or a logged failure depending on
     * MAIL_DEV_OUTBOX.
     * </summary>
     * <param name="toEmail">Recipient email address.</param>
     * <param name="subject">Subject line. Base64-encoded as UTF-8 for transport.</param>
     * <param name="body">Plain text body, UTF-8.</param>
     * <returns>True if mail() reported success, false otherwise.</returns>
     */
    public static function send(string $toEmail, string $subject, string $body): bool
    {
        $from     = Env::get('MAIL_FROM',      'hello@swiftlift.gg');
        $fromName = Env::get('MAIL_FROM_NAME', 'SwiftLift');

        $headers = implode("\r\n", [
            'From: ' . self::encodeFrom((string) $fromName, (string) $from),
            'Reply-To: ' . $from,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Mailer: SwiftLift',
        ]);

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        // Attempt PHP mail(), works on most shared hosts including IONOS.
        $sent = false;
        try {
            $sent = @mail($toEmail, $encodedSubject, $body, $headers);
        } catch (\Throwable $e) {
            $sent = false;
        }

        if (!$sent) {
            $devOutbox = filter_var(Env::get('MAIL_DEV_OUTBOX', '0'), FILTER_VALIDATE_BOOLEAN);
            if ($devOutbox) {
                self::writeOutbox($toEmail, $subject, $body, $headers);
            } else {
                // No body, no recipient, no token. Just record the failure
                // so the operator knows mail() is broken; the actual content
                // never touches the disk.
                error_log("[SwiftLift] mail() failed (mail not delivered, no fallback file written; set MAIL_DEV_OUTBOX=1 in .env to capture for dev).");
            }
        }
        return $sent;
    }

    /**
     * <summary>
     * Format the From header value with a base64 UTF-8 encoded display
     * name when present, falling back to a bare email address.
     * </summary>
     * <param name="name">The display name to encode.</param>
     * <param name="email">The sender email address.</param>
     * <returns>A valid RFC-conformant From value.</returns>
     */
    private static function encodeFrom(string $name, string $email): string
    {
        $name = trim($name);
        if ($name === '') return $email;
        return '=?UTF-8?B?' . base64_encode($name) . '?= <' . $email . '>';
    }

    /**
     * <summary>
     * Write a failed-delivery copy of an email to storage/outbox/ for
     * inspection during local development. Filename includes a
     * timestamp and a sanitised recipient.
     * </summary>
     * <param name="to">The recipient address.</param>
     * <param name="subject">The original (unencoded) subject line.</param>
     * <param name="body">The message body.</param>
     * <param name="headers">The fully assembled header block.</param>
     */
    private static function writeOutbox(string $to, string $subject, string $body, string $headers): void
    {
        $dir = dirname(__DIR__) . '/storage/outbox';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $stamp = date('Ymd-His');
        $safeTo = preg_replace('/[^a-z0-9_.-]/i', '_', $to) ?? 'unknown';
        $file = sprintf('%s/%s-%s.eml', $dir, $stamp, $safeTo);
        $contents = "To: $to\r\nSubject: $subject\r\n$headers\r\n\r\n$body\r\n";
        @file_put_contents($file, $contents);
        error_log("[SwiftLift] mail() failed; wrote outbox file $file (MAIL_DEV_OUTBOX is on)");
    }
}
