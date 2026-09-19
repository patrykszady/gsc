<?php

namespace Tests\Feature\Seo;

use App\Services\GoogleSearchConsoleService;
use App\Support\Seo\SitemapStatus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * The SEO screen used to say nothing whatsoever about sitemaps, so "is our
 * sitemap on Search Console?" could only be answered from Google's own UI.
 * These pin the shape the shared ss-systems admin renders — it is ONE view for
 * every tenant, so a key that changes here changes both sites' screens.
 */
class SitemapStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_it_reports_unconfigured_without_a_property(): void
    {
        $snapshot = SitemapStatus::snapshot(null);

        $this->assertSame('unconfigured', $snapshot['state']);
        $this->assertFalse($snapshot['connected']);
        $this->assertSame([], $snapshot['entries']);
    }

    public function test_it_grades_a_healthy_sitemap_as_ok(): void
    {
        $this->fakeService([[
            'path' => 'https://example.test/sitemap.xml',
            'lastSubmitted' => now()->subHours(2)->toIso8601String(),
            'lastDownloaded' => now()->subHours(2)->toIso8601String(),
            'warnings' => 0,
            'errors' => 0,
            'contents' => [['type' => 'web', 'submitted' => 447]],
        ]]);

        $snapshot = SitemapStatus::snapshot('sc-domain:example.test');

        $this->assertSame('ok', $snapshot['state']);
        $this->assertTrue($snapshot['connected']);
        $this->assertSame(447, $snapshot['entries'][0]['urls']);
        $this->assertSame(0, $snapshot['entries'][0]['age_days']);
        $this->assertNotNull($snapshot['entries'][0]['downloaded_age']);
    }

    /**
     * Submitted once and then never re-fetched looks identical to working
     * until you read the date — which is the whole failure this card exists
     * to make visible.
     */
    public function test_a_sitemap_google_stopped_reading_is_stale(): void
    {
        $this->fakeService([[
            'path' => 'https://example.test/sitemap.xml',
            'lastSubmitted' => now()->subMonth()->toIso8601String(),
            'lastDownloaded' => now()->subDays(30)->toIso8601String(),
            'warnings' => 0,
            'errors' => 0,
            'contents' => [['type' => 'web', 'submitted' => 10]],
        ]]);

        $this->assertSame('stale', SitemapStatus::snapshot('sc-domain:example.test')['state']);
    }

    public function test_a_sitemap_google_has_never_fetched_is_stale(): void
    {
        $this->fakeService([[
            'path' => 'https://example.test/sitemap.xml',
            'lastSubmitted' => now()->subHour()->toIso8601String(),
            'lastDownloaded' => null,
            'contents' => [],
        ]]);

        $snapshot = SitemapStatus::snapshot('sc-domain:example.test');

        $this->assertSame('stale', $snapshot['state']);
        $this->assertNull($snapshot['entries'][0]['age_days']);
        $this->assertNull($snapshot['entries'][0]['downloaded_age']);
    }

    public function test_errors_outrank_warnings(): void
    {
        $this->fakeService([[
            'path' => 'https://example.test/sitemap.xml',
            'lastDownloaded' => now()->toIso8601String(),
            'warnings' => 3,
            'errors' => 1,
            'contents' => [],
        ]]);

        $this->assertSame('errors', SitemapStatus::snapshot('sc-domain:example.test')['state']);
    }

    public function test_an_empty_property_reports_none_rather_than_ok(): void
    {
        $this->fakeService([]);

        $this->assertSame('none', SitemapStatus::snapshot('sc-domain:example.test')['state']);
    }

    public function test_a_failed_lookup_is_reported_not_swallowed(): void
    {
        $service = $this->mock(GoogleSearchConsoleService::class);
        $service->shouldReceive('isConfigured')->andReturnTrue();
        $service->shouldReceive('listSitemaps')->andReturnNull();
        $service->shouldReceive('getLastError')->andReturn(['status' => 403, 'message' => 'Insufficient permission']);

        $snapshot = SitemapStatus::snapshot('sc-domain:example.test');

        $this->assertSame('error', $snapshot['state']);
        $this->assertTrue($snapshot['connected']);
        $this->assertSame('Insufficient permission', $snapshot['error']);
    }

    /** The lookup is a remote call, so it must not repeat on every page load. */
    public function test_the_lookup_is_cached_until_forgotten(): void
    {
        $service = $this->mock(GoogleSearchConsoleService::class);
        $service->shouldReceive('isConfigured')->andReturnTrue();
        $service->shouldReceive('listSitemaps')->once()->andReturn([]);

        SitemapStatus::snapshot('sc-domain:example.test');
        SitemapStatus::snapshot('sc-domain:example.test');
    }

    public function test_forget_forces_a_fresh_lookup(): void
    {
        $service = $this->mock(GoogleSearchConsoleService::class);
        $service->shouldReceive('isConfigured')->andReturnTrue();
        $service->shouldReceive('listSitemaps')->twice()->andReturn([]);

        SitemapStatus::snapshot('sc-domain:example.test');
        SitemapStatus::snapshot('sc-domain:example.test', fresh: true);
    }

    /** @param  array<int, array<string, mixed>>  $sitemaps */
    protected function fakeService(array $sitemaps): void
    {
        $service = $this->mock(GoogleSearchConsoleService::class);
        $service->shouldReceive('isConfigured')->andReturnTrue();
        $service->shouldReceive('listSitemaps')->andReturn($sitemaps);
    }
}
