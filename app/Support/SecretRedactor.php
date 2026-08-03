<?php

namespace App\Support;

/**
 * Strip credentials out of text destined for a log.
 *
 * The Yelp automation shells out to Node with credentials as CLI arguments —
 * `--password=`, `--proxy=http://user:pass@host`, `--twocaptcha-key=`. When
 * Symfony's Process throws (timeout, signal, non-zero exit) the exception
 * message embeds the ENTIRE command line, and every catch block logged
 * `$e->getMessage()` verbatim.
 *
 * That is how the proxy password, both captcha keys and the IPRoyal password
 * ended up in `laravel.log` — 47 occurrences across one five-month file, which
 * then got downloaded and left sitting in `public/`. Logs get copied, pasted
 * into issues and handed to whoever is debugging; anything written to one
 * should be assumed to travel.
 *
 * Redaction happens at the log boundary rather than by changing how the
 * scripts take credentials: argv is the interface Node expects, and stdin
 * plumbing for five separate scripts is a much larger change than making the
 * logger safe.
 */
class SecretRedactor
{
    /**
     * CLI flags whose value is a credential.
     *
     * Matched case-insensitively against `--flag=value`, up to the next
     * whitespace or quote.
     */
    private const SECRET_FLAGS = [
        'password',
        'proxy',
        'twocaptcha-key',
        'anticaptcha-key',
        'cookies-file',
        'api-key',
        'token',
    ];

    public static function redact(?string $text): string
    {
        $text = (string) $text;
        if ($text === '') {
            return '';
        }

        // --flag=secret  ->  --flag=[REDACTED]
        $flags = implode('|', array_map('preg_quote', self::SECRET_FLAGS));
        $text = preg_replace(
            '/(--(?:' . $flags . ')=)([^\s\'"]+)/i',
            '$1[REDACTED]',
            $text,
        ) ?? $text;

        // Credentials embedded in a URL: scheme://user:pass@host
        $text = preg_replace(
            '#(://)([^:/\s\'"]+):([^@/\s\'"]+)@#',
            '$1$2:[REDACTED]@',
            $text,
        ) ?? $text;

        // Anything that still looks like a known-shape credential. Catches
        // values printed without a flag — e.g. an API error quoting the key.
        $shapes = [
            '/AIza[0-9A-Za-z_-]{35}/',            // Google
            '/sk-proj-[A-Za-z0-9_-]{20,}/',        // OpenAI
            '/GOCSPX-[A-Za-z0-9_-]{10,}/',         // Google OAuth client secret
            '/\bkey-[0-9a-f]{32}\b/',              // Mailgun
            '/\bxox[baprs]-[A-Za-z0-9-]{10,}/',    // Slack
        ];
        foreach ($shapes as $re) {
            $text = preg_replace($re, '[REDACTED]', $text) ?? $text;
        }

        return $text;
    }

    /**
     * Redact every string in a log context array, recursively.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function context(array $context): array
    {
        foreach ($context as $k => $v) {
            if (is_string($v)) {
                $context[$k] = self::redact($v);
            } elseif (is_array($v)) {
                $context[$k] = self::context($v);
            }
        }

        return $context;
    }
}
