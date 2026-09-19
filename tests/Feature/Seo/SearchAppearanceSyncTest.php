<?php

namespace Tests\Feature\Seo;

use App\Models\OAuthToken;
use App\Services\GoogleSearchConsoleService;
use App\Support\Seo\SearchAppearance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The bug this pins cost a month of empty data.
 *
 * syncSearchAppearance() asked Google for ['date', 'searchAppearance'] in one
 * call. Google rejects that outright:
 *
 *   400 Cannot group by search appearance dimension together with another
 *       dimension.
 *
 * The failure branch only warns, so the sync reported success every night while
 * gsc_search_appearance_metrics sat at zero rows from the day it was created —
 * and nothing read the table, so nobody noticed. There was no test over this
 * path at all, which is why it survived.
 */
class SearchAppearanceSyncTest extends TestCase
{
    use RefreshDatabase;

    private function connect(): void
    {
        config([
            'services.google.search_console.enabled' => true,
            'services.google.search_console.client_id' => 'client-id',
            'services.google.search_console.client_secret' => 'client-secret',
        ]);

        OAuthToken::create([
            'provider' => GoogleSearchConsoleService::PROVIDER,
            'refresh_token' => 'refresh-token',
            'access_token' => 'access-token',
            'access_token_expires_at' => now()->addHour(),
            'scopes' => ['https://www.googleapis.com/auth/webmasters'],
        ]);
    }

    private function fakeAnalytics(): void
    {
        Http::fake(function ($request) {
            if (! str_contains($request->url(), 'searchAnalytics/query')) {
                return Http::response([]);
            }

            $dimensions = $request->data()['dimensions'] ?? [];

            if ($dimensions === ['searchAppearance']) {
                return Http::response(['rows' => [
                    ['keys' => ['REVIEW_SNIPPET'], 'clicks' => 2, 'impressions' => 60, 'ctr' => 0.0333, 'position' => 5.1],
                ]]);
            }

            return Http::response(['rows' => []]);
        });
    }

    public function test_search_appearance_is_never_requested_alongside_another_dimension(): void
    {
        $this->connect();
        $this->fakeAnalytics();

        $this->artisan('seo:gsc-sync', ['--days' => 2, '--lag-days' => 1])->assertSuccessful();

        $appearanceRequests = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0]->data()['dimensions'] ?? [])
            ->filter(fn ($dimensions) => in_array('searchAppearance', $dimensions, true));

        $this->assertCount(2, $appearanceRequests, 'expected one searchAppearance request per day in the range');

        foreach ($appearanceRequests as $dimensions) {
            $this->assertSame(['searchAppearance'], $dimensions);
        }
    }

    public function test_it_stores_a_row_per_day_and_re_running_upserts(): void
    {
        $this->connect();
        $this->fakeAnalytics();

        $this->artisan('seo:gsc-sync', ['--days' => 2, '--lag-days' => 1])->assertSuccessful();
        $this->artisan('seo:gsc-sync', ['--days' => 2, '--lag-days' => 1])->assertSuccessful();

        $rows = DB::table(SearchAppearance::TABLE)->get();

        $this->assertCount(2, $rows, 'a re-run must upsert, not duplicate');
        $this->assertSame('REVIEW_SNIPPET', $rows->first()->appearance);
        $this->assertSame(60, (int) $rows->first()->impressions);
    }
}
