<?php

namespace App\Support\Seo;

use App\Models\AreaServed;
use SsSystems\Platform\Seo\Contracts\SeoSiteProfile;

/**
 * What the kit's SEO tools (0.19.0, 2026-11-01) know about gs.construction,
 * read from the exact config/seo.php keys the site's own retired
 * TrackRankings/SeoKeywordResearch/SeoDomainOverview/SeoMapPackGrid/
 * SeoAiMentions commands used to read directly. Nothing here is cached —
 * every method reads config()/the database fresh, the same way the
 * commands it replaces always did, so a config change (or a new project in
 * a new town) takes effect on the very next run without a deploy.
 */
final class GscSeoProfile implements SeoSiteProfile
{
    public function domain(): string
    {
        return 'gs.construction';
    }

    public function brandName(): string
    {
        return (string) config('seo.site_name', 'GS Construction & Remodeling');
    }

    public function brandPatterns(): array
    {
        return array_values(array_filter(array_map(
            static fn ($p) => (string) $p,
            (array) config('seo.rank_tracker.identity_patterns', []),
        )));
    }

    public function competitorPatterns(): array
    {
        // config's shape is name => list<pattern> (one business, several
        // spellings) — flattened to the plain list of fragments every
        // ported tool (CompetitorMatch, KeywordClassifier, ...) expects.
        $groups = array_values((array) config('seo.rank_tracker.competitor_patterns', []));

        return $groups === [] ? [] : array_values(array_unique(array_merge(...$groups)));
    }

    public function competitorExclusions(): array
    {
        return array_values(array_filter(array_map(
            static fn ($d) => (string) $d,
            (array) config('seo.competitor_exclusions', []),
        )));
    }

    /** The HQ vantage every "(HQ)" entry in seo.rank_tracker.web_queries checks from — Arlington Heights, not the Prospect Heights office the map-pack grid is centered on. */
    public function locationName(): string
    {
        return 'Arlington Heights,Illinois,United States';
    }

    public function latitude(): ?float
    {
        $lat = config('seo.map_pack.center_lat');

        return $lat !== null ? (float) $lat : null;
    }

    public function longitude(): ?float
    {
        $lng = config('seo.map_pack.center_lng');

        return $lng !== null ? (float) $lng : null;
    }

    public function trackedQueries(): array
    {
        return array_map(static function (array $cfg): array {
            $query = ['q' => (string) ($cfg['q'] ?? '')];
            if (filled($cfg['location'] ?? null)) {
                $query['location'] = (string) $cfg['location'];
            }
            if (filled($cfg['city_slug'] ?? null)) {
                $query['city_slug'] = (string) $cfg['city_slug'];
            }

            return $query;
        }, (array) config('seo.rank_tracker.web_queries', []));
    }

    public function mapsQueries(): array
    {
        return array_values(array_filter(array_map(
            static fn ($k) => (string) $k,
            (array) config('seo.map_pack.keywords', []),
        )));
    }

    public function services(): array
    {
        return array_values(array_map(
            static fn ($s) => (string) $s,
            array_keys((array) config('seo.ai_mentions.services', [])),
        ));
    }

    /** The core towns (most completed local projects) when the config list is empty, same default seo:ai-mentions always used. */
    public function towns(): array
    {
        $configured = array_values(array_filter(array_map(
            static fn ($t) => (string) $t,
            (array) config('seo.ai_mentions.towns', []),
        )));

        return $configured !== [] ? $configured : AreaServed::coreTowns();
    }
}
