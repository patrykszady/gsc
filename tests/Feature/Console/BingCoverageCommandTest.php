<?php

namespace Tests\Feature\Console;

use App\Models\BingCrawlStat;
use App\Models\BingUrlInfo;
use App\Support\Seo\CrawlFiles;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use SsSystems\Platform\Seo\Bing\Contracts\BingCoverageClient;
use Tests\TestCase;

/**
 * `seo:bing-coverage` end to end: a fake BingCoverageClient (no HTTP
 * behind it, per the contract's own docblock) bound into the container,
 * run against this site's REAL BingCoverageWriter/BingCoverageUrls — the
 * same split SyncBingWebmasterTest uses for seo:bing-sync, just faking the
 * client instead of the HTTP layer underneath it since BingCoverageClient
 * is the kit's new seam built specifically to be faked this way.
 */
class BingCoverageCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function writeSitemap(array $urls): void
    {
        $path = CrawlFiles::sitemapPath();
        @mkdir(dirname($path), 0775, true);
        $body = '<?xml version="1.0"?><urlset>'
            .implode('', array_map(fn ($u) => "<url><loc>{$u}</loc></url>", $urls))
            .'</urlset>';
        file_put_contents($path, $body);
    }

    private function bindFakeClient(array $urlInfoByUrl): void
    {
        $this->app->bind(BingCoverageClient::class, fn () => new class($urlInfoByUrl) implements BingCoverageClient
        {
            public function __construct(private array $urlInfoByUrl) {}

            public function isConfigured(): bool
            {
                return true;
            }

            public function siteUrl(): string
            {
                return 'https://gs.construction/';
            }

            public function crawlStats(): ?array
            {
                return [[
                    'date' => '2026-11-09', 'crawled_pages' => 42, 'in_index' => 38, 'in_links' => 5,
                    'crawl_errors' => 1, 'blocked_by_robots' => 0, 'code_2xx' => 40, 'code_301' => 1,
                    'code_302' => 0, 'code_4xx' => 1, 'code_5xx' => 0, 'other_codes' => 0,
                    'connection_timeouts' => 0, 'dns_failures' => 0,
                ]];
            }

            public function urlInfo(string $url): ?array
            {
                return $this->urlInfoByUrl[$url] ?? [
                    'url' => $url, 'http_status' => 200, 'is_page' => true, 'document_size' => 500,
                    'anchor_count' => 2, 'child_url_count' => 0, 'discovered_on' => '2026-01-01',
                    'last_crawled_at' => null,
                ];
            }

            public function pageStats(): ?array
            {
                return [
                    ['date' => '2026-11-08', 'url' => 'https://gs.construction/a', 'impressions' => 12, 'clicks' => 2, 'position' => 4.0],
                ];
            }

            public function urlSubmissionQuota(): ?array
            {
                return ['daily' => 10, 'monthly' => 300];
            }

            public function userSites(): ?array
            {
                return [['url' => 'https://gs.construction/', 'verified' => true]];
            }

            public function lastError(): ?string
            {
                return null;
            }
        });
    }

    public function test_it_writes_crawl_stats_shown_figures_and_fetch_state_from_the_fake_client(): void
    {
        $this->writeSitemap(['https://gs.construction/a', 'https://gs.construction/b']);
        $this->bindFakeClient([
            'https://gs.construction/a' => [
                'url' => 'https://gs.construction/a', 'http_status' => 200, 'is_page' => true, 'document_size' => 1000,
                'anchor_count' => 5, 'child_url_count' => 0, 'discovered_on' => '2026-10-01',
                'last_crawled_at' => '2026-11-09 10:00:00',
            ],
        ]);

        $this->artisan('seo:bing-coverage')->assertExitCode(0);

        $this->assertSame(1, BingCrawlStat::count());
        $stat = BingCrawlStat::first();
        $this->assertSame(38, $stat->in_index);
        $this->assertSame('https://gs.construction/', $stat->site_url);

        $this->assertSame(2, BingUrlInfo::count());

        $a = BingUrlInfo::where('url', 'https://gs.construction/a')->first();
        $this->assertSame(12, $a->impressions_28d, 'page stats for /a must fold onto its row');
        $this->assertSame(2, $a->clicks_28d);
        $this->assertSame(200, $a->http_status);
        $this->assertNotNull($a->last_crawled_at);
        $this->assertNotNull($a->inspected_at);
        $this->assertTrue($a->in_sitemap);

        $b = BingUrlInfo::where('url', 'https://gs.construction/b')->first();
        $this->assertSame(0, $b->impressions_28d, '/b was never shown, so it reads zero rather than being skipped');
        $this->assertNull($b->last_crawled_at, "the fake's default answer for /b is never-crawled");
        $this->assertNotNull($b->inspected_at);
    }

    public function test_a_url_that_left_the_sitemap_is_marked_not_deleted(): void
    {
        $this->writeSitemap(['https://gs.construction/a']);
        $this->bindFakeClient([]);

        BingUrlInfo::create(['url' => 'https://gs.construction/retired', 'url_hash' => sha1('https://gs.construction/retired'), 'in_sitemap' => true]);

        $this->artisan('seo:bing-coverage')->assertExitCode(0);

        $this->assertSame(2, BingUrlInfo::count());
        $this->assertFalse(BingUrlInfo::where('url', 'https://gs.construction/retired')->first()->in_sitemap);
    }

    public function test_without_a_configured_client_it_skips_plainly_and_writes_nothing(): void
    {
        $this->writeSitemap(['https://gs.construction/a']);

        $this->app->bind(BingCoverageClient::class, fn () => new class implements BingCoverageClient
        {
            public function isConfigured(): bool
            {
                return false;
            }

            public function siteUrl(): string
            {
                return 'https://gs.construction/';
            }

            public function crawlStats(): ?array
            {
                return null;
            }

            public function urlInfo(string $url): ?array
            {
                return null;
            }

            public function pageStats(): ?array
            {
                return null;
            }

            public function urlSubmissionQuota(): ?array
            {
                return null;
            }

            public function userSites(): ?array
            {
                return null;
            }

            public function lastError(): ?string
            {
                return null;
            }
        });

        $this->artisan('seo:bing-coverage')
            ->expectsOutputToContain('Nothing recorded')
            ->assertExitCode(0);

        $this->assertSame(0, BingCrawlStat::count());
        $this->assertSame(0, BingUrlInfo::count());
    }
}
