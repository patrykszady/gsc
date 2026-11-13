<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\BingDailyTotal;
use App\Models\GscDailyTotal;
use App\Models\Site;
use App\Support\Tenancy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use SsSystems\Platform\Seo\Status\Heartbeat;
use SsSystems\Platform\Seo\Status\SchedulerBeat;
use SsSystems\Platform\Seo\Status\SeoStatusController;
use SsSystems\Platform\Seo\Status\SeoStatusServiceProvider;
use SsSystems\Platform\Seo\Status\SourceRegistry;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

/**
 * GET seo/status and the `status` block of seo/summary (kit 0.28.0,
 * 2026-11-12) on this multi-tenant site: each tenant is told about its own
 * collectors and its own data only, and the scheduler's beat is everyone's.
 *
 * The kit routes the endpoint itself, beside seo/summary and behind the
 * same middleware, from a service provider Laravel discovers once the kit
 * version carrying it is installed; registering it here is what discovery
 * does, and a no-op once it has. The admin API pins every request to the
 * 'gsc' tenant (admin.api.tenant), so — like BingCoverageTenantIsolationTest
 * — the other tenant's answer is taken from the kit's controller inside
 * Tenancy::for() rather than over HTTP.
 */
class SeoStatusTest extends TestCase
{
    use WithAdminApiAuth;

    private const URL = '/api/admin/v1/seo/status';

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAdminApiAuth();
        $this->app->register(SeoStatusServiceProvider::class);
        Heartbeat::reset();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Heartbeat::reset();
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function site(string $slug): Site
    {
        return Site::query()->where('slug', $slug)->firstOrFail();
    }

    /** @return array<string, mixed> one source's row from a seo/status payload */
    private function source(array $data, string $key): array
    {
        return collect($data['sources'])->firstWhere('key', $key);
    }

    /** @return array<string, mixed> what the gsc tenant is told, over HTTP as ss.systems asks */
    private function gscStatus(): array
    {
        return $this->getJson(self::URL, $this->adminApiHeaders())->assertOk()->json('data');
    }

    /** @return array<string, mixed> what another tenant would be told by the same controller */
    private function statusFor(Site $site): array
    {
        return Tenancy::for($site, fn (): array => app(SeoStatusController::class)->status()->getData(true)['data']);
    }

    public function test_status_for_each_tenant_answers_with_its_own_rows_only(): void
    {
        Carbon::setTestNow('2026-11-12 09:00:00');
        $jpeterson = $this->site('jpeterson');

        Tenancy::for($this->site('gsc'), fn () => Heartbeat::succeeded('snapshot'));
        Tenancy::for($jpeterson, function (): void {
            Heartbeat::failed('snapshot', 'The J. Peterson snapshot could not be built.');
            Heartbeat::succeeded('reports');
        });

        Carbon::setTestNow('2026-11-12 09:05:00');
        $this->getJson(self::URL)->assertUnauthorized();
        $mine = $this->gscStatus();
        $theirs = $this->statusFor($jpeterson);

        $this->assertSame(SourceRegistry::keys(), array_column($mine['sources'], 'key'));

        $snapshot = $this->source($mine, 'snapshot');
        $this->assertSame('fresh', $snapshot['state']);
        $this->assertSame('ok', $snapshot['last_status']);
        $this->assertSame('2026-11-12T09:00:00+00:00', $snapshot['last_success_at']);
        $this->assertSame(0, $snapshot['consecutive_failures']);
        $this->assertNull($snapshot['last_error']);
        $this->assertSame('never_ran', $this->source($mine, 'reports')['state'], "the other tenant's run must not show here");

        $snapshot = $this->source($theirs, 'snapshot');
        $this->assertSame('failing', $snapshot['state']);
        $this->assertSame('The J. Peterson snapshot could not be built.', $snapshot['last_error']);
        $this->assertNull($snapshot['last_success_at'], "this tenant's success must not show there");
        $this->assertSame('fresh', $this->source($theirs, 'reports')['state']);
    }

    public function test_reading_the_status_never_makes_an_indexnow_key_for_a_tenant_that_has_none(): void
    {
        config(['services.indexnow.key' => null]);
        $jpeterson = $this->site('jpeterson');
        $keys = fn (): int => \App\Models\PlatformSetting::withoutGlobalScopes()->where('key', \App\Support\Seo\IndexNowSiteKey::SETTING_KEY)->count();
        $this->assertSame(0, $keys(), 'no tenant has a key yet');

        // Asked over HTTP as ss.systems asks, for the status and for the summary block, and for the other tenant.
        $gsc = $this->gscStatus();
        $this->getJson('/api/admin/v1/seo/summary', $this->adminApiHeaders())->assertOk();
        $theirs = $this->statusFor($jpeterson);

        $this->assertSame(0, $keys(), 'finding out whether IndexNow is set up must not set it up');
        $this->assertSame('not_connected', $this->source($gsc, 'indexnow')['state']);
        $this->assertSame('not_connected', $this->source($theirs, 'indexnow')['state']);

        // The collector asking is what makes it — for that tenant alone.
        $made = Tenancy::for($jpeterson, fn (): ?string => app(\SsSystems\Platform\Seo\IndexNow\Contracts\IndexNowKey::class)->key());

        $this->assertSame(1, $keys());
        $this->assertSame($made, Tenancy::for($jpeterson, fn (): ?string => app(\App\Support\Seo\IndexNowSiteKey::class)->existingKey()));
        $this->assertTrue($this->source($this->statusFor($jpeterson), 'indexnow')['connected']);
        $this->assertSame('not_connected', $this->source($this->gscStatus(), 'indexnow')['state'], 'the other tenant still has none');
        $this->assertSame(1, $keys());
    }

