<?php

namespace App\Support;

use App\Models\Site;

/**
 * Tenant-scoped paths for generated SEO artefacts.
 *
 * The ~40 seo:* commands write markdown reports to reports/{key}.md and JSON
 * to seo/{key}.json on the local disk. Those paths carry no tenant, so the
 * admin's SEO Reports page rendered gs.construction's crawl data, coverage
 * recommendations and competitor analysis inside every tenant's admin —
 * J. Peterson Design's client login included.
 *
 * The DB side was never affected: the models use BelongsToSite, so
 * GscCoverageState, GscDailyTotal and friends already return 0 rows for a
 * tenant with no data. Only the file-based half leaked.
 *
 * Mapping:
 *   default site (gsc)  ->  reports/audit.md          (unchanged)
 *   any other tenant    ->  tenants/{slug}/reports/audit.md
 *
 * Leaving the default site on the legacy paths is deliberate: gs.construction
 * has years of generated reports on disk and a scheduler that keeps writing
 * them, and renaming all of that to fix a leak that only affects OTHER tenants
 * would be a large, risky change for no gain.
 *
 * A tenant with nothing generated yet simply has no file, and the admin shows
 * its empty state — which is the truthful answer, and the one the leak was
 * hiding.
 */
class SeoStorage
{
    /**
     * Scope a generated-artefact path to the site currently bound.
     *
     * @param  string|null  $site  slug override; defaults to Site::current()
     */
    public static function path(string $relative, ?string $site = null): string
    {
        $slug = $site ?? Site::current()->slug;

        if ($slug === (string) config('sites.default', 'gsc')) {
            return $relative;
        }

        return 'tenants/' . $slug . '/' . ltrim($relative, '/');
    }
}
