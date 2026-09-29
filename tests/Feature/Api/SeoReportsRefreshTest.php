<?php

namespace Tests\Feature\Api;

use App\Models\Site;
use App\Support\Seo\Reports\ReportRefresh;
use App\Support\SeoStorage;
use App\Support\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use SsSystems\Platform\Reports\Jobs\RunArtisanCommandDetached;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

/**
 * The Full Report Library reads "needs a refresh" most of the time
 * (2026-09-29): a report counts as fresh for 24 hours, and one report's Run
 * is cut off by production's 30-second PHP-FPM limit. "Refresh all" starts
 * one background pass over every report; the schedule runs the same pass
 * hourly. Ported from hive2025's identical test — see App\Support\Seo\
 * Reports\ReportRefresh's docblock for what changed to make this work on a
 * multi-tenant site: every direct (non-HTTP) call below is wrapped in
 * Tenancy::for($this->gsc, ...) the same way tests/Feature/Seo/
 * ReportRunTenantIsolationTest.php already pins a tenant for a console-style
 * call with no request to inherit one from; the HTTP calls get 'gsc' for
 * free from PinAdminApiTenant.
 */
class SeoReportsRefreshTest extends TestCase
{
    use WithAdminApiAuth;

    private Site $gsc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAdminApiAuth();
        Storage::fake('local');
        Cache::flush();

        $this->gsc = Site::query()->where('slug', 'gsc')->firstOrFail();
    }

    /** @return list<string> */
    private function availableReportKeys(): array
    {
        return Tenancy::for($this->gsc, fn () => ReportRefresh::keysToRun(onlyStale: false));
    }

    private function reportPath(string $key): string
    {
        return Tenancy::for($this->gsc, fn () => SeoStorage::path("reports/{$key}.md"));
    }

    private function writeReport(string $key, int $hoursAgo = 0): void
    {
        $path = $this->reportPath($key);
        Storage::disk('local')->put($path, "# {$key}\n");
        touch(Storage::disk('local')->path($path), now()->subHours($hoursAgo)->getTimestamp());
    }

    public function test_it_starts_one_background_pass_over_every_report_that_needs_a_refresh(): void
    {
        Queue::fake();
        $keys = $this->availableReportKeys();
        $this->writeReport($keys[0]);           // up to date
        $this->writeReport($keys[1], 30);       // stale

        $data = $this->postJson('/api/admin/v1/seo/reports/refresh', [], $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $expected = array_values(array_diff($keys, [$keys[0]]));
        $this->assertTrue($data['queued']);
        $this->assertSame($expected, $data['keys']);
        $this->assertTrue($data['batch']['running']);

        // Handed --site explicitly: this app must never rely on a detached
        // job's own default-site fallback to land on the right tenant.
        Queue::assertPushed(RunArtisanCommandDetached::class, fn ($job) => $job->command === 'seo:reports-refresh'
            && $job->options === ['--keys' => implode(',', $expected), '--site' => 'gsc']);

        $this->getJson('/api/admin/v1/seo/reports', $this->adminApiHeaders())
            ->assertOk()
            ->assertJsonPath('data.batch.running', true)
            ->assertJsonPath('data.batch.keys', $expected);
    }

    public function test_it_does_not_start_a_second_pass_while_one_is_running(): void
    {
        Queue::fake();
        Tenancy::for($this->gsc, fn () => ReportRefresh::markQueued(['health']));

        $this->postJson('/api/admin/v1/seo/reports/refresh', [], $this->adminApiHeaders())
            ->assertOk()
            ->assertJsonPath('data.queued', false)
            ->assertJsonPath('data.running', true);

        Queue::assertNothingPushed();
    }

    public function test_it_says_so_when_every_report_is_up_to_date(): void
    {
        Queue::fake();
        foreach ($this->availableReportKeys() as $key) {
            $this->writeReport($key);
        }

        $this->postJson('/api/admin/v1/seo/reports/refresh', [], $this->adminApiHeaders())
            ->assertOk()
            ->assertJsonPath('data.queued', false)
            ->assertJsonPath('data.message', 'Every report is up to date.');

        Queue::assertNothingPushed();
    }

    public function test_it_runs_the_reports_one_after_another_and_records_the_progress(): void
    {
        $keys = $this->availableReportKeys();
        $ran = [];

        // A plain closure, not `fn () =>`, for the OUTER wrapper: an arrow
        // function auto-captures `$ran` by value, so the inner runner's
        // `use (&$ran)` would bind to that detached copy instead of the
        // variable below — silently losing every write.
        $progress = Tenancy::for($this->gsc, function () use (&$ran) {
            return ReportRefresh::run(
                onlyStale: false,
                runner: function (string $key, array $meta, Request $request, int $days) use (&$ran) {
                    $ran[] = $key;
                    $this->writeReport($key);

                    return ['ok' => true, 'status' => 'ok', 'output_tail' => ''];
                },
            );
        });

        $this->assertSame($keys, $ran);
        $this->assertFalse($progress['running']);
        $this->assertSame($keys, $progress['done']);
        foreach ($progress['results'] as $status) {
            $this->assertSame('ok', $status);
        }
        $this->assertSame([], Tenancy::for($this->gsc, fn () => ReportRefresh::keysToRun(onlyStale: true)));
    }

    public function test_it_counts_a_clean_run_with_nothing_to_write_as_checked_today(): void
    {
        $key = $this->availableReportKeys()[0];

        Tenancy::for($this->gsc, fn () => ReportRefresh::run(
            onlyStale: false,
            keys: [$key],
            runner: fn () => ['ok' => true, 'status' => 'ok', 'output_tail' => "Loading…\nNothing ranking in positions 8-20 this month."],
        ));

        $note = Storage::disk('local')->get($this->reportPath($key));
        $this->assertStringContainsString('Nothing ranking in positions 8-20 this month.', $note);
        $this->assertNotContains($key, Tenancy::for($this->gsc, fn () => ReportRefresh::keysToRun(onlyStale: true)));
    }

    public function test_it_leaves_a_failed_report_stale_and_lets_the_hourly_pass_wait_before_trying_it_again(): void
    {
        $key = $this->availableReportKeys()[0];
        $failing = fn () => ['ok' => false, 'status' => 'failed', 'output_tail' => 'boom'];

        $first = Tenancy::for($this->gsc, fn () => ReportRefresh::run(onlyStale: true, keys: [$key], automatic: true, runner: $failing));
        $this->assertSame('failed', $first['results'][$key]);
        $this->assertFalse(Storage::disk('local')->exists($this->reportPath($key)));

        $second = Tenancy::for($this->gsc, fn () => ReportRefresh::run(onlyStale: true, keys: [$key], automatic: true, runner: $failing));
        $this->assertSame([], $second['done']);

        $this->travel(7)->hours();
        $third = Tenancy::for($this->gsc, fn () => ReportRefresh::run(onlyStale: true, keys: [$key], automatic: true, runner: $failing));
        $this->assertSame([$key], $third['done']);
    }
}
