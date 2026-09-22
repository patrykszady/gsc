<?php

namespace Tests\Feature\Console;

use App\Models\GscDailyTotal;
use App\Models\GscQueryMetric;
use App\Models\OAuthToken;
use App\Support\Seo\SearchAppearance;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SsSystems\Platform\Seo\SearchConsoleSyncRule;
use Tests\TestCase;

/**
 * seo:gsc-sync is now a thin wrapper over the shared kit's
 * SsSystems\Platform\Seo\SearchConsoleSync::run() — see
 * App\Console\Commands\SyncGoogleSearchConsole. These tests pin the site
 * side of that wiring (GoogleSearchConsoleService as the sync client,
 * App\Support\Seo\SearchConsoleWriter as where results land, and the run
 * summary recorded onto the oauth_tokens row's metadata['sync']) rather
 * than re-testing the algorithm itself, which
 * ss-platform-kit's own SearchConsoleSyncTest already covers.
 *
 * Mirrors jpeterson-design's tests/Feature/SeoGscSyncTest.php's Http::fake
 * shapes (keyed on the request body's `dimensions`), adjusted for this
 * repo's multi-tenant models and metadata bookkeeping.
 */
class SyncGoogleSearchConsoleTest extends TestCase
{
    private function connect(): void
    {
        Cache::flush();

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
            'access_token_expires_at' => now()->addHour(),
            'scopes' => ['https://www.googleapis.com/auth/webmasters'],
        ]);
    }

    /** Fakes the three shapes of searchAnalytics/query call the sync makes. */
    private function fakeAnalytics(): void
    {
        Http::fake(function ($request) {
            $dimensions = $request->data()['dimensions'] ?? [];

            if ($dimensions === ['date']) {
                return Http::response([
                    'rows' => [
                        ['keys' => ['2026-09-16'], 'clicks' => 14, 'impressions' => 340, 'ctr' => 0.0412, 'position' => 7.8],
                    ],
                ]);
            }

            if ($dimensions === ['searchAppearance']) {
                return Http::response([
                    'rows' => [
                        ['keys' => ['REVIEW_SNIPPET'], 'clicks' => 2, 'impressions' => 60, 'ctr' => 0.0333, 'position' => 5.1],
                    ],
                ]);
            }

            return Http::response([
                'rows' => [
                    [
                        'keys' => ['2026-09-16', 'kitchen remodel palatine', 'https://gs.construction/portfolio/example', 'usa', 'MOBILE'],
                        'clicks' => 3,
                        'impressions' => 120,
                        'ctr' => 0.025,
                        'position' => 6.2,
                    ],
                    [
                        'keys' => ['2026-09-16', 'bathroom remodel arlington heights', 'https://gs.construction/portfolio/other', 'usa', 'DESKTOP'],
                        'clicks' => 1,
                        'impressions' => 40,
                        'ctr' => 0.025,
                        'position' => 9.1,
                    ],
                ],
            ]);
        });
    }

    private function syncMetadata(): ?array
    {
        return OAuthToken::forProvider('google_search_console')?->metadata['sync'] ?? null;
    }

    public function test_a_skipped_run_is_success_not_failure_and_still_records_a_summary(): void
    {
        // A token row exists (so recordSyncRun has somewhere to write) but
        // the client is not configured — isConfigured() checks 'enabled'
        // first, so this stays skipped regardless of the token.
        $this->connect();
        config(['services.google.search_console.enabled' => false]);
        Http::fake();

        // This is the deliberate change from the old command's FAILURE: a
        // skip is not a failure, it means the owner hasn't connected Search
        // Console (or disconnected it) — see SyncGoogleSearchConsole's own
        // comment on this catch.
        $this->artisan('seo:gsc-sync')
            ->expectsOutputToContain('not configured')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, GscQueryMetric::count());
        $this->assertSame(0, GscDailyTotal::count());

        $sync = $this->syncMetadata();
        $this->assertNotNull($sync, 'a skipped run must still be recorded onto the token metadata');
        $this->assertSame('skipped', $sync['status']);
    }

    public function test_a_skipped_run_with_no_token_at_all_writes_no_metadata_anywhere(): void
    {
        config(['services.google.search_console.enabled' => false]);
        Http::fake();

        $this->artisan('seo:gsc-sync')->assertSuccessful();

        // Nothing to stamp a summary onto — gscStatus() reads this null-safely.
        $this->assertSame(0, OAuthToken::count());
    }

    public function test_it_upserts_query_metrics_and_daily_totals_idempotently_and_records_an_ok_summary(): void
    {
        $this->connect();
        $this->fakeAnalytics();

        $this->artisan('seo:gsc-sync', ['--days' => 1, '--lag-days' => 1])
            ->expectsOutputToContain('Inserted=2 Updated=0')
            ->assertSuccessful();

        $this->assertSame(2, GscQueryMetric::count());
        $this->assertSame(1, GscDailyTotal::count());

        $total = GscDailyTotal::first();
        $this->assertSame('sc-domain:gs.construction', $total->site_url);
        $this->assertSame(14, $total->clicks);

        $sync = $this->syncMetadata();
        $this->assertSame('ok', $sync['status']);
        $this->assertSame(2, $sync['inserted']);
        $this->assertSame(0, $sync['updated']);
        $this->assertSame(1, $sync['daily_totals']);
        $this->assertSame(1, $sync['appearance_rows']);
        $this->assertSame('sc-domain:gs.construction', $sync['site_url']);
        $this->assertNotNull($sync['finished_at']);
        $this->assertNull($sync['error']);

        // Re-run with the exact same upstream data: dim_hash is a
        // deterministic SHA1 of the natural key, so a second sync must
        // update the existing two rows, never duplicate them.
        $this->artisan('seo:gsc-sync', ['--days' => 1, '--lag-days' => 1])
            ->expectsOutputToContain('Inserted=0 Updated=2')
            ->assertSuccessful();

        $this->assertSame(2, GscQueryMetric::count());
        $this->assertSame(1, GscDailyTotal::count());
        $this->assertSame(2, $this->syncMetadata()['updated']);
    }

    public function test_dry_run_queries_but_writes_nothing_and_records_a_dry_run_summary(): void
    {
        $this->connect();
        $this->fakeAnalytics();

        $this->artisan('seo:gsc-sync', ['--days' => 1, '--lag-days' => 1, '--dry-run' => true])
            ->assertSuccessful();

        Http::assertSentCount(3); // query-dimension pull + daily-totals pull + one searchAppearance pull (--days=1)
        $this->assertSame(0, GscQueryMetric::count());
        $this->assertSame(0, GscDailyTotal::count());
        $this->assertSame(0, DB::table(SearchAppearance::TABLE)->count());

        $this->assertSame('dry-run', $this->syncMetadata()['status']);
    }

    public function test_search_appearance_issues_one_request_per_day_with_the_dimension_alone(): void
    {
        $this->connect();
        $this->fakeAnalytics();

        // A 2-day range so a single-request implementation is
        // distinguishable from the required per-day shape.
        $this->artisan('seo:gsc-sync', ['--days' => 2, '--lag-days' => 1])->assertSuccessful();

        $appearanceRequests = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0]->data()['dimensions'] ?? [])
            ->filter(fn ($dimensions) => in_array('searchAppearance', $dimensions, true));

        $this->assertCount(2, $appearanceRequests, 'expected one searchAppearance request per day in the range');

        foreach ($appearanceRequests as $dimensions) {
            $this->assertSame(['searchAppearance'], $dimensions);
        }

        $this->assertSame(2, DB::table(SearchAppearance::TABLE)->count());
        $this->assertSame(2, $this->syncMetadata()['appearance_rows']);
    }

    public function test_no_days_or_lag_days_option_falls_back_to_the_kits_own_rule_constants(): void
    {
        $this->connect();
        $this->fakeAnalytics();

        // No --days / --lag-days at all: the command must not silently
        // re-decide the window with its own literal default.
        $this->artisan('seo:gsc-sync')->assertSuccessful();

        $sync = $this->syncMetadata();
        $this->assertSame(SearchConsoleSyncRule::DEFAULT_DAYS, $sync['days']);
        $this->assertSame(SearchConsoleSyncRule::DEFAULT_LAG_DAYS, $sync['lag_days']);
    }

    public function test_a_failed_query_fails_the_command_writes_no_partial_rows_and_records_an_error_summary(): void
    {
        $this->connect();
        Http::fake(['searchconsole.googleapis.com/*' => Http::response('{"error":{"code":500}}', 500)]);

        $this->artisan('seo:gsc-sync', ['--days' => 1, '--lag-days' => 1])->assertFailed();

        $this->assertSame(0, GscQueryMetric::count());
        $this->assertSame(0, GscDailyTotal::count());

        $sync = $this->syncMetadata();
        $this->assertSame('error', $sync['status']);
        $this->assertNotNull($sync['error']);
    }
}
