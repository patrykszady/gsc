<?php

namespace Tests\Feature\Console;

use App\Models\AreaServed;
use App\Models\GscQueryMetric;
use App\Support\SeoStorage;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

/**
 * Smoke tests for the ten thin artisan wrappers around the shared kit's
 * SsSystems\Platform\Reports\* classes (see App\Console\Commands\Seo\
 * KitReportCommand and vendor/ss-systems/platform-kit's docs/
 * REPORTS-PORTING.md). The kit's own test suite
 * (ss-platform-kit/tests/Unit/Reports/*Test.php) already covers each
 * report's algorithm against fakes; these tests pin the SITE half — that
 * this app's adapters actually feed the kit real data, that the thin
 * wrapper writes markdown to the same tenant-scoped path the admin reads
 * (App\Support\SeoStorage::path), and the exit-code rule each command kept
 * from its pre-port self.
 */
class KitReportsTest extends TestCase
{
    use WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
    }

    public function test_content_decay_writes_markdown_and_prints_the_summary(): void
    {
        Storage::fake('local');

        // Prior window: healthy clicks. Recent window: the same page collapses.
        GscQueryMetric::create(['date' => now()->subDays(50)->toDateString(), 'site_url' => 'sc-domain:example.test', 'query' => 'kitchen remodel', 'page' => '/kitchens', 'impressions' => 500, 'clicks' => 100, 'position' => 3.0, 'dim_hash' => 'kit-decay-prior']);
        GscQueryMetric::create(['date' => now()->subDays(2)->toDateString(), 'site_url' => 'sc-domain:example.test', 'query' => 'kitchen remodel', 'page' => '/kitchens', 'impressions' => 400, 'clicks' => 5, 'position' => 3.0, 'dim_hash' => 'kit-decay-recent']);

        $exitCode = Artisan::call('seo:content-decay', ['--markdown' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('click drop(s)', $output);

        $path = SeoStorage::path('reports/content-decay.md');
        $this->assertTrue(Storage::disk('local')->exists($path));
        $this->assertStringStartsWith('# Content decay report', Storage::disk('local')->get($path));
    }

    public function test_health_check_writes_markdown_and_prints_the_summary(): void
    {
        Storage::fake('local');
        config(['app.url' => 'https://example.test']);

        $html = '<html><head><title>'.str_repeat('Kitchen Remodeling Experts In Naperville ', 1).'</title>'
            .'<meta name="description" content="'.str_repeat('a', 100).'">'
            .'<link rel="canonical" href="https://example.test/">'
            .'<script type="application/ld+json">{"@type":"LocalBusiness","name":"x","address":"y"}</script>'
            .'</head><body><h1>Kitchens</h1>'
            .'<img src="/a.jpg" alt="a"><img src="/b.jpg" alt="b"><img src="/c.jpg" alt="c">'
            .'<a href="/contact">Contact</a><a href="/about">About</a><a href="/services">Services</a>'
            .'<p>'.str_repeat('word ', 400).'</p>'
            .'</body></html>';

        Http::fake([
            'example.test/sitemap.xml' => Http::response('<urlset><url><loc>https://example.test/</loc></url></urlset>', 200),
            'example.test/' => Http::response($html, 200),
        ]);

        $exitCode = Artisan::call('seo:health-check', ['--markdown' => true, '--min-score' => 0]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Average score', $output);

        $path = SeoStorage::path('reports/health-check.md');
        $this->assertTrue(Storage::disk('local')->exists($path));
        $this->assertStringStartsWith('# Local SEO health-check', Storage::disk('local')->get($path));
    }

    public function test_schema_audit_writes_markdown_and_prints_the_summary(): void
    {
        Storage::fake('local');
        config(['app.url' => 'https://example.test']);

        $html = '<html><body><script type="application/ld+json">'
            .'{"@type":"LocalBusiness","name":"GS Construction","address":"1 Main St"}'
            .'</script></body></html>';

        Http::fake([
            'example.test/sitemap.xml' => Http::response('<urlset><url><loc>https://example.test/</loc></url></urlset>', 200),
            'example.test/' => Http::response($html, 200),
        ]);

        $exitCode = Artisan::call('seo:schema-audit', ['--markdown' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('URL(s) audited', $output);

        $path = SeoStorage::path('reports/schema-audit.md');
        $this->assertTrue(Storage::disk('local')->exists($path));
        $this->assertStringStartsWith('# Schema markup audit', Storage::disk('local')->get($path));
    }

    /**
     * An empty test DB leaves every pillar unmeasured (degraded status), so
     * one complete area is seeded to give the on-page pillar a real score —
     * enough to exercise the ledger write and the markdown/exit path without
     * needing GSC/GBP/rank-tracker fixtures the other pillars would need.
     */
    public function test_health_writes_markdown_and_prints_the_summary(): void
    {
        Storage::fake('local');
        // Freshness reads real files via storage_path(), not the faked 'local'
        // disk — point it at an empty directory so a sitemap.xml some other
        // paratest run left behind under this worker's shared testing path
        // cannot leak in and make the freshness pillar (and therefore the
        // exit code) depend on filesystem state outside this test.
        config(['seo.crawl_files_root' => sys_get_temp_dir().'/kit-reports-test-'.uniqid()]);
        // AreaServed::saved fires RecrawlNudger::nudge() (AppServiceProvider),
        // which under the suite's QUEUE_CONNECTION=sync would otherwise
        // regenerate a real sitemap.xml synchronously and make the freshness
        // pillar measured too — faking the queue keeps this test's only
        // signal the one it actually seeds.
        Queue::fake();

        AreaServed::create([
            'city' => 'Naperville', 'slug' => 'naperville',
            'intro' => 'Intro copy.', 'local_intro' => 'Local intro copy.', 'landmarks' => 'Some landmarks.',
        ]);

        $exitCode = Artisan::call('seo:health', ['--markdown' => true]);
        $output = Artisan::output();

        // Only on-page is measured (100), the rest unmeasured — degraded
        // status, but the exit code follows the numeric score (>= 70), not
        // the ok/degraded status, exactly like the original seo:health.
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('SEO health:', $output);

        $path = SeoStorage::path('reports/health.md');
        $this->assertTrue(Storage::disk('local')->exists($path));
        $this->assertStringStartsWith('# SEO Health', Storage::disk('local')->get($path));

        $ledger = json_decode(Storage::disk('local')->get(SeoStorage::path('reports/health-history.json')), true);
        $this->assertSame(100, $ledger[now()->toDateString()]);
    }

    public function test_config_seo_reports_carries_all_ten_keys_with_requires(): void
    {
        $reports = config('seo-reports.reports');

        $this->assertSame([
            'content-decay', 'content-gap', 'cwv-template', 'gbp-parity',
            'internal-link-suggest', 'schema-audit', 'area-pages-audit',
            'health-check', 'health', 'clarity-health',
        ], array_keys($reports));

        foreach ($reports as $key => $meta) {
            $this->assertArrayHasKey('requires', $meta, "report \"{$key}\" is missing requires");
            $this->assertIsArray($meta['requires']);
        }
    }

    public function test_regenerate_refuses_a_report_whose_capability_is_not_bound_on_this_site(): void
    {
        Storage::fake('local');
        Http::fake();

        // Simulate a site that never bound the `clarity_metrics` capability
        // (App\Support\Seo\Reports\ReportCapabilities::provided() reads
        // this config key) — clarity-health requires only that one.
        config(['seo-reports.capabilities' => [
            'query_metrics', 'psi_snapshots', 'page_fetcher', 'site_catalog',
            'site_identity', 'area_catalog', 'health_data', 'cache',
        ]]);

        $logPath = storage_path('logs/seo-reports-'.now()->format('Y-m-d').'.log');
        $before = file_exists($logPath) ? filesize($logPath) : 0;

        $data = $this->postJson('/api/admin/v1/seo/reports/clarity-health/regenerate', [], $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertFalse($data['ok']);
        $this->assertSame('unavailable', $data['status']);
        $this->assertNull($data['run']);
        $this->assertSame('Needs visitor behaviour data. Connect it under Connect Services.', $data['message']);
        $this->assertFalse($data['available']);
        $this->assertSame(['clarity_metrics'], $data['missing']);

        $this->assertFileExists($logPath);
        $newLines = substr(file_get_contents($logPath), $before);
        $this->assertStringContainsString('report run refused', $newLines);
        $this->assertStringNotContainsString('report run started', $newLines);
    }
}
