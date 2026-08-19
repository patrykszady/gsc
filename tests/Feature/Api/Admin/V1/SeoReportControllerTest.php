<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Support\Tenancy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

class SeoReportControllerTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
    }

    public function test_reports_index_lists_the_configured_registry(): void
    {
        $response = $this->getJson('/api/admin/v1/seo/reports', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($response['reports']);
        $this->assertSame(count(config('seo-reports.reports')), $response['stats']['total']);
        $this->assertArrayHasKey('key', $response['reports'][0]);
        $this->assertArrayHasKey('freshness_pct', $response['reports'][0]);
    }

    public function test_show_404s_for_an_unknown_report_key(): void
    {
        $this->getJson('/api/admin/v1/seo/reports/not-a-real-report', $this->adminApiHeaders())
            ->assertNotFound();
    }

    public function test_show_returns_the_placeholder_when_the_file_was_never_generated(): void
    {
        // Isolate from whatever this dev box's real storage/app/reports
        // happens to contain — the registry key exists, the file must not.
        Storage::fake('local');

        $key = array_key_first(config('seo-reports.reports'));

        $data = $this->getJson("/api/admin/v1/seo/reports/{$key}", $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame($key, $data['key']);
        $this->assertFalse($data['exists']);
        $this->assertSame('missing', $data['status']);
        $this->assertStringContainsString('Run now', $data['html']);
    }

    public function test_regenerate_404s_for_an_unknown_report_key(): void
    {
        // Blanket-fake all outbound HTTP so nothing this triggers (some
        // report commands call external APIs) can ever leave the box.
        Http::fake();

        $this->postJson('/api/admin/v1/seo/reports/not-a-real-report/regenerate', [], $this->adminApiHeaders())
            ->assertNotFound();
    }

    public function test_regenerate_runs_the_commands_artisan_command_and_returns_the_refreshed_report(): void
    {
        // seo:health does purely local reads (no Http:: calls) — confirmed
        // by code inspection — but this blanket-fakes HTTP anyway as a
        // second line of defense for every report command, not just this one.
        Http::fake();
        Storage::fake('local');
        Cache::put(Tenancy::cacheKey('admin.seo-reports.health-snapshot'), ['score' => 999, 'pillars' => []], 60);

        $data = $this->postJson('/api/admin/v1/seo/reports/health/regenerate', [], $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame('health', $data['key']);
        // The command itself may succeed or fail depending on how much GSC
        // data this environment has (e.g. seo:health can divide by data this
        // empty sqlite test db doesn't have) — either way the controller's
        // try/catch (ported verbatim from the Livewire original) must turn
        // that into a friendly message, never an unhandled 500.
        $this->assertMatchesRegularExpression('/regenerated\.$|^Failed to regenerate: /', $data['message']);
        // regenerate() busts the health-snapshot cache same as the Livewire
        // original — the stale, manually-seeded score of 999 must be gone
        // either way, since the cache is forgotten before the command runs.
        $this->assertNotSame(999, $this->getJson('/api/admin/v1/seo/snapshot', $this->adminApiHeaders())->json('data.health.score'));
    }

    public function test_snapshot_returns_the_full_payload_shape_with_no_data_seeded(): void
    {
        $data = $this->getJson('/api/admin/v1/seo/snapshot', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        foreach (['health', 'report_stats', 'diagnostic', 'search', 'trend', 'top_queries', 'top_pages', 'clarity', 'geo', 'ai_traffic', 'gsc_errors'] as $key) {
            $this->assertArrayHasKey($key, $data, "snapshot payload missing \"{$key}\"");
        }

        $this->assertFalse($data['diagnostic']['available']);
        $this->assertSame([], $data['top_queries']);
        $this->assertSame([], $data['top_pages']);
        $this->assertArrayHasKey('channels', $data['search']);
        $this->assertArrayHasKey('gsc', $data['search']['channels']);
    }

    public function test_snapshot_accepts_trend_and_top_controls(): void
    {
        $data = $this->getJson('/api/admin/v1/seo/snapshot?trend_days=30&trend_metric=impressions&top_days=90&top_queries_sort=impressions&top_queries_dir=asc', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        // 30 days of trend rows, one per day.
        $this->assertCount(30, $data['trend']);
    }

    public function test_snapshot_refresh_busts_the_cached_health_and_search_snapshots(): void
    {
        Cache::put(Tenancy::cacheKey('admin.seo-reports.health-snapshot'), ['score' => 999, 'pillars' => []], 60);

        $data = $this->postJson('/api/admin/v1/seo/snapshot/refresh', [], $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame('Dashboard metrics refreshed.', $data['message']);
        // The stale, manually-seeded score of 999 must be gone.
        $this->assertNotSame(999, $data['health']['score']);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/admin/v1/seo/reports')->assertUnauthorized();
    }
}
