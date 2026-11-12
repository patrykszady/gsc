<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\GscCoverageState;
use App\Models\Site;
use App\Support\Tenancy;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use SsSystems\Platform\Seo\Snapshot\SeoSnapshotStore;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

/**
 * The stored SEO snapshot and seo/summary (kit 0.26.0, 2026-11-11): GET
 * seo/snapshot answers from the copy seo:snapshot-store built for the gsc
 * tenant (every 15 minutes and after each Search Console sync),
 * `computed_at` saying when; `?fresh=1` builds it again; seo/summary hands
 * ss.systems' platform dashboard the health score and trouble counts
 * without building the snapshot (it was 4.7 s at the median on production).
 */
class SeoSnapshotStoreTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithAdminApiAuth;

    private const SCREEN = ['trend_days' => 60, 'top_days' => 28, 'clarity_days' => 7, 'rank_days' => 7];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
        Cache::flush();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function problemPage(string $path): void
    {
        GscCoverageState::create([
            'url' => 'https://gs.construction'.$path, 'source' => 'sitemap', 'verdict' => 'FAIL',
            'coverage_state' => 'Crawled - currently not indexed', 'inspected_at' => now(),
        ]);
    }

    private function store(): SeoSnapshotStore
    {
        return Tenancy::for(Site::query()->where('slug', 'gsc')->firstOrFail(), fn (): SeoSnapshotStore => app(SeoSnapshotStore::class));
    }

    public function test_the_snapshot_is_served_from_the_stored_copy_until_fresh_builds_it_again(): void
    {
        $url = '/api/admin/v1/seo/snapshot?'.http_build_query(self::SCREEN);

        Carbon::setTestNow('2026-11-11 10:00:00');
        $first = $this->getJson($url, $this->adminApiHeaders())->assertOk()->json('data');
        $this->assertSame('2026-11-11T10:00:00+00:00', $first['computed_at']);
        $this->assertSame(0, $first['gsc_errors']['totals']['problem']);

        $this->problemPage('/kitchens');
        Carbon::setTestNow('2026-11-11 10:05:00');

        $stored = $this->getJson($url, $this->adminApiHeaders())->assertOk()->json('data');
        $this->assertSame('2026-11-11T10:00:00+00:00', $stored['computed_at']);
        $this->assertSame(0, $stored['gsc_errors']['totals']['problem']);

        $fresh = $this->getJson($url.'&fresh=1', $this->adminApiHeaders())->assertOk()->json('data');
        $this->assertSame('2026-11-11T10:05:00+00:00', $fresh['computed_at']);
        $this->assertSame(1, $fresh['gsc_errors']['totals']['problem']);

        $this->assertSame($fresh, $this->getJson($url, $this->adminApiHeaders())->json('data'));
    }

    public function test_the_refresh_button_rebuilds_and_stores_the_screens_snapshot(): void
    {
        $url = '/api/admin/v1/seo/snapshot?'.http_build_query(self::SCREEN);
        $this->getJson($url, $this->adminApiHeaders())->assertOk();
        $this->problemPage('/kitchens');

        $refreshed = $this->postJson('/api/admin/v1/seo/snapshot/refresh', ['trend_days' => 60], $this->adminApiHeaders())->assertOk()->json('data');

        $this->assertTrue($refreshed['ok']);
        $this->assertSame(1, $refreshed['gsc_errors']['totals']['problem']);
        $this->assertSame(1, $this->getJson($url, $this->adminApiHeaders())->json('data.gsc_errors.totals.problem'));
    }

    public function test_a_query_the_hub_does_not_open_with_is_built_on_every_request(): void
    {
        $this->getJson('/api/admin/v1/seo/snapshot?top_queries_sort=impressions', $this->adminApiHeaders())->assertOk();
        $this->problemPage('/kitchens');

        $data = $this->getJson('/api/admin/v1/seo/snapshot?top_queries_sort=impressions', $this->adminApiHeaders())->assertOk()->json('data');

        $this->assertSame(1, $data['gsc_errors']['totals']['problem']);
        $this->assertArrayHasKey('computed_at', $data);
        $this->assertNull($this->store()->get([]));
    }

    /**
     * Through tenants:run --site=gsc, as the schedule runs it, and with
     * Artisan::call rather than $this->artisan(): the harness's mocked
     * output would also swallow the `seo:health --json` the health block
     * runs inside it.
     */
    public function test_the_store_command_builds_both_combinations_the_hub_asks_for_and_the_api_serves_them(): void
    {
        Carbon::setTestNow('2026-11-11 10:15:00');

        $this->assertSame(0, Artisan::call('tenants:run', ['cmd' => 'seo:snapshot-store', '--site' => ['gsc']]));

        $this->assertSame('2026-11-11T10:15:00+00:00', $this->store()->get(self::SCREEN)['computed_at']);
        $this->assertSame('2026-11-11T10:15:00+00:00', $this->store()->get([])['computed_at']);
        $this->assertArrayHasKey('pillars', $this->store()->get([])['health']);

        $this->problemPage('/kitchens');
        $served = $this->getJson('/api/admin/v1/seo/snapshot', $this->adminApiHeaders())->assertOk()->json('data');
        $this->assertSame('2026-11-11T10:15:00+00:00', $served['computed_at']);
        $this->assertSame(0, $served['gsc_errors']['totals']['problem']);
    }

    public function test_it_is_scheduled_every_fifteen_minutes_for_the_gsc_tenant(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'seo:snapshot-store'));

        $this->assertNotNull($event);
        $this->assertStringContainsString('tenants:run seo:snapshot-store --site=gsc', (string) $event->command);
        $this->assertSame('*/15 * * * *', $event->expression);
    }

    public function test_the_summary_carries_the_dashboard_cards_figures_without_building_the_snapshot(): void
    {
        $this->problemPage('/kitchens');
        GscCoverageState::create([
            'url' => 'https://gs.construction/', 'source' => 'sitemap', 'verdict' => 'PASS',
            'coverage_state' => 'Submitted and indexed', 'inspected_at' => now(),
        ]);

        $data = $this->getJson('/api/admin/v1/seo/summary?prior_days=28', $this->adminApiHeaders())->assertOk()->json('data');

        $this->assertSame(['health', 'gsc_errors', 'computed_at'], array_keys($data));
        $this->assertSame(['available' => true, 'totals' => ['tracked' => 2, 'problem' => 1, 'pass' => 1, 'not_indexed' => 1]], $data['gsc_errors']);
        $this->assertSame(['score', 'prior_score', 'prior_as_of', 'pillars'], array_keys($data['health']));
        $this->assertNull($this->store()->get([]), 'the summary never builds or stores the snapshot');
    }

    public function test_the_summary_counts_the_gsc_tenants_pages_only(): void
    {
        $this->problemPage('/kitchens');
        Tenancy::for(Site::query()->where('slug', 'jpeterson')->firstOrFail(), function (): void {
            GscCoverageState::create([
                'url' => 'https://jpeterson-design.com/services', 'source' => 'sitemap', 'verdict' => 'FAIL',
                'coverage_state' => 'Crawled - currently not indexed', 'inspected_at' => now(),
            ]);
        });

        $totals = $this->getJson('/api/admin/v1/seo/summary', $this->adminApiHeaders())->assertOk()->json('data.gsc_errors.totals');

        $this->assertSame(1, $totals['tracked']);
        $this->assertSame(1, $totals['problem']);
    }

    public function test_the_summary_reads_the_health_score_from_the_newest_stored_snapshot(): void
    {
        Carbon::setTestNow('2026-11-11 09:00:00');
        $pillars = [['name' => 'On-page completeness', 'score' => 80, 'color' => 'emerald'], ['name' => 'Freshness', 'score' => 70, 'color' => 'amber'], ['name' => 'Local rankings', 'score' => 60, 'color' => 'amber']];
        $this->store()->put(self::SCREEN, ['health' => ['score' => 70, 'prior_score' => 66, 'prior_as_of' => '2026-11-04', 'pillars' => $pillars]]);

        Carbon::setTestNow('2026-11-11 09:10:00');
        $data = $this->getJson('/api/admin/v1/seo/summary?prior_days=28', $this->adminApiHeaders())->assertOk()->json('data');

        // This site's prior score is the one closest to a week back whatever
        // window is asked, the same as its snapshot's.
        $this->assertSame(['score' => 70, 'prior_score' => 66, 'prior_as_of' => '2026-11-04', 'pillars' => $pillars], $data['health']);
        $this->assertSame('2026-11-11T09:00:00+00:00', $data['computed_at']);
    }

    public function test_the_summary_refuses_an_unauthenticated_request(): void
    {
        $this->getJson('/api/admin/v1/seo/summary')->assertUnauthorized();
    }
}
