<?php

namespace App\Support;

/**
 * Normalises a browser cookie export into the shape scripts/lib/yelp-cookies.mjs
 * injects, and writes it to the one file every Yelp automation reads.
 *
 * Extracted because the browser-extension ingest endpoint and the admin paste
 * box must agree byte-for-byte: two normalisers would drift, and a cookie jar
 * that differs by one field is the difference between an authenticated
 * automation profile and a silent `session_expired` an hour later.
 *
 * Three corrections happen here that the raw browser export does not carry:
 *
 *  1. `session: true` cookies (no expirationDate) become CDP *session* cookies,
 *     which live only for one Chromium run and are never written to
 *     Default/Cookies. An imported session then authenticates exactly once and
 *     "expires" — the failure mode this whole feature exists to end. We give
 *     them a real expiry so they persist between queue jobs.
 *
 *  2. Host-only and `__Host-`-prefixed cookies must be re-injected WITHOUT a
 *     domain attribute or the browser silently drops them. We emit a `url`
 *     instead, which yelp-cookies.mjs passes straight through to CDP.
 *
 *  3. Domain matching is a real suffix test. The pre-existing importers used
 *     str_contains($domain, 'yelp.com'), which also accepts
 *     `evil-yelp.com.attacker.net`. Harmless for a paste box an admin controls;
 *     a cookie-injection vector for an endpoint reachable over the network.
 */
class YelpCookieJar
{
    /**
     * Absolute path of the jar every Yelp script is pointed at.
     *
     * Config-driven so tests can redirect it. Without that, any test touching
     * the jar reads and deletes the real one — which is exactly what happened
     * while building this class.
     */
    public static function path(): string
    {
        return (string) config('services.yelp.business.cookies_file')
            ?: storage_path('app/yelp-cookies.json');
    }

    /**
     * True when $domain is yelp.com or a subdomain of it.
     *
     * Leading dots (".yelp.com" — how browsers mark domain cookies) are
     * stripped before comparison.
     */
    public static function isYelpDomain(?string $domain): bool
    {
        $d = strtolower(trim((string) $domain));
        $d = ltrim($d, '.');

        return $d === 'yelp.com' || str_ends_with($d, '.yelp.com');
    }

    /**
     * Normalise a raw browser cookie list.
     *
     * Accepts either a bare list or `{"cookies": [...]}` — the same two shapes
     * yelp-cookies.mjs accepts — so an operator can paste a Cookie-Editor
     * export unchanged.
     *
     * @param  int  $sessionTtlDays  Expiry granted to session-only cookies; 0 leaves them session-only.
     * @return array{cookies: array<int, array<string, mixed>>, stats: array<string, int>}
     */
    public static function normalize(mixed $raw, ?int $sessionTtlDays = null): array
    {
        $sessionTtlDays ??= (int) config('services.yelp.business.cookie_session_ttl_days', 30);

        if (is_array($raw) && isset($raw['cookies']) && is_array($raw['cookies'])) {
            $raw = $raw['cookies'];
        }
        if (! is_array($raw)) {
            return ['cookies' => [], 'stats' => self::emptyStats()];
        }

        $stats = self::emptyStats();
        $out = [];

        foreach ($raw as $c) {
            $stats['received']++;

            if (! is_array($c) || ! isset($c['name']) || ! is_string($c['name']) || $c['name'] === '') {
                $stats['skipped_malformed']++;
                continue;
            }
            if (! array_key_exists('value', $c) || $c['value'] === null) {
                $stats['skipped_malformed']++;
                continue;
            }
            if (! self::isYelpDomain($c['domain'] ?? null)) {
                $stats['skipped_foreign_domain']++;
                continue;
            }

            $name = (string) $c['name'];
            $rawDomain = strtolower(trim((string) $c['domain']));
            $path = isset($c['path']) && $c['path'] !== '' ? (string) $c['path'] : '/';
            $secure = (bool) ($c['secure'] ?? false);

            // A leading dot means "and all subdomains"; its absence means the
            // cookie belongs to exactly that host. chrome.cookies also reports
            // this as hostOnly, which we prefer when present.
            $hostOnly = array_key_exists('hostOnly', $c)
                ? (bool) $c['hostOnly']
                : ! str_starts_with($rawDomain, '.');

            $cookie = [
                'name' => $name,
                'value' => (string) $c['value'],
                'path' => $path,
                'secure' => $secure,
                'httpOnly' => (bool) ($c['httpOnly'] ?? false),
            ];

            // __Host- requires secure + path=/ + NO domain. Emitting a domain
            // for it (or for any host-only cookie) makes the browser reject or
            // widen it; a url carries the host without the domain attribute.
            $isHostPrefixed = str_starts_with($name, '__Host-');
            if ($isHostPrefixed || $hostOnly) {
                $host = ltrim($rawDomain, '.');
                $cookie['url'] = ($secure || $isHostPrefixed ? 'https://' : 'http://') . $host . ($isHostPrefixed ? '/' : $path);
                if ($isHostPrefixed) {
                    $cookie['secure'] = true;
                    $cookie['path'] = '/';
                }
                $stats['host_only']++;
            } else {
                $cookie['domain'] = $rawDomain;
            }

            // chrome.cookies expirationDate and CDP expires are BOTH seconds
            // since epoch. No ms conversion.
            $expires = null;
            if (isset($c['expirationDate']) && is_numeric($c['expirationDate'])) {
                $expires = (int) floor((float) $c['expirationDate']);
            } elseif (isset($c['expires']) && is_numeric($c['expires']) && (float) $c['expires'] > 0) {
                $expires = (int) floor((float) $c['expires']);
            }

            if ($expires === null || $expires <= time()) {
                $stats['session_only']++;
                if ($sessionTtlDays > 0) {
                    $expires = time() + ($sessionTtlDays * 86400);
                    $stats['session_persisted']++;
                } else {
                    $expires = null;
                }
            }
            if ($expires !== null) {
                $cookie['expires'] = $expires;
            }

            // chrome.cookies uses lowercase snake; CDP wants capitalised.
            // "unspecified" has no CDP equivalent — omit rather than coerce to
            // None, which would change cross-site behaviour.
            $sameSite = strtolower((string) ($c['sameSite'] ?? ''));
            $mapped = match (true) {
                str_starts_with($sameSite, 'no') => 'None',
                str_starts_with($sameSite, 'lax') => 'Lax',
                str_starts_with($sameSite, 'strict') => 'Strict',
                default => null,
            };
            if ($mapped !== null) {
                // Chrome rejects SameSite=None without Secure, and one rejected
                // cookie can fail the whole injection batch.
                if ($mapped === 'None' && ! $cookie['secure']) {
                    $mapped = 'Lax';
                    $stats['samesite_downgraded']++;
                }
                $cookie['sameSite'] = $mapped;
            }

            // CHIPS: a partitioned cookie only re-injects into the same
            // partition, and requires Secure.
            if (isset($c['partitionKey']) && is_array($c['partitionKey'])) {
                $cookie['partitionKey'] = $c['partitionKey'];
                $cookie['secure'] = true;
                $stats['partitioned']++;
            }

            $out[] = $cookie;
            $stats['kept']++;
        }

        return ['cookies' => self::dedupe($out, $stats), 'stats' => $stats];
    }