    public function test_the_scheduler_beat_is_reported_to_every_tenant(): void
    {
        Carbon::setTestNow('2026-11-12 09:15:00');
        app()->forgetInstance('site.current');
        SchedulerBeat::record();

        Carbon::setTestNow('2026-11-12 09:20:00');
        $beat = ['last_beat_at' => '2026-11-12T09:15:00+00:00', 'state' => 'fresh'];

        $this->assertSame($beat, $this->gscStatus()['scheduler']);
        $this->assertSame($beat, $this->statusFor($this->site('jpeterson'))['scheduler']);
        $this->assertSame($beat, $this->statusFor($this->site('ss'))['scheduler']);
    }

    /**
     * The kit reads one MAX(date) per source from the table its registry
     * names, scoped by this site's tenant column, and answers null — never
     * an error — when that read fails. A table here without `site_id`, or
     * under another name, would therefore lose its date without a word, so
     * every one of them is checked for the column first.
     */
    public function test_data_through_dates_do_not_mix_tenants(): void
    {
        $tables = array_filter(array_column(SourceRegistry::all(), 'data_through', 'key'));
        $this->assertNotEmpty($tables);

        foreach ($tables as $key => [$table, $column]) {
            $this->assertTrue(Schema::hasColumns($table, ['site_id', $column]), "{$key}: {$table} needs site_id and {$column}");
        }

        $jpeterson = $this->site('jpeterson');

        Tenancy::for($this->site('gsc'), function (): void {
            BingDailyTotal::create(['date' => '2026-11-08', 'site_url' => 'https://gs.construction/', 'clicks' => 1, 'impressions' => 9, 'ctr' => 0.11]);
        });
        Tenancy::for($jpeterson, function (): void {
            BingDailyTotal::create(['date' => '2026-11-11', 'site_url' => 'https://jpeterson-design.com/', 'clicks' => 2, 'impressions' => 8, 'ctr' => 0.25]);
            GscDailyTotal::create(['date' => '2026-11-10', 'site_url' => 'sc-domain:jpeterson-design.com', 'clicks' => 3, 'impressions' => 30, 'ctr' => 0.1, 'position' => 7.5]);
        });

        $mine = $this->gscStatus();
        $theirs = $this->statusFor($jpeterson);

        $this->assertSame('2026-11-08', $this->source($mine, 'bing')['data_through'], "the newest Bing day is the other tenant's and must not be reported here");
        $this->assertNull($this->source($mine, 'search_console')['data_through'], 'this tenant has no Search Console day at all');
        $this->assertSame('2026-11-11', $this->source($theirs, 'bing')['data_through']);
        $this->assertSame('2026-11-10', $this->source($theirs, 'search_console')['data_through']);
    }

    public function test_the_summary_status_block_counts_this_tenants_sources_only(): void
    {
        Carbon::setTestNow('2026-11-12 09:00:00');
        Storage::fake('local');
        $url = '/api/admin/v1/seo/summary';

        Tenancy::for($this->site('jpeterson'), function (): void {
            Heartbeat::failed('snapshot', 'The J. Peterson snapshot could not be built.');
            Heartbeat::failed('reports', 'No reports were written.');
        });
        Tenancy::for($this->site('gsc'), fn () => Heartbeat::succeeded('snapshot'));

        $status = $this->getJson($url, $this->adminApiHeaders())->assertOk()->json('data.status');

        $this->assertSame(0, $status['failing'], "the other tenant's two failing sources must not be counted here");
        $this->assertNotSame('failing', $status['worst']);

        Tenancy::for($this->site('gsc'), fn () => Heartbeat::failed('reports', 'No reports were written.'));

        $status = $this->getJson($url, $this->adminApiHeaders())->assertOk()->json('data.status');

        $this->assertSame(1, $status['failing']);
        $this->assertSame('failing', $status['worst']);
    }

    /**
     * End to end, as production runs it: the schedule's own command line
     * (`tenants:run seo:snapshot-store --site=gsc`, which starts the
     * collector with `$this->call()` and so raises no console event), then
     * the endpoint ss.systems reads.
     */
    public function test_the_snapshot_job_as_scheduled_shows_up_as_a_fresh_source(): void
    {
        Carbon::setTestNow('2026-11-12 09:15:00');
        Storage::fake('local');

        $this->assertSame('never_ran', $this->source($this->gscStatus(), 'snapshot')['state']);

        $this->assertSame(0, Artisan::call('tenants:run', ['cmd' => 'seo:snapshot-store', '--site' => ['gsc']]));

        $snapshot = $this->source($this->gscStatus(), 'snapshot');
        $this->assertSame('fresh', $snapshot['state']);
        $this->assertSame('2026-11-12T09:15:00+00:00', $snapshot['last_success_at']);
        $this->assertSame(900, $snapshot['cadence_seconds']);
        $this->assertSame('never_ran', $this->source($this->statusFor($this->site('jpeterson')), 'snapshot')['state']);
    }
}
