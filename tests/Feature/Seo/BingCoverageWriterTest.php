<?php

namespace Tests\Feature\Seo;

use App\Models\BingCrawlStat;
use App\Models\BingUrlInfo;
use App\Models\Site;
use App\Support\Seo\BingCoverageWriter;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use SsSystems\Platform\Seo\Bing\Contracts\CountsSitemapUrls;
use Tests\TestCase;

/**
 * App\Support\Seo\BingCoverageWriter — the kit's BingCoverageSync writes
 * through this, next to BingAndIndexNowTest's coverage of the older
 * BingWriter/BingSitemapSubmitter. Each method pinned against the exact
 * behavior its interface docblock promises.
 */
class BingCoverageWriterTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function writer(): BingCoverageWriter
    {
        return new BingCoverageWriter;
    }

    public function test_upsert_crawl_stat_is_idempotent_per_date_and_site_url(): void
    {
        $row = [
            'date' => '2026-11-09',
            'site_url' => 'https://gs.construction/',
            'crawled_pages' => 10,
            'in_index' => 8,
            'in_links' => 2,
            'crawl_errors' => 0,
            'blocked_by_robots' => 0,
            'code_2xx' => 10,
            'code_301' => 0,
            'code_302' => 0,
            'code_4xx' => 0,
            'code_5xx' => 0,
            'other_codes' => 0,
            'connection_timeouts' => 0,
            'dns_failures' => 0,
        ];

        $this->writer()->upsertCrawlStat($row);
        $this->assertSame(1, BingCrawlStat::count());
        $this->assertSame(8, BingCrawlStat::first()->in_index);

        // A re-run for the same day updates the one row rather than creating a second.
        // Overrides first: array + array keeps the LEFT side's value for a
        // shared key, so $row + [...] here would silently keep the old figures.
        $this->writer()->upsertCrawlStat(['in_index' => 9, 'crawled_pages' => 11] + $row);

        $this->assertSame(1, BingCrawlStat::count());
        $this->assertSame(9, BingCrawlStat::first()->in_index);
        $this->assertSame('2026-11-09', BingCrawlStat::first()->date->toDateString());
    }

    public function test_upsert_url_info_merges_shown_figures_then_fetch_figures_onto_one_row(): void
    {
        $url = 'https://gs.construction/remodeling';
        $hash = sha1($url);

        $this->writer()->upsertUrlInfo([
            'url' => $url,
            'url_hash' => $hash,
            'in_sitemap' => true,
            'impressions_28d' => 14,
            'clicks_28d' => 2,
            'last_shown_on' => '2026-11-08',
        ]);

        $this->assertSame(1, BingUrlInfo::count());

        $this->writer()->upsertUrlInfo([
            'url' => $url,
            'url_hash' => $hash,
            'http_status' => 200,
            'is_page' => true,
            'discovered_on' => '2026-01-01',
            'last_crawled_at' => '2026-11-09 03:00:00',
            'inspected_at' => '2026-11-09 03:00:01',
            'inspect_error' => null,
        ]);

        $this->assertSame(1, BingUrlInfo::count(), 'the fetch call must land on the SAME row, keyed on url_hash');

        $row = BingUrlInfo::first();
        // The shown figures from the first call must survive the second,
        // keys-not-given call untouched.
        $this->assertSame(14, $row->impressions_28d);
        $this->assertSame(2, $row->clicks_28d);
        $this->assertSame('2026-11-08', $row->last_shown_on->toDateString());
        $this->assertTrue($row->in_sitemap);
        // And the fetch figures from the second call are now there too.
        $this->assertSame(200, $row->http_status);
        $this->assertTrue($row->is_page);
        $this->assertNotNull($row->last_crawled_at);
    }

    public function test_urls_to_inspect_orders_never_asked_first_then_oldest(): void
    {
        $urls = [
            'https://gs.construction/never-asked',
            'https://gs.construction/asked-recently',
            'https://gs.construction/asked-long-ago',
            'https://gs.construction/also-never-asked',
        ];

        BingUrlInfo::create(['url' => $urls[1], 'url_hash' => sha1($urls[1]), 'in_sitemap' => true, 'inspected_at' => now()->subDay()]);
        BingUrlInfo::create(['url' => $urls[2], 'url_hash' => sha1($urls[2]), 'in_sitemap' => true, 'inspected_at' => now()->subDays(10)]);
        // $urls[0] and $urls[3] have no row at all yet — "never asked" covers that too.

        $ordered = $this->writer()->urlsToInspect($urls, 10);

        $this->assertSame(
            [$urls[0], $urls[3], $urls[2], $urls[1]],
            $ordered,
            'never-inspected URLs first (in sitemap order), then oldest inspected_at to newest'
        );
    }

    public function test_urls_to_inspect_respects_the_limit(): void
    {
        $urls = ['https://gs.construction/a', 'https://gs.construction/b', 'https://gs.construction/c'];

        $ordered = $this->writer()->urlsToInspect($urls, 2);

        $this->assertCount(2, $ordered);
    }

    public function test_forget_urls_not_in_marks_without_deleting_and_returns_the_count(): void
    {
        $kept = 'https://gs.construction/still-in-sitemap';
        $dropped1 = 'https://gs.construction/left-the-sitemap-1';
        $dropped2 = 'https://gs.construction/left-the-sitemap-2';

        BingUrlInfo::create(['url' => $kept, 'url_hash' => sha1($kept), 'in_sitemap' => true]);
        BingUrlInfo::create(['url' => $dropped1, 'url_hash' => sha1($dropped1), 'in_sitemap' => true]);
        BingUrlInfo::create(['url' => $dropped2, 'url_hash' => sha1($dropped2), 'in_sitemap' => true]);

        $count = $this->writer()->forgetUrlsNotIn([$kept]);

        $this->assertSame(2, $count);
        $this->assertSame(3, BingUrlInfo::count(), 'rows must never be deleted');
        $this->assertTrue(BingUrlInfo::where('url', $kept)->first()->in_sitemap);
        $this->assertFalse(BingUrlInfo::where('url', $dropped1)->first()->in_sitemap);
        $this->assertFalse(BingUrlInfo::where('url', $dropped2)->first()->in_sitemap);

        // Already-forgotten rows are not re-counted on a second run that still omits them.
        $this->assertSame(0, $this->writer()->forgetUrlsNotIn([$kept]));
    }

    /**
     * The kit's optional CountsSitemapUrls (kit 0.28.0): the figure
     * SitemapShrinkGuard compares a sitemap list with — see
     * BingCoverageCommandTest for the guard itself.
     */
    public function test_sitemap_url_count_is_this_tenants_urls_still_marked_as_in_the_sitemap(): void
    {
        $this->assertInstanceOf(CountsSitemapUrls::class, $this->writer());
        $this->assertSame(0, $this->writer()->sitemapUrlCount());

        foreach (['a' => true, 'b' => true, 'left' => false] as $path => $inSitemap) {
            $url = 'https://gs.construction/'.$path;
            BingUrlInfo::create(['url' => $url, 'url_hash' => sha1($url), 'in_sitemap' => $inSitemap]);
        }

        $other = Site::query()->where('slug', 'jpeterson')->firstOrFail();
        Tenancy::for($other, function (): void {
            foreach (['a', 'b', 'c', 'd'] as $path) {
                $url = 'https://jpeterson-design.com/'.$path;
                BingUrlInfo::create(['url' => $url, 'url_hash' => sha1($url), 'in_sitemap' => true]);
            }
        });

        $this->assertSame(2, $this->writer()->sitemapUrlCount(), "neither the URL that left nor the other tenant's four");
        $this->assertSame(4, Tenancy::for($other, fn (): int => $this->writer()->sitemapUrlCount()));
    }
}
