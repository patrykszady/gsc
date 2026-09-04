<?php

namespace App\Support\SEO;

use App\Models\AreaServed;
use App\Models\Project;
use App\Models\Testimonial;
use Illuminate\Support\Facades\Cache;

/**
 * Single source of truth for which area-served pages Google should index.
 *
 * Context: the site publishes 87 cities × up to 11 page variants (~950 URLs).
 * Google's own coverage report shows ~186 of them "Crawled – currently not
 * indexed" and the ones that do surface earn ~0% CTR — a templated-sprawl
 * quality drag. This policy keeps the pages that carry genuine local proof
 * (a real project or review in that city) in the index and noindexes the thin,
 * near-duplicate spokes. Used by BOTH AreaPage (to emit the noindex meta) and
 * GenerateSitemap (to exclude the same URLs) so the two never disagree —
 * sitemapping a noindexed URL is a self-inflicted quality signal.
 */
class AreaSeoPolicy
{
    /** Sub-page variants that are pure navigational duplicates — never indexed. */
    public const THIN_PAGES = ['contact', 'about', 'services'];

    /** Spokes that only earn an index slot when the city has real local proof. */
    public const PROOF_GATED_PAGES = ['service', 'projects', 'testimonials'];

    /**
     * Lowercased city names that have genuine local proof: at least one published
     * project OR one visible testimonial located in that city.
     *
     * @return array<string,bool>
     */
    public static function priorityCities(): array
    {
        return Cache::remember('seo.area.priority_cities', 3600, function (): array {
            $token = static function (?string $location): ?string {
                $parts = preg_split('/[,.]/', (string) $location) ?: [];
                $t = mb_strtolower(trim((string) ($parts[0] ?? '')));

                return $t !== '' ? $t : null;
            };

            $cities = [];

            Project::query()
                ->where('is_published', true)
                ->whereNotNull('location')->where('location', '!=', '')
                ->pluck('location')
                ->each(function ($loc) use (&$cities, $token): void {
                    if ($c = $token($loc)) {
                        $cities[$c] = true;
                    }
                });

            Testimonial::query()
                ->where('is_hidden', false)
                ->whereNotNull('project_location')
                ->pluck('project_location')
                ->each(function ($loc) use (&$cities, $token): void {
                    if ($c = $token($loc)) {
                        $cities[$c] = true;
                    }
                });

            return $cities;
        });
    }

    /** Does this city have a real project or review to justify service/proof spokes? */
    public static function isPriority(AreaServed $area): bool
    {
        return isset(self::priorityCities()[mb_strtolower(trim((string) $area->city))]);
    }

    /**
     * Should this specific area page variant be indexed?
     *
     * @param string $page 'home'|'contact'|'about'|'services'|'projects'|'testimonials'|'service'
     */
    public static function shouldIndex(AreaServed $area, string $page = 'home', ?string $service = null): bool
    {
        // The area landing page is the canonical local page and carries the most
        // unique content (local_intro, landmarks, permit_notes) — always index it,
        // provided it actually has that unique copy.
        if ($page === 'home' || $page === '') {
            return $area->hasUniqueContent();
        }

        if (in_array($page, self::THIN_PAGES, true)) {
            return false;
        }

        if (in_array($page, self::PROOF_GATED_PAGES, true)) {
            if (self::isPriority($area)) {
                return true;
            }
            // Demand gate: a town's service page also earns its index slot when
            // Google already shows real demand for that town + service. The
            // pages exist with ~2,000 unique words each; keeping them noindexed
            // left the town hub ranking 8–15 for queries a dedicated page
            // answers (Kenilworth home remodeling: 3,445 impressions/28d,
            // Schaumburg kitchen: 2,120). Threshold in config/seo.php.
            if ($page === 'service' && $service !== null && $area->hasUniqueContent()) {
                return self::demandImpressions($area, $service) >= (int) config('seo.area_service_demand_impressions', 100);
            }

            return false;
        }

        return false;
    }

    /** Service slug => words a query must contain (besides the town) to count as demand for it. */
    public const DEMAND_KEYWORDS = [
        'kitchen-remodeling' => ['kitchen'],
        'bathroom-remodeling' => ['bathroom', 'bath '],
        'home-remodeling' => ['home remodel', 'home renovation', 'whole home', 'remodeling contractor', 'renovation services', 'renovation contractor'],
        'basement-remodeling' => ['basement'],
        'home-additions' => ['addition'],
    ];

    /**
     * Impressions (last 28 full days of Search Console data) for queries that
     * name this town and this service. Cached per site for 12 hours; the whole
     * query table is read once per cache miss and folded per town+service.
     */
    public static function demandImpressions(AreaServed $area, string $service): int
    {
        $table = self::demandTable();
        $key = mb_strtolower(trim((string) $area->city)) . '|' . $service;

        return (int) ($table[$key] ?? 0);
    }

    /** @return array<string,int> "city|service" => impressions */
    protected static function demandTable(): array
    {
        return Cache::remember(\App\Support\Tenancy::cacheKey('seo.area.service_demand'), 12 * 3600, function (): array {
            if (! \Illuminate\Support\Facades\Schema::hasTable('gsc_query_metrics')) {
                return [];
            }
            $end = now()->subDays(3);
            $start = $end->copy()->subDays(27);
            $rows = \App\Support\Tenancy::table('gsc_query_metrics')
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->groupBy('query')
                ->selectRaw('query, SUM(impressions) impressions')
                ->get();
            $cities = AreaServed::query()->pluck('city')->map(fn ($c) => mb_strtolower(trim((string) $c)))->filter()->unique()->all();

            $table = [];
            foreach ($rows as $r) {
                $q = ' ' . preg_replace('/\s+/', ' ', mb_strtolower(str_replace([',', '.'], ' ', (string) $r->query))) . ' ';
                foreach ($cities as $city) {
                    if (! str_contains($q, ' ' . $city . ' ')) {
                        continue;
                    }
                    foreach (self::DEMAND_KEYWORDS as $service => $words) {
                        foreach ($words as $w) {
                            if (str_contains($q, $w)) {
                                $table[$city . '|' . $service] = ($table[$city . '|' . $service] ?? 0) + (int) $r->impressions;
                                break;
                            }
                        }
                    }
                }
            }

            return $table;
        });
    }
}
