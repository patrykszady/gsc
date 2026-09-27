<?php

namespace Tests\Feature\Seo;

use App\Models\GscCoverageState;
use App\Models\GscRichResultIssue;
use App\Models\Site;
use App\Support\Tenancy;
use SsSystems\Platform\Seo\Inspection\Contracts\CoverageStore;
use Tests\TestCase;

/**
 * AppServiceProvider binds CoverageStoreContract to the kit's
 * EloquentCoverageStore, parameterized with THIS site's own
 * GscCoverageState/GscRichResultIssue/GscCoverageStateHistory model
 * classes — the kit never references App\Models\* directly (see
 * ss-platform-kit's CLAUDE.md/RULES.md). Tenant safety therefore rides
 * entirely on those models' own BelongsToSite global scope, not on
 * anything the kit class does — this test proves that seam actually
 * holds for every CoverageStore method a real sweep exercises, the same
 * two-Site isolation guard every gsc-scoped kit adoption requires before
 * shipping (RULES.md's tenancy guard).
 */
class CoverageStoreTenantIsolationTest extends TestCase
{
    private function otherSite(): Site
    {
        $site = Site::query()->firstOrCreate(['slug' => 'jpeterson'], [
            'name' => 'J. Peterson Design', 'theme' => 'jpeterson', 'hosts' => ['jpeterson-design.com'], 'primary_host' => 'jpeterson-design.com',
        ]);
        $site->forceFill(['is_active' => true, 'hosts' => ['jpeterson-design.com'], 'primary_host' => 'jpeterson-design.com'])->save();
        Site::forgetActive();

        return $site->fresh();
    }

    public function test_one_tenants_coverage_rows_are_invisible_to_the_other(): void
    {
        $other = $this->otherSite();
        $store = app(CoverageStore::class);

        $store->upsert([
            'url' => 'https://gs.construction/projects',
            'source' => 'sitemap',
            'console_reason' => null,
            'verdict' => 'PASS',
            'coverage_state' => 'Submitted and indexed',
            'robots_txt_state' => null,
            'indexing_state' => null,
            'page_fetch_state' => null,
            'sitemap_url' => null,
            'last_crawl_time' => null,
            'user_canonical' => null,
            'google_canonical' => null,
            'inspected_at' => now(),
            'last_changed_at' => now(),
            'consecutive_failures' => 0,
        ]);
        $store->replaceRichResultIssues('https://gs.construction/projects', [[
            'rich_result_type' => 'Review snippet',
            'issue_severity' => 'ERROR',
            'issue_type' => 'MISSING_FIELD',
            'issue_message' => 'Missing field "name"',
            'verdict' => 'FAIL',
            'inspected_at' => now()->toIso8601String(),
        ]]);

        Tenancy::for($other, function () {
            $otherStore = app(CoverageStore::class);

            $this->assertSame([], $otherStore->knownUrls(), 'another tenant sees no coverage rows at all');
            $this->assertNull($otherStore->find('https://gs.construction/projects'));
            $this->assertSame([], $otherStore->knownUrlsAmong(['https://gs.construction/projects']));
            $this->assertSame([], $otherStore->verdictCoverageTotals());

            $otherStore->upsert([
                'url' => 'https://jpeterson-design.com/portfolio',
                'source' => 'sitemap',
                'console_reason' => null,
                'verdict' => 'PASS',
                'coverage_state' => 'Submitted and indexed',
                'robots_txt_state' => null,
                'indexing_state' => null,
                'page_fetch_state' => null,
                'sitemap_url' => null,
                'last_crawl_time' => null,
                'user_canonical' => null,
                'google_canonical' => null,
                'inspected_at' => now(),
                'last_changed_at' => now(),
                'consecutive_failures' => 0,
            ]);

            $this->assertSame(['https://jpeterson-design.com/portfolio'], $otherStore->knownUrls());
        });

        // Back on the default tenant: the other site's write did not leak here either.
        $this->assertSame(['https://gs.construction/projects'], $store->knownUrls());
        $this->assertNotNull($store->find('https://gs.construction/projects'));

        // The raw tables prove the rows are truly both present, correctly
        // stamped, and simply invisible to each other through Eloquent.
        $this->assertSame(2, GscCoverageState::withoutSiteScope()->count());
        $this->assertSame(1, GscRichResultIssue::withoutSiteScope()->count());
        $this->assertSame(
            $this->siteId('gsc'),
            GscCoverageState::withoutSiteScope()->where('url', 'https://gs.construction/projects')->value('site_id')
        );
        $this->assertSame(
            $other->id,
            GscCoverageState::withoutSiteScope()->where('url', 'https://jpeterson-design.com/portfolio')->value('site_id')
        );
    }

    private function siteId(string $slug): int
    {
        return Site::query()->where('slug', $slug)->value('id')
            ?? Site::current()->id;
    }
}
