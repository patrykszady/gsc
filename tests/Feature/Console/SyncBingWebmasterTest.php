<?php

namespace Tests\Feature\Console;

use App\Models\BingDailyTotal;
use App\Models\BingTrafficStat;
use App\Models\PlatformSetting;
use App\Support\Seo\BingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use SsSystems\Platform\Seo\Bing\BingWebmasterApi;
use Tests\TestCase;

/**
 * seo:bing-sync runs the kit's BingSync (0.8.0) over this site's client and
 * writer — pinned here because the loop used to be inline in the command
 * and nothing tested it.
 */
class SyncBingWebmasterTest extends TestCase
{
    use RefreshDatabase;

    private function fakeBing(): void
    {
        Http::fake([
            BingWebmasterApi::API_BASE.'/GetQueryStats*' => Http::response(['d' => [
                ['Date' => '/Date(1758153600000)/', 'Query' => 'kitchen remodel chicago', 'Impressions' => 40, 'Clicks' => 3, 'AvgImpressionPosition' => 4.5],
                ['Date' => '/Date(1758240000000)/', 'Query' => 'kitchen remodel chicago', 'Impressions' => 55, 'Clicks' => 5, 'AvgImpressionPosition' => 3.9],
            ]]),
            BingWebmasterApi::API_BASE.'/GetRankAndTrafficStats*' => Http::response(['d' => [
                ['Date' => '/Date(1758153600000)/', 'Impressions' => 120, 'Clicks' => 6],
            ]]),
        ]);
    }

    public function test_it_fills_both_tables_from_the_admin_saved_key_and_a_rerun_updates(): void
    {
        config(['services.bing.webmaster_api_key' => null]);
        PlatformSetting::put(BingSettings::SETTING_API_KEY, 'admin-saved-key');
        $this->fakeBing();

        $this->artisan('seo:bing-sync')
            ->expectsOutputToContain('Fetched 2 rows')
            ->expectsOutputToContain('Upserted 2 rows.')
            ->expectsOutputToContain('Daily totals upserted: 1 day(s).')
            ->assertExitCode(0);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/GetQueryStats?') && $r['apikey'] === 'admin-saved-key');
        $this->assertSame(2, BingTrafficStat::count());
        $this->assertSame('2025-09-18', BingTrafficStat::orderBy('date')->first()->date->toDateString(), 'Bing dates are read in UTC');
        $this->assertSame(0.05, BingDailyTotal::first()->ctr);

        $this->artisan('seo:bing-sync')->assertExitCode(0);

        $this->assertSame(2, BingTrafficStat::count());
        $this->assertSame(1, BingDailyTotal::count(), 'the daily-total lookup finds the day again under sqlite too');
    }

    public function test_without_a_key_it_fails_plainly_without_asking_bing(): void
    {
        config(['services.bing.webmaster_api_key' => null]);
        Http::fake();

        $this->artisan('seo:bing-sync')
            ->expectsOutputToContain('Bing not configured')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_a_dry_run_shows_a_sample_and_writes_nothing(): void
    {
        PlatformSetting::put(BingSettings::SETTING_API_KEY, 'admin-saved-key');
        $this->fakeBing();

        $this->artisan('seo:bing-sync --dry-run')
            ->expectsOutputToContain('"query":"kitchen remodel chicago"')
            ->assertExitCode(0);

        $this->assertSame(0, BingTrafficStat::count());
    }
}