    /**
     * Last write wins per (name, domain-or-url, path).
     *
     * A browser can hold the same cookie name on both the apex and a
     * subdomain; sending both is fine, but sending the same triple twice makes
     * the CDP batch ambiguous.
     *
     * @param  array<int, array<string, mixed>>  $cookies
     * @param  array<string, int>  $stats
     * @return array<int, array<string, mixed>>
     */
    protected static function dedupe(array $cookies, array &$stats): array
    {
        $byKey = [];
        foreach ($cookies as $c) {
            // partitionKey is part of the identity: the same name/domain/path
            // can exist once per CHIPS partition, and collapsing them would
            // drop one at random.
            $partition = isset($c['partitionKey']['topLevelSite'])
                ? (string) $c['partitionKey']['topLevelSite']
                : '';
            $key = strtolower(($c['name'] ?? '') . '|' . ($c['domain'] ?? $c['url'] ?? '') . '|' . ($c['path'] ?? '/') . '|' . $partition);
            if (isset($byKey[$key])) {
                $stats['deduped']++;
                $stats['kept']--;
            }
            $byKey[$key] = $c;
        }

        return array_values($byKey);
    }

    /**
     * Do these cookies actually carry a Yelp login?
     *
     * Verified against the real jar: `bse` (.yelp.com, httpOnly, secure) is the
     * primary biz auth cookie and `biz_session` (.biz.yelp.com) the dashboard
     * one; `s`/`bsd` cover the consumer session. Writing a jar without one of
     * them guarantees a failed automation run, so callers surface this instead
     * of discovering it 90 seconds into a Chromium launch.
     *
     * @param  array<int, array<string, mixed>>  $cookies
     */
    public static function looksAuthenticated(array $cookies): bool
    {
        $names = array_map(fn ($c) => strtolower((string) ($c['name'] ?? '')), $cookies);

        return (bool) array_intersect($names, ['bse', 'bsd', 's', 'biz_session']);
    }

    /**
     * Cookies in the jar that are already past their expiry.
     *
     * The jar is a point-in-time snapshot, and this is precisely how the
     * production session died: the file was captured 2026-06-01 and
     * `biz_session` expired 2026-07-25, after which every automation run was
     * faithfully injecting a dead cookie. Surfacing the count lets the admin
     * UI say "re-send from your browser" before anything fails.
     *
     * @param  array<int, array<string, mixed>>  $cookies
     * @return array<int, string>  Names of expired cookies.
     */
    public static function expiredNames(array $cookies): array
    {
        $now = time();
        $out = [];
        foreach ($cookies as $c) {
            $exp = $c['expires'] ?? $c['expirationDate'] ?? null;
            if (is_numeric($exp) && (int) $exp > 0 && (int) $exp <= $now) {
                $out[] = (string) ($c['name'] ?? '?');
            }
        }

        return $out;
    }

    /**
     * Write the jar atomically with 0600 permissions.
     *
     * Atomic because a queue job may be reading this file while an ingest
     * request rewrites it; a torn read is invalid JSON, which the Node side
     * swallows into a misleading "session expired".
     *
     * @param  array<int, array<string, mixed>>  $cookies
     */
    public static function write(array $cookies): void
    {
        $path = self::path();
        @mkdir(dirname($path), 0775, true);

        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, json_encode($cookies, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        chmod($tmp, 0600);
        rename($tmp, $path);
    }

    /** @return array<string, int> */
    protected static function emptyStats(): array
    {
        return [
            'received' => 0,
            'kept' => 0,
            'skipped_malformed' => 0,
            'skipped_foreign_domain' => 0,
            'session_only' => 0,
            'session_persisted' => 0,
            'samesite_downgraded' => 0,
            'host_only' => 0,
            'partitioned' => 0,
            'deduped' => 0,
        ];
    }
}
