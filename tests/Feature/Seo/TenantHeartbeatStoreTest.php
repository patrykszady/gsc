<?php

namespace Tests\Feature\Seo;

use App\Models\SeoSyncRun;
use App\Models\Site;
use App\Support\Seo\TenantHeartbeatStore;
use App\Support\Tenancy;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SsSystems\Platform\Seo\Status\Contracts\HeartbeatStore;
use SsSystems\Platform\Seo\Status\Heartbeat;
use SsSystems\Platform\Seo\Status\HeartbeatStores;
use SsSystems\Platform\Seo\Status\SchedulerBeat;
use SsSystems\Platform\Seo\Status\SeoStatus;
use SsSystems\Platform\Seo\Status\SeoStatusServiceProvider;
use Tests\TestCase;

/**
 * App\Support\Seo\TenantHeartbeatStore — where the kit's SEO data-status
 * heartbeats (kit 0.28.0, 2026-11-12) are kept on this multi-tenant site:
 * a source's row is the current tenant's, the scheduler's beat is kept
 * once for all of them. Written through the kit's own Heartbeat and
 * SchedulerBeat wherever possible, so the binding in AppServiceProvider is
 * what is under test, not just the class.
 */
class TenantHeartbeatStoreTest extends TestCase
{
    private const MIGRATION = 'migrations/2026_11_13_010626_create_seo_sync_runs_table.php';

    private const UNIQUE_INDEX = 'seo_sync_runs_site_id_sync_key_unique';

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

    private function site(string $slug): Site
    {
        return Site::query()->where('slug', $slug)->firstOrFail();
    }

    private function store(): HeartbeatStore
    {
        return app(HeartbeatStore::class);
    }

    public function test_the_kit_writes_to_and_reads_from_this_sites_store(): void
    {
        $this->assertInstanceOf(TenantHeartbeatStore::class, HeartbeatStores::current());
        $this->assertSame($this->store(), HeartbeatStores::current(), 'one store for every writer and reader in the process');
    }

    public function test_a_heartbeat_written_for_one_tenant_is_not_visible_to_another(): void
    {
        Carbon::setTestNow('2026-11-12 09:00:00');
        $gsc = $this->site('gsc');
        $jpeterson = $this->site('jpeterson');

        Tenancy::for($gsc, fn () => Heartbeat::succeeded('bing', ['fetched' => 412]));

        $this->assertSame('ok', Tenancy::for($gsc, fn () => $this->store()->read('bing'))['last_status']);
        $this->assertNull(Tenancy::for($jpeterson, fn () => $this->store()->read('bing')));

        Tenancy::for($jpeterson, fn () => Heartbeat::failed('bing', 'InvalidApiKey'));

        $mine = Tenancy::for($gsc, fn () => $this->store()->read('bing'));
        $theirs = Tenancy::for($jpeterson, fn () => $this->store()->read('bing'));

        $this->assertSame('ok', $mine['last_status'], "the other tenant's failure must not land on this tenant's row");
        $this->assertSame(['fetched' => 412], $mine['detail']);
        $this->assertSame('failed', $theirs['last_status']);
        $this->assertSame('InvalidApiKey', $theirs['last_error']);
        $this->assertNull($theirs['last_success_at']);

        $this->assertSame(
            [$gsc->id => 'bing', $jpeterson->id => 'bing'],
            SeoSyncRun::withoutSiteScope()->orderBy('site_id')->pluck('sync_key', 'site_id')->all(),
            'one row per tenant, each stamped with its own site',
        );
    }

    public function test_a_command_with_no_tenant_bound_writes_for_the_default_site(): void
    {
        app()->forgetInstance('site.current');

        Heartbeat::succeeded('bing');

        $this->assertSame($this->site('gsc')->id, SeoSyncRun::withoutSiteScope()->where('sync_key', 'bing')->sole()->site_id);
    }

