<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Http\Controllers\Api\Admin\V1\GscErrorController;
use App\Models\GscCoverageState;
use App\Models\Site;
use App\Support\Seo\CrawlFiles;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Tests\Feature\Seo\CoverageStoreTenantIsolationTest;
use Tests\TestCase;

/**
 * The two-Site isolation guard RULES.md requires before any gsc-scoped
 * model/query is touched by a kit port (CONSOLIDATION-PLAN.md §6): proves
 * `GscErrorController`'s own endpoints — not just the lower-level
 * `CoverageStore` contract {@see CoverageStoreTenantIsolationTest}
 * already covers — never read or write another tenant's `gsc_coverage_states`
 * rows, even when both tenants have a row at the exact same URL. Written
 * BEFORE porting this controller onto the kit's `ServesGscErrors` trait
 * (Seo\Http\Concerns\ServesGscErrors, coverageStateModel() hook) so it
 * pins the CURRENT behavior first and must still pass unmodified after.
 *
 * The real admin API always pins every HTTP request to the 'gsc' tenant
 * (see routes/api.php's `admin.api.tenant` => PinAdminApiTenant), so an
 * HTTP-level two-tenant test cannot flip tenants the way a real cross-site
 * leak would need to — this test instead calls the controller directly
 * inside `Tenancy::for()`, the same pattern
 * `Tests\Feature\Citations\CitationsBatchSyncIsolationTest` already uses
 * for exactly this reason.
 */
class GscErrorsTenantIsolationTest extends TestCase
{
    private function otherSite(): Site
    {
        return Site::where('slug', 'jpeterson')->firstOrFail();
    }

    private function controller(): GscErrorController
    {
        return app(GscErrorController::class);
    }

    public function test_prune_retired_under_one_tenant_never_deletes_the_others_identically_urled_row(): void
    {
        $gsc = Site::where('slug', 'gsc')->firstOrFail();
        $jp = $this->otherSite();

        Tenancy::for($gsc, function () {
            GscCoverageState::create(['url' => 'https://shared-path.example/page', 'source' => 'sitemap', 'verdict' => 'PASS', 'inspected_at' => now()]);
        });
        Tenancy::for($jp, function () {
            GscCoverageState::create(['url' => 'https://shared-path.example/page', 'source' => 'sitemap', 'verdict' => 'PASS', 'inspected_at' => now()]);
        });

        // gsc's own sitemap does not list this URL (a real, freshly-written
        // sitemap that simply omits it, not a missing-file no-op), so gsc's
        // prune should delete gsc's row — and gsc's row ONLY.
        Tenancy::for($gsc, function () use ($gsc) {
            $path = CrawlFiles::sitemapPath($gsc);
            @mkdir(dirname($path), 0775, true);
            file_put_contents($path, '<?xml version="1.0"?><urlset><url><loc>https://gs.construction/</loc></url></urlset>');
        });

        Tenancy::for($gsc, function () {
            $this->controller()->pruneRetired(new Request);
        });

        $this->assertSame(0, GscCoverageState::withoutSiteScope()->where('site_id', $gsc->id)->count(), "gsc's own retired row should be gone");
        $this->assertSame(1, GscCoverageState::withoutSiteScope()->where('site_id', $jp->id)->count(), "jpeterson's identically-urled row must survive gsc's prune");
    }

    public function test_stats_under_one_tenant_never_counts_the_others_rows(): void
    {
        $gsc = Site::where('slug', 'gsc')->firstOrFail();
        $jp = $this->otherSite();

        Tenancy::for($gsc, function () {
            GscCoverageState::create(['url' => 'https://gs.construction/a', 'source' => 'sitemap', 'verdict' => 'PASS', 'inspected_at' => now()]);
        });
        Tenancy::for($jp, function () {
            GscCoverageState::create(['url' => 'https://jpeterson-design.com/a', 'source' => 'sitemap', 'verdict' => 'FAIL', 'coverage_state' => 'Not indexed', 'inspected_at' => now()]);
            GscCoverageState::create(['url' => 'https://jpeterson-design.com/b', 'source' => 'sitemap', 'verdict' => 'FAIL', 'coverage_state' => 'Not indexed', 'inspected_at' => now()]);
        });

        $gscStats = Tenancy::for($gsc, function () {
            $response = $this->controller()->index(new Request);

            return $response->getData(true)['stats'];
        });

        $this->assertSame(1, $gscStats['tracked'], "gsc's stats must not include jpeterson's two rows");
    }

    public function test_index_under_one_tenant_never_lists_the_others_rows(): void
    {
        $gsc = Site::where('slug', 'gsc')->firstOrFail();
        $jp = $this->otherSite();

        Tenancy::for($gsc, function () {
            GscCoverageState::create(['url' => 'https://gs.construction/only-gsc', 'source' => 'sitemap', 'verdict' => 'FAIL', 'coverage_state' => 'Not indexed', 'inspected_at' => now()]);
        });
        Tenancy::for($jp, function () {
            GscCoverageState::create(['url' => 'https://jpeterson-design.com/only-jp', 'source' => 'sitemap', 'verdict' => 'FAIL', 'coverage_state' => 'Not indexed', 'inspected_at' => now()]);
        });

        $gscUrls = Tenancy::for($gsc, function () {
            $response = $this->controller()->index(new Request(['scope' => 'all']));

            return collect($response->getData(true)['data'])->pluck('url')->all();
        });

        $this->assertSame(['https://gs.construction/only-gsc'], $gscUrls);
    }
}
