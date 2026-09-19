<?php

namespace Tests\Feature\Seo;

use App\Models\ClarityDailyMetric;
use App\Models\ClarityPageMetric;
use App\Services\MicrosoftClarityService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The per-page half of the Clarity sync, built against the real response
 * shape (captured from production 2026-09-19): the dimension key comes back
 * as "Url", Traffic lists every host that carries the tag — local dev
 * included — and bots are counted inside the session total.
 */
class ClarityPageSyncTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.microsoft.clarity.project_id' => 'proj-test',
            'services.microsoft.clarity.api_token' => 'token-test',
            'services.microsoft.clarity.base_url' => 'https://clarity.test/export-data/api/v1',
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function urlPayload(): array
    {
        $row = fn (?string $url, array $extra) => $extra + ['Url' => $url];

        return [
            ['metricName' => 'Traffic', 'information' => [
                $row(null, ['totalSessionCount' => 0, 'totalBotSessionCount' => 0, 'distinctUserCount' => 2]),
                $row('http://127.0.0.1:8003/', ['totalSessionCount' => 2, 'totalBotSessionCount' => 0]),
                $row('https://gs.construction/', ['totalSessionCount' => 30, 'totalBotSessionCount' => 5]),
                $row('https://www.gs.construction/kitchens/', ['totalSessionCount' => 12, 'totalBotSessionCount' => 0]),
                $row('https://gs.construction/kitchens', ['totalSessionCount' => 8, 'totalBotSessionCount' => 0]),
                $row('https://gs.construction/never-visited', ['totalSessionCount' => 0, 'totalBotSessionCount' => 0]),
            ]],
            ['metricName' => 'RageClickCount', 'information' => [
                $row('https://gs.construction/', ['sessionsCount' => 25, 'subTotal' => 1]),
                $row('https://www.gs.construction/kitchens/', ['sessionsCount' => 12, 'subTotal' => 3]),
                $row('https://gs.construction/kitchens', ['sessionsCount' => 8, 'subTotal' => 2]),
                $row('http://127.0.0.1:8003/', ['sessionsCount' => 2, 'subTotal' => 9]),
            ]],
            ['metricName' => 'DeadClickCount', 'information' => [
                $row('https://gs.construction/kitchens', ['sessionsCount' => 8, 'subTotal' => 4]),
            ]],
            ['metricName' => 'QuickbackClick', 'information' => [
                $row('https://gs.construction/', ['sessionsCount' => 25, 'subTotal' => 2]),
            ]],
            ['metricName' => 'ScriptErrorCount', 'information' => [
                $row('https://gs.construction/kitchens', ['sessionsCount' => 8, 'subTotal' => 1]),
            ]],
            ['metricName' => 'ScrollDepth', 'information' => [
                $row('https://gs.construction/', ['averageScrollDepth' => 55.5]),
                $row('https://www.gs.construction/kitchens/', ['averageScrollDepth' => 40]),
                $row('https://gs.construction/kitchens', ['averageScrollDepth' => 60]),
            ]],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function osPayload(): array
    {
        return [
            ['metricName' => 'Traffic', 'information' => [
                ['totalSessionCount' => 40, 'totalBotSessionCount' => 3, 'distinctUserCount' => 30, 'pagesPerSessionPercentage' => 1.5, 'OS' => 'Windows'],
            ]],
        ];
    }

    private function fakeClarity(): void
    {
        Http::fake([
            'clarity.test/*' => fn (Request $request) => Http::response(
                ($request['dimension1'] ?? '') === 'URL' ? $this->urlPayload() : $this->osPayload()
            ),
        ]);
    }

    public function test_it_keeps_only_this_sites_hosts_and_folds_url_variants_into_one_path(): void
    {
        $this->fakeClarity();

        $rows = collect(app(MicrosoftClarityService::class)->fetchPageMetrics())->keyBy('path');

        // Local dev, the null row and the never-visited page are all gone.
        $this->assertSame(['/', '/kitchens'], $rows->keys()->sort()->values()->all());

        $home = $rows['/'];
        $this->assertSame(25, $home['sessions'], 'bots are counted inside Clarity\'s total and must come out');
        $this->assertSame(1, $home['rage_clicks']);
        $this->assertSame(2, $home['quickbacks']);
        $this->assertSame(55.5, $home['scroll_depth']);

        // www + trailing slash + bare are one page.
        $kitchens = $rows['/kitchens'];
        $this->assertSame(20, $kitchens['sessions']);
        $this->assertSame(5, $kitchens['rage_clicks']);
        $this->assertSame(4, $kitchens['dead_clicks']);
        $this->assertSame(1, $kitchens['script_errors']);
        $this->assertSame(50.0, $kitchens['scroll_depth']);
        $this->assertSame(now()->toDateString(), $kitchens['date']);
    }

    public function test_the_sync_writes_both_tables_and_is_idempotent(): void
    {
        $this->fakeClarity();

        $this->artisan('seo:clarity-sync', ['--days' => 1])
            ->expectsOutputToContain('Upserted 2 per-page rows.')
            ->assertSuccessful();

        $this->assertSame(1, ClarityDailyMetric::count());
        $this->assertSame(2, ClarityPageMetric::count());

        $kitchens = ClarityPageMetric::where('path', '/kitchens')->firstOrFail();
        $this->assertSame(ClarityPageMetric::hashPath('/kitchens'), $kitchens->path_hash);
        $this->assertSame('proj-test', $kitchens->project_id);
        $this->assertNotNull($kitchens->site_id, 'rows are tenant-scoped like every other SEO table');

        // A second run the same day updates in place.
        $this->artisan('seo:clarity-sync', ['--days' => 1])->assertSuccessful();
        $this->assertSame(2, ClarityPageMetric::count());
    }

    public function test_no_pages_skips_the_second_request_entirely(): void
    {
        $this->fakeClarity();

        $this->artisan('seo:clarity-sync', ['--days' => 1, '--no-pages' => true])->assertSuccessful();

        $this->assertSame(1, ClarityDailyMetric::count());
        $this->assertSame(0, ClarityPageMetric::count());
        Http::assertSentCount(1);
    }

    public function test_a_failed_page_request_does_not_fail_the_run(): void
    {
        Http::fake([
            'clarity.test/*' => fn (Request $request) => ($request['dimension1'] ?? '') === 'URL'
                ? Http::response('rate limited', 429)
                : Http::response($this->osPayload()),
        ]);

        $this->artisan('seo:clarity-sync', ['--days' => 1])
            ->expectsOutputToContain('site-wide rows were still written')
            ->assertSuccessful();

        $this->assertSame(1, ClarityDailyMetric::count());
        $this->assertSame(0, ClarityPageMetric::count());
    }
}