    public function test_a_later_run_replaces_the_tenants_one_row(): void
    {
        $this->store()->write('bing', ['last_status' => 'ok', 'detail' => ['fetched' => 1, 'warnings' => ['a/b']]]);
        $this->store()->write('bing', ['last_status' => 'failed', 'last_error' => 'Bing said no — "żółć"']);

        $this->assertSame(['last_status' => 'failed', 'last_error' => 'Bing said no — "żółć"'], $this->store()->read('bing'));
        $this->assertSame(1, SeoSyncRun::withoutSiteScope()->count());
    }

    /**
     * As `schedule:run` does it: the kit's listener on Laravel's own
     * scheduler event, handed this site's real seo:snapshot-store schedule
     * entry (`tenants:run seo:snapshot-store --site=gsc`), with no tenant
     * bound.
     */
    public function test_the_scheduler_beat_written_with_no_tenant_bound_is_read_back_for_every_tenant(): void
    {
        Carbon::setTestNow('2026-11-12 09:15:00');
        $this->app->register(SeoStatusServiceProvider::class);
        $sites = Site::query()->orderBy('id')->get();
        $task = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'seo:snapshot-store'));
        $this->assertNotNull($task, 'seo:snapshot-store is not scheduled');

        app()->forgetInstance('site.current');
        event(new ScheduledTaskStarting($task));

        $this->assertGreaterThan(1, $sites->count());

        foreach ($sites as $site) {
            $status = Tenancy::for($site, fn (): array => SeoStatus::payload());

            $this->assertSame(
                ['last_beat_at' => '2026-11-12T09:15:00+00:00', 'state' => 'fresh'],
                $status['scheduler'],
                "the scheduler's beat must be read back for {$site->slug}",
            );
        }

        $row = SeoSyncRun::withoutSiteScope()->where('sync_key', SchedulerBeat::KEY)->sole();
        $this->assertNull($row->site_id, 'kept once, as nobody\'s row — not stamped with the default site');
    }

    public function test_the_next_beat_moves_the_one_shared_row_whichever_tenant_is_bound(): void
    {
        Carbon::setTestNow('2026-11-12 09:15:00');
        SchedulerBeat::record();

        Carbon::setTestNow('2026-11-12 09:30:00');
        Tenancy::for($this->site('jpeterson'), fn () => SchedulerBeat::record());

        $this->assertSame(1, SeoSyncRun::withoutSiteScope()->count());
        $this->assertSame('2026-11-12T09:30:00+00:00', SchedulerBeat::lastBeatAt($this->store()->read(SchedulerBeat::KEY)));
    }

    /**
     * MySQL's unique index does not hold two NULL-tenant rows apart, so
     * two schedulers inserting the first beat at the same instant could
     * leave a pair behind.
     */
    public function test_a_doubled_shared_row_reads_as_the_newest_and_both_are_kept_current(): void
    {
        foreach (['2026-11-12 09:00:00' => 'older', '2026-11-12 09:15:00' => 'newer'] as $at => $which) {
            $row = new SeoSyncRun(['sync_key' => SchedulerBeat::KEY, 'summary' => ['which' => $which]]);
            $row->site_id = null;
            $row->created_at = $row->updated_at = Carbon::parse($at);
            $row->saveQuietly();
        }

        $this->assertSame(['which' => 'newer'], $this->store()->read(SchedulerBeat::KEY));

        $this->store()->write(SchedulerBeat::KEY, ['which' => 'now']);

        $this->assertSame(
            [['which' => 'now'], ['which' => 'now']],
            SeoSyncRun::withoutSiteScope()->whereNull('site_id')->get()->pluck('summary')->all(),
        );
    }

    public function test_a_tenants_row_and_a_shared_row_never_stand_in_for_each_other(): void
    {
        $gsc = $this->site('gsc');

        $shared = new SeoSyncRun(['sync_key' => 'bing', 'summary' => ['last_status' => 'failed']]);
        $shared->site_id = null;
        $shared->saveQuietly();

        $stamped = new SeoSyncRun(['sync_key' => SchedulerBeat::KEY, 'summary' => ['last_beat_at' => '2026-01-01T00:00:00+00:00']]);
        $stamped->site_id = $gsc->id;
        $stamped->saveQuietly();

        Tenancy::for($gsc, function (): void {
            $this->assertNull($this->store()->read('bing'), 'a source is read from the tenant\'s own row only');
            $this->assertNull($this->store()->read(SchedulerBeat::KEY), 'the beat is read from the shared row only');
        });
    }

    /**
     * Two processes finishing the same tenant's collector at once: both
     * find no row, both insert. The unique index refuses the second, which
     * must then land on the row the first one made — simulated by slipping
     * the rival's row in just before this store's own INSERT runs.
     */
    public function test_losing_the_race_for_a_tenants_first_row_ends_on_the_winners_row(): void
    {
        $gsc = $this->site('gsc');
        $this->assertNull($this->store()->read('bing'));
        $raced = false;

        DB::connection()->beforeExecuting(function (string $query) use (&$raced, $gsc): void {
            if ($raced || ! str_starts_with($query, 'insert into "seo_sync_runs"')) {
                return;
            }

            $raced = true;
            $rival = new SeoSyncRun(['sync_key' => 'bing', 'summary' => ['last_status' => 'failed']]);
            $rival->site_id = $gsc->id;
            $rival->saveQuietly();
        });

        $this->store()->write('bing', ['last_status' => 'ok']);

        $this->assertTrue($raced);
        $this->assertSame(['last_status' => 'ok'], $this->store()->read('bing'));
        $this->assertSame(1, SeoSyncRun::withoutSiteScope()->count());
    }

    public function test_the_migration_can_be_run_again_after_stopping_half_way_and_be_rolled_back(): void
    {
        $migration = require database_path(self::MIGRATION);
        $this->assertTrue(Schema::hasIndex('seo_sync_runs', self::UNIQUE_INDEX));

        // MySQL commits each statement of a migration on its own: the
        // table can be there without its index when the run is repeated.
        Schema::table('seo_sync_runs', fn (Blueprint $table) => $table->dropUnique(self::UNIQUE_INDEX));
        $this->store()->write('bing', ['last_status' => 'ok']);

        $migration->up();
        $migration->up();

        $this->assertTrue(Schema::hasIndex('seo_sync_runs', self::UNIQUE_INDEX));
        $this->assertSame(['last_status' => 'ok'], $this->store()->read('bing'), 'finishing the migration must keep what the table held');

        $migration->down();
        $this->assertFalse(Schema::hasTable('seo_sync_runs'));

        $migration->up();
        $this->assertSame(
            ['id', 'site_id', 'sync_key', 'summary', 'created_at', 'updated_at'],
            Schema::getColumnListing('seo_sync_runs'),
        );
        $this->assertTrue(Schema::hasIndex('seo_sync_runs', ['site_id', 'sync_key'], 'unique'));
    }

    public function test_without_its_table_it_reads_nothing_writes_nothing_and_never_throws(): void
    {
        Schema::drop('seo_sync_runs');

        $store = new TenantHeartbeatStore;

        $this->assertNull($store->read('bing'));
        $store->write('bing', ['last_status' => 'ok']);
        $this->assertNull($store->read(SchedulerBeat::KEY));

        Heartbeat::succeeded('bing');
        SchedulerBeat::record();
        $this->assertNull(Heartbeat::lastException());
        $this->assertSame('never_ran', SeoStatus::payload()['scheduler']['state']);

        // The table arriving later (the migration running under a live
        // process) is picked up by the same instance.
        (require database_path(self::MIGRATION))->up();

        $store->write('bing', ['last_status' => 'ok']);
        $this->assertSame(['last_status' => 'ok'], $store->read('bing'));
    }
}
