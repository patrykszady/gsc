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
            return self::isPriority($area);
        }

        return false;
    }
}
