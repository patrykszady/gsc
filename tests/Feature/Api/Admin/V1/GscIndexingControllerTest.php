<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Jobs\RunGscInspectUrlsJob;
use App\Models\GscCoverageState;
use App\Models\OAuthToken;
use App\Models\Tracked404;
use App\Support\Seo\UrlInspectionQuota;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Search Console surface the central admin reads and writes: the
 * Page-indexing breakdown, a Console export inspected on import, one URL
 * inspected on demand, and the property's sitemaps.
 */
class GscIndexingControllerTest extends TestCase
{
    protected function bearer(): array
    {
        config(['services.admin_api.token' => 'test-admin-api-token']);

        return ['Authorization' => 'Bearer test-admin-api-token', 'Accept' => 'application/json'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://gs.construction', 'services.google.search_console.site_url' => 'sc-domain:gs.construction', 'services.google.search_console.client_id' => 'id', 'services.google.search_console.client_secret' => 'secret']);
    }

    private function coverage(string $url, ?string $state, string $verdict = 'NEUTRAL', string $source = 'sitemap', ?string $reason = null): GscCoverageState
    {
        return GscCoverageState::create(['url' => $url, 'source' => $source, 'verdict' => $verdict, 'coverage_state' => $state, 'console_reason' => $reason, 'inspected_at' => now()]);
    }

    public function test_the_breakdown_groups_by_googles_reason_and_adds_googlebot_404s_and_robots_rules(): void
    {
        $this->coverage('https://gs.construction/', 'Submitted and indexed', 'PASS');
        $this->coverage('https://gs.construction/projects', 'Submitted and indexed', 'PASS');
        $this->coverage('https://gs.construction/areas-served/x/projects', 'Crawled - currently not indexed');
        $this->coverage('https://gs.construction/areas-served/y/projects', 'Crawled - currently not indexed');
        $this->coverage('https://gs.construction/old-page', null, 'NEUTRAL', 'console', 'Page with redirect');
        Tracked404::create(['path' => '/areas-served/orland-park', 'user_agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1)', 'hit_count' => 159, 'first_seen_at' => now(), 'last_seen_at' => now()]);
        Tracked404::create(['path' => '/env.js', 'user_agent' => 'curl/8', 'hit_count' => 9, 'first_seen_at' => now(), 'last_seen_at' => now()]);

        $data = $this->getJson('/api/admin/v1/seo/gsc-errors/indexing', $this->bearer())->assertOk()->json('data');
        $byReason = collect($data['reasons'])->keyBy('reason');

        $this->assertSame(2, $byReason['Crawled - currently not indexed']['total']);
        $this->assertSame(['sitemap' => 2], $byReason['Crawled - currently not indexed']['sources']);
        $this->assertSame(1, $byReason['Page with redirect']['total'], 'a Console-imported row is grouped under the reason it was exported with');
        $this->assertSame(1, $byReason['Not found (404), seen by Googlebot']['total'], 'only Googlebot hits count, not the bots probing /env.js');
        $this->assertSame('https://gs.construction/areas-served/orland-park', $byReason['Not found (404), seen by Googlebot']['sample'][0]['url']);
        $this->assertSame(159, $byReason['Not found (404), seen by Googlebot']['sample'][0]['hits']);
        $this->assertGreaterThan(0, $byReason['Blocked by robots.txt']['total']);
        $this->assertTrue($byReason['Submitted and indexed']['indexed']);
        $this->assertSame('Submitted and indexed', end($data['reasons'])['reason'], 'indexed pages sort last, problems first');
        $this->assertSame(['sitemap' => 4, 'console' => 1, 'tracked' => 0], $data['sources']);
    }

    public function test_a_console_export_queues_its_urls_for_inspection_and_ignores_other_hosts(): void
    {
        Bus::fake();
        config(['app.url' => 'https://gs.construction']);
        $csv = "URL,Last crawled\nhttps://gs.construction/areas-served/orland-park,2026-08-01\n\"https://gs.construction/old\",2026-07-01\nhttps://example.com/not-ours,2026-07-01\nhttps://gs.construction/areas-served/orland-park,dup\n";

        $this->postJson('/api/admin/v1/seo/gsc-errors/import', ['csv' => $csv, 'reason' => 'Not found (404)'], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.queued', 2)
            ->assertJsonPath('data.skipped', 1);

        Bus::assertDispatched(RunGscInspectUrlsJob::class, fn (RunGscInspectUrlsJob $job) => $job->urls === ['https://gs.construction/areas-served/orland-park', 'https://gs.construction/old']
            && $job->source === 'console' && $job->reason === 'Not found (404)');

        $this->postJson('/api/admin/v1/seo/gsc-errors/import', ['csv' => "URL\nhttps://elsewhere.test/x\n"], $this->bearer())
            ->assertOk()->assertJsonPath('data.queued', 0);
        Bus::assertDispatchedTimes(RunGscInspectUrlsJob::class, 1);
    }

    public function test_one_url_is_inspected_on_demand_and_kept_as_a_coverage_row(): void
    {
        OAuthToken::create(['provider' => 'google_search_console', 'refresh_token' => 'r', 'access_token' => 'a', 'access_token_expires_at' => now()->addHour(), 'scopes' => ['https://www.googleapis.com/auth/webmasters']]);
        Http::fake([
            'searchconsole.googleapis.com/v1/urlInspection/index:inspect' => Http::response(['inspectionResult' => [
                'inspectionResultLink' => 'https://search.google.com/search-console/inspect?resource_id=sc-domain:gs.construction&id=1',
                'indexStatusResult' => ['verdict' => 'NEUTRAL', 'coverageState' => 'Crawled - currently not indexed', 'robotsTxtState' => 'ALLOWED', 'indexingState' => 'INDEXING_ALLOWED', 'pageFetchState' => 'SUCCESSFUL', 'lastCrawlTime' => '2026-09-01T10:00:00Z', 'userCanonical' => 'https://gs.construction/p', 'googleCanonical' => 'https://gs.construction/p', 'crawledAs' => 'MOBILE'],
            ]]),
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'a', 'expires_in' => 3600]),
        ]);

        $this->postJson('/api/admin/v1/seo/gsc-errors/inspect', ['url' => 'https://gs.construction/p'], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.index.coverage_state', 'Crawled - currently not indexed')
            ->assertJsonPath('data.index.crawled_as', 'MOBILE');

        $row = GscCoverageState::query()->where('url', 'https://gs.construction/p')->first();
        $this->assertNotNull($row);
        $this->assertSame('console', $row->source);
        $this->assertSame('Crawled - currently not indexed', $row->coverage_state);

        $this->postJson('/api/admin/v1/seo/gsc-errors/inspect', ['url' => 'not a url'], $this->bearer())->assertStatus(422);
    }

    /**
     * Search Console publishes no coverage report over its API, so every row
     * of our own breakdown costs one URL Inspection call. The allowance is
     * the property's and three callers share it, so the breakdown reports
     * what is left.
     */
    public function test_the_breakdown_reports_what_is_left_of_todays_inspection_allowance(): void
    {
        config(['services.google.search_console.inspection_daily_quota' => 2000]);
        UrlInspectionQuota::consume(828);

        $quota = $this->getJson('/api/admin/v1/seo/gsc-errors/indexing', $this->bearer())
            ->assertOk()->json('data.quota');

        $this->assertSame(828, $quota['used']);
        $this->assertSame(1172, $quota['remaining']);
        $this->assertSame(2000, $quota['daily_limit']);
    }

    public function test_an_import_queues_only_what_todays_allowance_covers_and_says_what_it_deferred(): void
    {
        Bus::fake();
        config(['services.google.search_console.inspection_daily_quota' => 10]);
        UrlInspectionQuota::consume(8);

        $csv = "URL\n".collect(range(1, 5))->map(fn ($i) => "https://gs.construction/p{$i}")->implode("\n");

        $data = $this->postJson('/api/admin/v1/seo/gsc-errors/import', ['csv' => $csv, 'reason' => 'Page with redirect'], $this->bearer())
            ->assertOk()->json('data');

        $this->assertSame(2, $data['queued'], 'only what is left');
        $this->assertSame(3, $data['deferred']);
        Bus::assertDispatched(RunGscInspectUrlsJob::class, fn (RunGscInspectUrlsJob $job) => count($job->urls) === 2);
    }

    public function test_an_import_with_no_allowance_left_queues_nothing_and_explains_why(): void
    {
        Bus::fake();
        config(['services.google.search_console.inspection_daily_quota' => 5]);
        UrlInspectionQuota::markExhausted();

        $data = $this->postJson('/api/admin/v1/seo/gsc-errors/import', ['csv' => "URL\nhttps://gs.construction/p1"], $this->bearer())
            ->assertOk()->json('data');

        $this->assertSame(0, $data['queued']);
        $this->assertSame(1, $data['deferred']);
        $this->assertStringContainsString('allowance is spent', $data['message']);
        Bus::assertNothingDispatched();
    }

    public function test_inspecting_one_url_spends_from_the_allowance_and_is_refused_once_it_is_gone(): void
    {
        config(['services.google.search_console.inspection_daily_quota' => 1]);
        OAuthToken::create(['provider' => 'google_search_console', 'refresh_token' => 'r', 'access_token' => 'a', 'expires_at' => now()->addHour(), 'scopes' => ['https://www.googleapis.com/auth/webmasters']]);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'a', 'expires_in' => 3600]),
            'searchconsole.googleapis.com/*' => Http::response(['inspectionResult' => ['indexStatusResult' => ['verdict' => 'PASS', 'coverageState' => 'Submitted and indexed']]]),
        ]);

        $this->postJson('/api/admin/v1/seo/gsc-errors/inspect', ['url' => 'https://gs.construction/a'], $this->bearer())
            ->assertOk()->assertJsonPath('data.ok', true);
        $this->assertSame(0, UrlInspectionQuota::remaining());

        // The second call would be refused by Google; refuse it here instead,
        // with a message that says when it can be retried.
        $this->postJson('/api/admin/v1/seo/gsc-errors/inspect', ['url' => 'https://gs.construction/b'], $this->bearer())
            ->assertStatus(429)
            ->assertJsonPath('data.ok', false);
    }

    public function test_sitemaps_are_listed_submitted_and_deleted_through_the_api(): void
    {
        OAuthToken::create(['provider' => 'google_search_console', 'refresh_token' => 'r', 'access_token' => 'a', 'access_token_expires_at' => now()->addHour(), 'scopes' => ['https://www.googleapis.com/auth/webmasters']]);
        Http::fake([
            'searchconsole.googleapis.com/webmasters/v3/sites/*/sitemaps/*' => Http::response('', 204),
            'searchconsole.googleapis.com/webmasters/v3/sites/*/sitemaps' => Http::response(['sitemap' => [
                ['path' => 'https://gs.construction/sitemap.xml', 'lastSubmitted' => '2026-09-11T00:30:00Z', 'lastDownloaded' => '2026-09-11T01:00:00Z', 'isPending' => false, 'isSitemapsIndex' => false, 'type' => 'sitemap', 'warnings' => '0', 'errors' => '0', 'contents' => [['type' => 'web', 'submitted' => '836', 'indexed' => '665']]],
            ]]),
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'a', 'expires_in' => 3600]),
        ]);

        $listed = $this->getJson('/api/admin/v1/seo/gsc-errors/sitemaps', $this->bearer())->assertOk();
        $listed->assertJsonPath('data.available', true, 'sitemaps unavailable: '.json_encode($listed->json('data.message')))
            ->assertJsonPath('data.sitemaps.0.path', 'https://gs.construction/sitemap.xml')
            ->assertJsonPath('data.sitemaps.0.contents.0.submitted', 836)
            ->assertJsonPath('data.sitemaps.0.contents.0.indexed', 665);

        $this->postJson('/api/admin/v1/seo/gsc-errors/sitemaps', ['url' => 'https://gs.construction/image-sitemap.xml'], $this->bearer())->assertOk()->assertJsonPath('data.ok', true);
        $this->deleteJson('/api/admin/v1/seo/gsc-errors/sitemaps', ['url' => 'https://gs.construction/old-sitemap.xml'], $this->bearer())->assertOk()->assertJsonPath('data.ok', true);

        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), rawurlencode('https://gs.construction/image-sitemap.xml')));
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), rawurlencode('https://gs.construction/old-sitemap.xml')));
    }
}
