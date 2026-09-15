<?php

namespace Tests\Feature\Seo;

use App\Models\GscCoverageState;
use App\Models\OAuthToken;
use App\Models\Tracked404;
use App\Support\Seo\UrlInspectionQuota;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The nightly URL Inspection sweep.
 *
 * Search Console's own "why pages aren't indexed" report counts URLs our
 * sitemap never carried, and the API offers no way to read that report, so
 * the sweep has to inspect those URLs itself to have any per-URL state for
 * them at all.
 */
class GscInspectBulkSweepTest extends TestCase
{
    /** @var list<string> */
    protected array $inspected = [];

    /** What the faked inspection endpoint answers; a test may set it to 429. */
    protected int $inspectionStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://gs.construction',
            'services.google.search_console.site_url' => 'sc-domain:gs.construction',
            'services.google.search_console.client_id' => 'id',
            'services.google.search_console.client_secret' => 'secret',
            'services.google.search_console.inspection_daily_quota' => 2000,
            // 600/min keeps the sweep's own pacing at 200ms; the test does not
            // want to wait for it.
            'services.google.search_console.inspection_per_minute_quota' => 600000,
        ]);

        OAuthToken::create([
            'provider' => 'google_search_console',
            'refresh_token' => 'r',
            'access_token' => 'a',
            'expires_at' => now()->addHour(),
            'access_token_expires_at' => now()->addHour(),
            'scopes' => ['https://www.googleapis.com/auth/webmasters'],
        ]);

        $this->inspected = [];
        $this->inspectionStatus = 200;
        // One stub, because a later Http::fake() only adds to the list and the
        // first match wins — a per-test override has to go through here.
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'a', 'expires_in' => 3600]),
            'searchconsole.googleapis.com/*' => function ($request) {
                if ($this->inspectionStatus !== 200) {
                    return Http::response(['error' => ['code' => $this->inspectionStatus]], $this->inspectionStatus);
                }

                $this->inspected[] = $request->data()['inspectionUrl'];

                return Http::response(['inspectionResult' => ['indexStatusResult' => [
                    'verdict' => 'PASS',
                    'coverageState' => 'Submitted and indexed',
                ]]]);
            },
        ]);
    }

    private function sitemap(string ...$urls): string
    {
        $path = storage_path('app/test-sitemap.xml');
        $body = '<?xml version="1.0" encoding="UTF-8"?><urlset>';
        foreach ($urls as $url) {
            $body .= '<url><loc>'.$url.'</loc></url>';
        }
        file_put_contents($path, $body.'</urlset>');

        return $path;
    }

    public function test_it_sweeps_the_sitemap_plus_googlebots_404s_and_rows_the_sitemap_no_longer_carries(): void
    {
        $sitemap = $this->sitemap('https://gs.construction/', 'https://gs.construction/projects');
        // A URL imported from a Console export: never in the sitemap, so only
        // the 'coverage' pool can ever refresh it.
        GscCoverageState::create(['url' => 'https://gs.construction/old-page', 'source' => 'console', 'console_reason' => 'Page with redirect', 'inspected_at' => now()->subWeek()]);
        Tracked404::create(['path' => '/areas-served/orland-park', 'user_agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)', 'hit_count' => 159, 'first_seen_at' => now(), 'last_seen_at' => now()]);
        // Not Googlebot: a person's 404 says nothing about indexing.
        Tracked404::create(['path' => '/typo', 'user_agent' => 'Mozilla/5.0 (Macintosh)', 'hit_count' => 2, 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $this->artisan('seo:gsc-inspect-bulk', ['--sitemap' => $sitemap, '--limit' => 0])->assertExitCode(0);

        $this->assertEqualsCanonicalizing([
            'https://gs.construction/',
            'https://gs.construction/projects',
            'https://gs.construction/old-page',
            'https://gs.construction/areas-served/orland-park',
        ], $this->inspected);
        $this->assertSame(4, UrlInspectionQuota::used());
    }

    public function test_only_the_sitemap_is_swept_when_the_other_pools_are_turned_off(): void
    {
        $sitemap = $this->sitemap('https://gs.construction/');
        Tracked404::create(['path' => '/gone', 'user_agent' => 'Googlebot/2.1', 'hit_count' => 5, 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $this->artisan('seo:gsc-inspect-bulk', ['--sitemap' => $sitemap, '--limit' => 0, '--include' => 'sitemap'])->assertExitCode(0);

        $this->assertSame(['https://gs.construction/'], $this->inspected);
    }

    public function test_it_inspects_only_what_todays_allowance_covers(): void
    {
        config(['services.google.search_console.inspection_daily_quota' => 3]);
        // An import earlier today already spent one.
        UrlInspectionQuota::consume();
        $sitemap = $this->sitemap('https://gs.construction/a', 'https://gs.construction/b', 'https://gs.construction/c', 'https://gs.construction/d');

        $this->artisan('seo:gsc-inspect-bulk', ['--sitemap' => $sitemap, '--limit' => 0])
            ->expectsOutputToContain('Only 2 of 4 URLs fit')
            ->assertExitCode(0);

        $this->assertCount(2, $this->inspected);
        $this->assertSame(0, UrlInspectionQuota::remaining());
    }

    public function test_it_does_not_call_google_at_all_once_the_allowance_is_spent(): void
    {
        config(['services.google.search_console.inspection_daily_quota' => 5]);
        UrlInspectionQuota::markExhausted();
        $sitemap = $this->sitemap('https://gs.construction/a');

        $this->artisan('seo:gsc-inspect-bulk', ['--sitemap' => $sitemap, '--limit' => 0])
            ->expectsOutputToContain('allowance for today is spent')
            ->assertExitCode(0);

        $this->assertSame([], $this->inspected);
    }

    public function test_googles_own_refusal_stops_the_run_and_spends_the_day(): void
    {
        $this->inspectionStatus = 429;
        $sitemap = $this->sitemap('https://gs.construction/a', 'https://gs.construction/b', 'https://gs.construction/c');

        $this->artisan('seo:gsc-inspect-bulk', ['--sitemap' => $sitemap, '--limit' => 0])
            ->expectsOutputToContain('Google refused on quota')
            ->assertExitCode(0);

        $this->assertSame(0, UrlInspectionQuota::remaining(), 'the day is spent, whatever our own count said');
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('app/test-sitemap.xml'));
        parent::tearDown();
    }
}
