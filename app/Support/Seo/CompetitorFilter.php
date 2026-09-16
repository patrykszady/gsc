<?php

namespace App\Support\Seo;

use Illuminate\Support\Str;

/**
 * The one place every SEO intel source decides "is this a competitor" — a
 * real local remodeling business, not a directory, a review site, a media
 * outlet, a big-box retailer or a giant SaaS platform that merely happens to
 * show up in the same search results, map pack, or keyword-overlap report.
 *
 * Three checks, in order:
 *  1. isKnownLocal() — the 26 owner-curated /compare companies
 *     (config/competitors.php). Human-verified ground truth; nothing below
 *     ever overrides it, so a curated competitor is never dropped by a
 *     metrics fluke or an over-broad exclusion entry.
 *  2. isAggregator() — an exact-host-or-subdomain match against the shared
 *     exclusion list (config('seo.competitor_exclusions')).
 *  3. isGiant() — an organic footprint so large it cannot be a local
 *     remodeling competitor (config('seo.competitor_giant_thresholds')).
 *     Only applied when metrics are actually available.
 *
 * Hosts merely *sourced* from map_pack_competitors or
 * competitor-discovery.json are candidates, not pre-vetted — they still run
 * through isAggregator()/isGiant() unless they also happen to be
 * known-local.
 */
final class CompetitorFilter
{
    /** Lowercase, strip a leading 'www.' — the shared normal form every check compares against. */
    public static function normalize(string $host): string
    {
        $host = mb_strtolower(trim($host));

        return preg_replace('/^www\./', '', $host) ?? $host;
    }

    /**
     * TRUE only for a host parsed from config('competitors.competitors')[*]['website']
     * — the 26 owner-curated /compare companies — matched exact-host-or-subdomain
     * (either direction, matching the original SeoDiscoverCompetitors behavior).
     * This is the one list that bypasses every other check.
     */
    public static function isKnownLocal(string $host): bool
    {
        $host = self::normalize($host);
        if ($host === '') {
            return false;
        }
        foreach (self::knownLocalHosts() as $known) {
            if ($known !== '' && ($host === $known || Str::endsWith($host, '.'.$known) || Str::endsWith($known, '.'.$host))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The normalized hosts behind config('competitors.competitors')[*]['website'].
     *
     * @return array<int, string>
     */
    public static function knownLocalHosts(): array
    {
        return collect(config('competitors.competitors', []))
            ->map(fn ($c) => self::normalize((string) parse_url((string) ($c['website'] ?? ''), PHP_URL_HOST)))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Exact-host-or-subdomain suffix match against
     * config('seo.competitor_exclusions') — directories, marketplaces,
     * review sites, social platforms, big-box retailers, media and B2B SaaS:
     * never a competing remodeling business, however they show up in a SERP,
     * a map-pack "website" field, or a Labs domain list. Suffix-safe, not a
     * naive substring match, so a host like "changingspacesremodeling.com"
     * is never caught by an exclusion entry such as "angi.com".
     */
    public static function isAggregator(string $host): bool
    {
        $host = self::normalize($host);
        if ($host === '') {
            return false;
        }
        foreach ((array) config('seo.competitor_exclusions', []) as $exclusion) {
            $exclusion = self::normalize((string) $exclusion);
            if ($exclusion !== '' && ($host === $exclusion || Str::endsWith($host, '.'.$exclusion))) {
                return true;
            }
        }

        return false;
    }

    /**
     * TRUE when the organic footprint is so large it cannot be a local
     * remodeling competitor — a national directory or a B2B platform that
     * slipped past the static exclusion list. Null metrics (not available at
     * this call site) never count toward "giant": we can't judge what we
     * don't have, so missing data never drops a host on this check alone.
     */
    public static function isGiant(?float $organicCount, ?float $organicEtv): bool
    {
        $countLimit = (float) config('seo.competitor_giant_thresholds.organic_count', 20000);
        $etvLimit = (float) config('seo.competitor_giant_thresholds.organic_etv', 50000);

        return ($organicCount !== null && $organicCount > $countLimit)
            || ($organicEtv !== null && $organicEtv > $etvLimit);
    }

    /**
     * The one method call sites use: known-local always wins; otherwise an
     * aggregator is never a competitor, and — when metrics are available —
     * neither is a giant.
     */
    public static function isCompetitor(string $host, ?float $organicCount = null, ?float $organicEtv = null): bool
    {
        $host = self::normalize($host);

        return self::isKnownLocal($host) || (! self::isAggregator($host) && ! self::isGiant($organicCount, $organicEtv));
    }

    /**
     * Normalize + dedupe + filter a plain host list (no metrics) through
     * isCompetitor() — for the map-pack/discovery sourcing sites that only
     * ever have bare hostnames.
     *
     * @param  iterable<string>  $hosts
     * @return array<int, string>
     */
    public static function keep(iterable $hosts): array
    {
        $out = [];
        foreach ($hosts as $host) {
            $host = self::normalize((string) $host);
            if ($host === '' || isset($out[$host])) {
                continue;
            }
            if (self::isCompetitor($host)) {
                $out[$host] = true;
            }
        }

        return array_keys($out);
    }
}
