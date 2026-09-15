<?php

namespace Tests\Feature;

use App\Jobs\RunGscInspectBulkJob;
use App\Jobs\RunGscInspectUrlsJob;
use App\Models\Site;
use App\Services\GoogleSearchConsoleService;
use App\Support\Seo\CrawlFiles;
use App\Support\Seo\SearchConsoleProperty;
use App\Support\SiteConfig;
use App\Support\Tenancy;
use Tests\TestCase;

/**
 * Everything Search Console touches belongs to one site: the property, the
 * OAuth grant, the sitemap, robots.txt, and the jobs that inspect. None of
 * it may leak from gs.construction to another tenant.
 */
class PerSiteSearchConsoleTest extends TestCase
{
    /** The migrated jpeterson row is inactive until launch; a host only resolves for an active site. */
    private function jpd(): Site
    {
        $site = Site::query()->firstOrCreate(['slug' => 'jpeterson'], [
            'name' => 'J. Peterson Design', 'theme' => 'jpeterson', 'hosts' => ['jpeterson-design.com'], 'primary_host' => 'jpeterson-design.com',
        ]);
        $site->forceFill(['is_active' => true, 'hosts' => ['jpeterson-design.com'], 'primary_host' => 'jpeterson-design.com'])->save();
        Site::forgetActive();

        return $site->fresh();
    }

    public function test_each_site_is_its_own_search_console_property(): void
    {
        config(['seo.search_console.site_url' => 'sc-domain:gs.construction']);

        $this->assertSame('sc-domain:gs.construction', SearchConsoleProperty::url());
        $this->assertSame('https://gs.construction', SearchConsoleProperty::baseUrl());

        $jpd = $this->jpd();
        // No override of its own: its domain property, never gs.construction's.
        $this->assertSame('sc-domain:jpeterson-design.com', SearchConsoleProperty::url($jpd));
        Tenancy::for($jpd, fn () => $this->assertSame('sc-domain:jpeterson-design.com', SearchConsoleProperty::url()));

        // A site may name a URL-prefix property in its own settings.
        $jpd->forceFill(['settings' => ['config' => ['seo' => ['search_console' => ['site_url' => 'https://jpeterson-design.com/']]]]])->save();
        SiteConfig::flush();
        $this->assertSame('https://jpeterson-design.com/', SearchConsoleProperty::url($jpd->fresh()));
    }

    public function test_the_env_refresh_token_belongs_to_the_default_site_only(): void
    {
        config(['services.google.search_console.refresh_token' => 'gs-env-token']);
        $service = app(GoogleSearchConsoleService::class);

        $this->assertSame('gs-env-token', $service->getRefreshToken());

        Tenancy::for($this->jpd(), function () use ($service) {
            $this->assertNull($service->getRefreshToken(), 'another site without its own grant is not connected');
            $this->assertFalse($service->isConfigured());
        });
    }

    public function test_robots_and_sitemap_are_answered_for_the_host_that_asks(): void
    {
        $jpd = $this->jpd();
        @mkdir(CrawlFiles::dir(), 0775, true);
        @mkdir(CrawlFiles::dir($jpd), 0775, true);
        file_put_contents(CrawlFiles::sitemapPath(), '<urlset><url><loc>https://gs.construction/</loc></url></urlset>');
        file_put_contents(CrawlFiles::sitemapPath($jpd), '<urlset><url><loc>https://jpeterson-design.com/</loc></url></urlset>');

        $this->get('https://gs.construction/robots.txt')->assertOk()
            ->assertSee('Sitemap: https://gs.construction/sitemap.xml', false)
            ->assertSee('AhrefsBot');
        $this->get('https://jpeterson-design.com/robots.txt')->assertOk()
            ->assertSee('Sitemap: https://jpeterson-design.com/sitemap.xml', false)
            ->assertDontSee('gs.construction');

        $this->get('https://gs.construction/sitemap.xml')->assertOk()->assertSee('https://gs.construction/', false)->assertDontSee('jpeterson');
        $this->get('https://jpeterson-design.com/sitemap.xml')->assertOk()->assertSee('https://jpeterson-design.com/', false)->assertDontSee('gs.construction');

        // A site whose sitemaps have not been generated says so, rather than
        // serving another site's. (Files persist under storage/ between test
        // runs, so remove both explicitly.)
        @unlink(CrawlFiles::sitemapPath($jpd));
        @unlink(CrawlFiles::imageSitemapPath($jpd));
        clearstatcache();
        $this->get('https://jpeterson-design.com/sitemap.xml')->assertNotFound();
        $this->get('https://jpeterson-design.com/image-sitemap.xml')->assertNotFound();
    }

    public function test_inspection_jobs_run_as_the_site_that_queued_them(): void
    {
        $jpd = $this->jpd();

        $this->assertSame(Site::current()->id, (new RunGscInspectBulkJob)->siteId);
        $this->assertSame(Site::current()->id, (new RunGscInspectUrlsJob(['https://gs.construction/']))->siteId);

        Tenancy::for($jpd, function () use ($jpd) {
            $this->assertSame($jpd->id, (new RunGscInspectBulkJob)->siteId);
            $this->assertSame($jpd->id, (new RunGscInspectUrlsJob(['https://jpeterson-design.com/'], 'console', 'Page with redirect'))->siteId);
        });
    }

    public function test_the_sitemap_is_generated_under_the_tenants_own_host(): void
    {
        $jpd = $this->jpd();
        @unlink(CrawlFiles::sitemapPath($jpd));

        // ->run(): a pending artisan call executes lazily, and it must execute
        // while the tenant is still bound, not after Tenancy::for() has restored the default.
        Tenancy::for($jpd, fn () => $this->artisan('sitemap:generate')->assertExitCode(0)->run());

        $xml = (string) file_get_contents(CrawlFiles::sitemapPath($jpd));
        $this->assertStringContainsString('<loc>https://jpeterson-design.com/', $xml);
        $this->assertStringNotContainsString('gs.construction', $xml);
        // Only what her site serves: the shared route table and guide config
        // are gs.construction's, and those paths 404 on her domain.
        $this->assertStringContainsString('<loc>https://jpeterson-design.com/about</loc>', $xml, 'a path both tenants claim');
        foreach (['/reviews', '/compare/', '/trades/', '/permits/', '/costs/', '/faq', '/areas-served'] as $gsOnly) {
            $this->assertStringNotContainsString('jpeterson-design.com'.$gsOnly, $xml, "{$gsOnly} is gs.construction's");
        }
        $this->assertFileDoesNotExist(public_path('sitemap.xml'), 'never public/: nginx would hand one file to every host');
    }
}
