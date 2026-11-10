<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Http\Controllers\Api\Admin\V1\BingCoverageController;
use App\Jobs\RunBingCoverageJob;
use App\Models\BingUrlInfo;
use App\Models\Site;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The two-Site isolation guard every kit-scoped table needs (see
 * GscErrorsTenantIsolationTest's own docblock): proves
 * BingCoverageController's index() never reads another tenant's
 * bing_url_info rows, even when both tenants have a row at the exact same
 * URL. The real admin API always pins every request to the 'gsc' tenant
 * (routes/api.php's admin.api.tenant), so — same as
 * GscErrorsTenantIsolationTest — this calls the controller directly
 * inside Tenancy::for() rather than over HTTP.
 */
class BingCoverageTenantIsolationTest extends TestCase
{
    private function otherSite(): Site
    {
        return Site::where('slug', 'jpeterson')->firstOrFail();
    }

    private function controller(): BingCoverageController
    {
        return app(BingCoverageController::class);
    }

    public function test_index_under_one_tenant_never_counts_or_lists_the_others_identically_urled_row(): void
    {
        $gsc = Site::where('slug', 'gsc')->firstOrFail();
        $jp = $this->otherSite();

        Tenancy::for($gsc, function () {
            BingUrlInfo::create(['url' => 'https://shared-path.example/page', 'url_hash' => sha1('https://shared-path.example/page'), 'in_sitemap' => true]);
        });
        Tenancy::for($jp, function () {
            BingUrlInfo::create(['url' => 'https://shared-path.example/page', 'url_hash' => sha1('https://shared-path.example/page'), 'in_sitemap' => true]);
            BingUrlInfo::create(['url' => 'https://jpeterson-design.com/only-jp', 'url_hash' => sha1('https://jpeterson-design.com/only-jp'), 'in_sitemap' => true]);
        });

        $gscData = Tenancy::for($gsc, function () {
            $response = $this->controller()->index(new Request);

            return $response->getData(true)['data'];
        });

        $this->assertSame(1, $gscData['urls']['total'], "gsc's count must not include jpeterson's two rows");
        $this->assertSame(['https://shared-path.example/page'], collect($gscData['rows'])->pluck('url')->all());

        // Sanity: both rows really exist, correctly stamped, once the scope is dropped.
        $this->assertSame(1, BingUrlInfo::withoutSiteScope()->where('site_id', $gsc->id)->count());
        $this->assertSame(2, BingUrlInfo::withoutSiteScope()->where('site_id', $jp->id)->count());
    }

    public function test_refresh_under_one_tenant_only_ever_dispatches_for_that_tenants_id(): void
    {
        Queue::fake();

        $gsc = Site::where('slug', 'gsc')->firstOrFail();
        $jp = $this->otherSite();

        Tenancy::for($jp, function () {
            $this->controller()->refresh(new Request);
        });

        Queue::assertPushed(RunBingCoverageJob::class, fn (RunBingCoverageJob $job) => $job->siteId === $jp->id);
        Queue::assertNotPushed(RunBingCoverageJob::class, fn (RunBingCoverageJob $job) => $job->siteId === $gsc->id);
    }
}
