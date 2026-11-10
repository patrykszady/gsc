<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Jobs\RunBingCoverageJob;
use App\Models\BingCrawlStat;
use App\Models\BingUrlInfo;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

/**
 * GET/POST seo/bing-coverage — the hub's Bing card, next to
 * GscErrorControllerTest. index()/refresh() come from the kit's
 * ServesBingCoverage trait (kit 0.22.0); this pins BingCoverageController's
 * own hooks (the two models, the Refresh dispatch) against seeded rows.
 */
class BingCoverageControllerTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
    }

    /** One row per status word serializeUrl() can produce. */
    private function seedRows(): void
    {
        BingCrawlStat::create([
            'date' => '2026-11-09',
            'site_url' => 'https://gs.construction/',
            'crawled_pages' => 42,
            'in_index' => 38,
            'crawl_errors' => 1,
            'blocked_by_robots' => 0,
        ]);

        // shown: Bing displayed it in results, so it counts regardless of fetch state.
        BingUrlInfo::create([
            'url' => 'https://gs.construction/shown-page',
            'url_hash' => sha1('https://gs.construction/shown-page'),
            'in_sitemap' => true,
            'http_status' => 200,
            'impressions_28d' => 5,
            'clicks_28d' => 1,
            'last_crawled_at' => now(),
            'inspected_at' => now(),
        ]);

        // crawled: fetched, never shown.
        BingUrlInfo::create([
            'url' => 'https://gs.construction/crawled-page',
            'url_hash' => sha1('https://gs.construction/crawled-page'),
            'in_sitemap' => true,
            'http_status' => 200,
            'impressions_28d' => 0,
            'last_crawled_at' => now(),
            'inspected_at' => now(),
        ]);

        // never_crawled: asked about, Bing has no fetch on record.
        BingUrlInfo::create([
            'url' => 'https://gs.construction/never-crawled-page',
            'url_hash' => sha1('https://gs.construction/never-crawled-page'),
            'in_sitemap' => true,
            'inspected_at' => now(),
        ]);

        // pending: not asked yet.
        BingUrlInfo::create([
            'url' => 'https://gs.construction/pending-page',
            'url_hash' => sha1('https://gs.construction/pending-page'),
            'in_sitemap' => true,
        ]);

        // error: Bing's last fetch was a 4xx/5xx.
        BingUrlInfo::create([
            'url' => 'https://gs.construction/error-page',
            'url_hash' => sha1('https://gs.construction/error-page'),
            'in_sitemap' => true,
            'http_status' => 404,
            'inspected_at' => now(),
            'inspect_error' => null,
        ]);

        // Out of the sitemap — must never show up in counts or rows.
        BingUrlInfo::create([
            'url' => 'https://gs.construction/retired-page',
            'url_hash' => sha1('https://gs.construction/retired-page'),
            'in_sitemap' => false,
        ]);
    }

    public function test_index_returns_counts_and_rows_with_the_right_status_word_per_row(): void
    {
        $this->seedRows();

        $data = $this->getJson('/api/admin/v1/seo/bing-coverage', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame(38, $data['latest']['in_index']);
        $this->assertSame(5, $data['urls']['total'], 'the retired (out-of-sitemap) row must not be counted');
        $this->assertSame(1, $data['urls']['shown']);
        $this->assertSame(1, $data['urls']['crawled']);
        $this->assertSame(1, $data['urls']['never_crawled'], 'the five statuses partition the sitemap (kit 0.22.1): one tile per row, same precedence as its own status');
        $this->assertSame(1, $data['urls']['pending']);
        $this->assertSame(1, $data['urls']['errors']);

        $statusByUrl = collect($data['rows'])->pluck('status', 'url');
        $this->assertSame('shown', $statusByUrl->get('https://gs.construction/shown-page'));
        $this->assertSame('crawled', $statusByUrl->get('https://gs.construction/crawled-page'));
        $this->assertSame('never_crawled', $statusByUrl->get('https://gs.construction/never-crawled-page'));
        $this->assertSame('pending', $statusByUrl->get('https://gs.construction/pending-page'));
        $this->assertSame('error', $statusByUrl->get('https://gs.construction/error-page'));
        $this->assertFalse($statusByUrl->has('https://gs.construction/retired-page'));
    }

    public function test_index_status_filter_narrows_the_rows(): void
    {
        $this->seedRows();

        $data = $this->getJson('/api/admin/v1/seo/bing-coverage?status=error', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame(['https://gs.construction/error-page'], collect($data['rows'])->pluck('url')->all());
    }

    public function test_index_q_filter_searches_the_url(): void
    {
        $this->seedRows();

        $data = $this->getJson('/api/admin/v1/seo/bing-coverage?q=never-crawled', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame(['https://gs.construction/never-crawled-page'], collect($data['rows'])->pluck('url')->all());
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/admin/v1/seo/bing-coverage')->assertUnauthorized();
    }

    public function test_refresh_answers_202_and_dispatches_the_sync_without_running_it(): void
    {
        Queue::fake();

        $this->postJson('/api/admin/v1/seo/bing-coverage/refresh', [], $this->adminApiHeaders())
            ->assertStatus(202)
            ->assertJsonPath('data.queued', true);

        Queue::assertPushed(RunBingCoverageJob::class);
    }
}
