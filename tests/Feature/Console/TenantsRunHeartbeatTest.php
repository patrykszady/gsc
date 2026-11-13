<?php

namespace Tests\Feature\Console;

use App\Models\OAuthToken;
use App\Models\SeoSyncRun;
use App\Models\Site;
use App\Support\Tenancy;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use SsSystems\Platform\Seo\Snapshot\Contracts\SeoSnapshotSource;
use SsSystems\Platform\Seo\Snapshot\SeoSnapshotStore;
use SsSystems\Platform\Seo\Status\Contracts\HeartbeatStore;
use SsSystems\Platform\Seo\Status\Heartbeat;
use SsSystems\Platform\Seo\Status\SeoStatusServiceProvider;
use Tests\TestCase;

/**
 * `tenants:run` reports the commands it runs to the kit's SEO data-status
 * heartbeat (kit 0.28.0, 2026-11-12). It starts each one with
 * `$this->call()`, which raises no CommandStarting/CommandFinished event,
 * so without CommandHeartbeat::around() in App\Console\Commands\TenantsRun
 * every per-tenant collector — seo:snapshot-store, seo:gsc-sync,
 * seo:faq-bank, seo:gsc-inspect-bulk, seo:bing-submit-sitemaps — would run
 * and leave nothing for `GET seo/status` to read.
 *
 * Driven with the real seo:snapshot-store (the `snapshot` source's one
 * job), its snapshot source and store swapped for ones that succeed, fail
 * or throw depending on the tenant bound when the command runs — and once
 * with the real seo:gsc-sync against a faked Google, for a collector that
 * reports its own outcome.
 */
class TenantsRunHeartbeatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Heartbeat::reset();
    }

    protected function tearDown(): void
    {
        Heartbeat::reset();
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array<string, mixed>|null the heartbeat kept for one tenant — its `snapshot` one unless another source is named */
    private function heartbeatOf(string $slug, string $source = 'snapshot'): ?array
    {
        return Tenancy::for(
            Site::query()->where('slug', $slug)->firstOrFail(),
            fn (): ?array => app(HeartbeatStore::class)->read($source),
        );
    }

    /**
     * gsc builds its snapshot, jpeterson's build fails (the command exits
     * 1), and for ss the command itself throws before it can exit.
     */
    private function snapshotsThatDependOnTheTenant(): void
    {
        $this->app->bind(SeoSnapshotSource::class, fn () => new class implements SeoSnapshotSource
        {
            public function buildSnapshot(Request $request): array
            {
                if (Site::current()->slug === 'jpeterson') {
                    throw new RuntimeException('No data for this tenant.');
                }

                return ['health' => ['score' => 80]];
            }

            public function forgetSnapshotCaches(Request $request): void {}
        });

        $this->app->bind(SeoSnapshotStore::class, function ($app): SeoSnapshotStore {
            if (Site::current()->slug === 'ss') {
                throw new RuntimeException('The cache is down.');
            }

            return new SeoSnapshotStore($app->make('cache.store'), Tenancy::cacheKey('admin.seo-snapshot'));
        });
    }

    public function test_a_command_run_per_tenant_records_a_heartbeat_for_each_tenant_including_the_ones_it_failed_for(): void
    {
        Carbon::setTestNow('2026-11-12 09:15:00');
        $this->snapshotsThatDependOnTheTenant();

        // Also the first query of the test: LazilyRefreshDatabase migrates
        // on it and then swaps the console application, which would take
        // the output read below with it were that to happen mid-command.
        $this->assertSame(0, SeoSyncRun::withoutSiteScope()->count());

        $exitCode = Artisan::call('tenants:run', [
            'cmd' => 'seo:snapshot-store',
            '--site' => ['gsc', 'jpeterson', 'ss'],
            '--continue-on-error' => true,
        ]);

        $this->assertSame(1, $exitCode, 'tenants:run still fails when a tenant did');
        $this->assertStringContainsString('Failed for: jpeterson (exit 1), ss (The cache is down.)', Artisan::output());

        $gsc = $this->heartbeatOf('gsc');
        $this->assertSame('ok', $gsc['last_status']);
        $this->assertSame('2026-11-12T09:15:00+00:00', $gsc['last_success_at']);
        $this->assertSame(0, $gsc['consecutive_failures']);

        $jpeterson = $this->heartbeatOf('jpeterson');
        $this->assertSame('failed', $jpeterson['last_status']);
        $this->assertSame('seo:snapshot-store exited with code 1.', $jpeterson['last_error']);
        $this->assertNull($jpeterson['last_success_at']);
        $this->assertSame(1, $jpeterson['consecutive_failures']);

        $ss = $this->heartbeatOf('ss');
        $this->assertSame('failed', $ss['last_status']);
        $this->assertSame('seo:snapshot-store threw: The cache is down.', $ss['last_error']);

        $this->assertSame(3, SeoSyncRun::withoutSiteScope()->where('sync_key', 'snapshot')->distinct()->count('site_id'));
    }

    public function test_the_next_run_keeps_each_tenants_own_history(): void
    {
        $this->snapshotsThatDependOnTheTenant();
        $run = fn (): int => Artisan::call('tenants:run', [
            'cmd' => 'seo:snapshot-store',
            '--site' => ['gsc', 'jpeterson'],
            '--continue-on-error' => true,
        ]);

        Carbon::setTestNow('2026-11-12 09:15:00');
        $run();
        Carbon::setTestNow('2026-11-12 09:30:00');
        $run();

        $this->assertSame(2, $this->heartbeatOf('jpeterson')['consecutive_failures']);
        $this->assertSame(0, $this->heartbeatOf('gsc')['consecutive_failures']);
        $this->assertSame('2026-11-12T09:30:00+00:00', $this->heartbeatOf('gsc')['last_success_at']);
    }

    /**
     * The Search Console sync as the schedule runs it. The kit's sync
     * reports its own outcome from inside the command, with Google's own
     * words; tenants:run must leave that report alone rather than add the
     * exit code as a second failure on top.
     */
    public function test_a_collector_that_reports_itself_is_not_counted_twice(): void
    {
        Carbon::setTestNow('2026-11-12 09:00:00');
        Cache::flush();
        Storage::fake('local');
        config([
            'services.google.search_console.enabled' => true,
            'services.google.search_console.client_id' => 'client-id',
            'services.google.search_console.client_secret' => 'client-secret',
            'seo.search_console.site_url' => 'sc-domain:gs.construction',
        ]);
        OAuthToken::create([
            'provider' => 'google_search_console',
            'refresh_token' => 'refresh-token',
            'access_token' => 'access-token',
            'access_token_expires_at' => now()->addDay(),
            'scopes' => ['https://www.googleapis.com/auth/webmasters'],
        ]);
        $sync = fn (): int => Artisan::call('tenants:run', ['cmd' => 'seo:gsc-sync --days=1 --lag-days=1', '--site' => ['gsc']]);
        $refused = true;
        Http::fake(['searchconsole.googleapis.com/*' => function () use (&$refused) {
            return $refused
                ? Http::response(['error' => ['code' => 403, 'message' => 'User does not have sufficient permission for site.']], 403)
                : Http::response(['rows' => []]);
        }]);

        $this->assertSame(1, $sync());
        Carbon::setTestNow('2026-11-12 12:00:00');
        $this->assertSame(1, $sync());

        $failing = $this->heartbeatOf('gsc', 'search_console');
        $this->assertSame('failed', $failing['last_status']);
        $this->assertSame(2, $failing['consecutive_failures'], 'two runs, two failures — not four');
        $this->assertStringContainsString('sufficient permission', $failing['last_error']);
        $this->assertNull($failing['last_success_at']);

        $refused = false;
        Carbon::setTestNow('2026-11-12 15:00:00');
        $this->assertSame(0, $sync());

        $healthy = $this->heartbeatOf('gsc', 'search_console');
        $this->assertSame('ok', $healthy['last_status']);
        $this->assertSame(0, $healthy['consecutive_failures']);
        $this->assertSame('2026-11-12T15:00:00+00:00', $healthy['last_success_at']);
        $this->assertNull($this->heartbeatOf('jpeterson', 'search_console'));
    }

    /**
     * Production has both halves on at once: the kit's listener on
     * Laravel's console events (which a test suite does not raise until
     * the kernel is told to) and around() inside tenants:run. A run must
     * still be one run — the outer `tenants:run` feeds no source, and the
     * command inside it raises no event — while a collector the scheduler
     * starts directly, with no tenant bound, is heard by the listener and
     * kept for the default site.
     */
    public function test_with_the_kits_listener_on_as_in_production_each_run_is_counted_once(): void
    {
        Carbon::setTestNow('2026-11-12 09:15:00');
        $this->snapshotsThatDependOnTheTenant();
        $this->assertSame(0, SeoSyncRun::withoutSiteScope()->count());

        $this->app->register(SeoStatusServiceProvider::class);
        $kernel = $this->app->make(Kernel::class);
        $kernel->rerouteSymfonyCommandEvents();
        $kernel->setArtisan(null);

        $this->assertSame(1, Artisan::call('tenants:run', ['cmd' => 'seo:snapshot-store', '--site' => ['jpeterson']]));

        $this->assertSame(1, $this->heartbeatOf('jpeterson')['consecutive_failures']);
        $this->assertNull($this->heartbeatOf('gsc'), 'the outer tenants:run, run with no tenant bound, must record nothing of its own');

        app()->forgetInstance('site.current');
        $this->assertSame(0, Artisan::call('seo:snapshot-store'));

        $this->assertSame('ok', $this->heartbeatOf('gsc')['last_status']);
        $this->assertSame(1, $this->heartbeatOf('jpeterson')['consecutive_failures']);
        $this->assertSame(2, SeoSyncRun::withoutSiteScope()->count());
    }

    public function test_only_a_command_that_feeds_a_data_source_leaves_a_heartbeat(): void
    {
        $this->snapshotsThatDependOnTheTenant();

        $this->assertSame(0, Artisan::call('tenants:run', ['cmd' => 'seo:snapshot-store', '--site' => ['gsc']]));
        $this->assertSame(['snapshot'], SeoSyncRun::withoutSiteScope()->pluck('sync_key')->all());

        $this->assertSame(0, Artisan::call('tenants:run', ['cmd' => 'env', '--site' => ['gsc', 'jpeterson']]));
        $this->assertSame(['snapshot'], SeoSyncRun::withoutSiteScope()->pluck('sync_key')->all(), 'a command no data source knows runs as it always did');
    }
}
