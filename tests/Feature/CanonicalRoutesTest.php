<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use App\Services\ZipCodeService;
use App\Support\Seo\CrawlFiles;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Regression coverage for canonical and alias routes:
 *  - /areas-served/{area} is the canonical area URL
 *  - /areas/{area} and /locations/{area} are old aliases that 301 to
 *    /areas-served/{area}, every sub-path included
 *  - /service-area/{zip} should 200 for known ZIPs and 404 for unknown
 *  - Critical static feeds: /ai-feed.json, /geo/answers.json, /llms.txt,
 *    /sitemap.xml all return 200
 */
class CanonicalRoutesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // These tests require the real application schema/data (areas, projects,
        // ZIP map). Skip when running under an empty in-memory test DB.
        try {
            if (! Schema::hasTable('areas_served')) {
                $this->markTestSkipped('areas_served table not present in test database.');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database not available: '.$e->getMessage());
        }
    }

    private function firstAreaSlug(): ?string
    {
        return AreaServed::query()->orderBy('slug')->value('slug');
    }

    public function test_homepage_returns_200(): void
    {
        $this->get('/')->assertStatus(200);
    }

    public function test_area_canonical_route_returns_200(): void
    {
        $slug = $this->firstAreaSlug();
        if (! $slug) {
            $this->markTestSkipped('No areas seeded.');
        }

        $resp = $this->get("/areas-served/{$slug}");
        $resp->assertStatus(200);
        $resp->assertSee('rel="canonical"', false);
    }

    public function test_area_alias_routes_redirect_permanently_to_areas_served(): void
    {
        // Every alias shape Search Console still remembers, sub-paths included.
        foreach ([
            '/areas' => '/areas-served',
            '/locations' => '/areas-served',
            '/areas/north-barrington' => '/areas-served/north-barrington',
            '/locations/north-barrington/projects' => '/areas-served/north-barrington/projects',
            '/areas/lake-forest/services/kitchen-remodeling' => '/areas-served/lake-forest/services/kitchen-remodeling',
        ] as $alias => $target) {
            $this->get($alias)->assertStatus(301)->assertRedirect($target);
        }
    }

    public function test_area_alias_routes_used_to_serve_content_with_canonical_to_areas_served(): void
    {
        $this->markTestSkipped('Superseded: the aliases now 301 (see the test above).');
        $slug = $this->firstAreaSlug();
        if (! $slug) {
            $this->markTestSkipped('No areas seeded.');
        }

        foreach (['/areas/'.$slug, '/locations/'.$slug] as $aliasUrl) {
            $resp = $this->get($aliasUrl);
            $resp->assertStatus(200);
            // Canonical should point at /areas-served/{slug}
            $resp->assertSee('href="'.url('/areas-served/'.$slug).'"', false);
        }
    }

    public function test_area_service_pages_return_200(): void
    {
        $slug = $this->firstAreaSlug();
        if (! $slug) {
            $this->markTestSkipped('No areas seeded.');
        }
        foreach (['kitchen-remodeling', 'bathroom-remodeling', 'home-remodeling'] as $service) {
            $this->get("/areas-served/{$slug}/services/{$service}")->assertStatus(200);
        }
    }

    public function test_service_area_index_returns_200(): void
    {
        $this->get('/service-area')->assertStatus(200);
    }

    public function test_service_area_unknown_zip_returns_404(): void
    {
        $this->get('/service-area/99999')->assertStatus(404);
    }

    public function test_service_area_known_zip_returns_200_with_city(): void
    {
        $map = app(ZipCodeService::class)->getZipMap();
        if (empty($map)) {
            $this->markTestSkipped('No ZIP-mapped projects available.');
        }
        $zip = (string) array_key_first($map);
        $city = $map[$zip]['city'];

        $resp = $this->get('/service-area/'.$zip);
        $resp->assertStatus(200);
        $resp->assertSee($zip);
        $resp->assertSee($city);
    }

    public function test_ai_and_geo_feeds_return_200_json(): void
    {
        $this->get('/ai-feed.json')->assertStatus(200)->assertHeader('content-type', 'application/json');
        $resp = $this->get('/geo/answers.json');
        $resp->assertStatus(200);
        $resp->assertJsonPath('@type', 'FAQPage');
    }

    public function test_the_crawl_files_are_served_per_site(): void
    {
        $this->get('/llms.txt')->assertStatus(200);

        // robots.txt and the sitemaps are routes now, rendered for the tenant
        // that answers the host — they used to be static files that nginx
        // handed to every host of this deployment.
        $this->get('/robots.txt')->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->assertSee('Sitemap: '.\App\Models\Site::current()->url('sitemap.xml'), false);

        $path = CrawlFiles::sitemapPath();
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, '<?xml version="1.0"?><urlset><url><loc>'.url('/').'</loc></url></urlset>');
        $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function test_legacy_service_urls_redirect(): void
    {
        foreach ([
            '/bathroom-remodeling' => '/services/bathroom-remodeling',
            '/kitchen-remodeling' => '/services/kitchen-remodeling',
            '/home-remodeling' => '/services/home-remodeling',
        ] as $from => $to) {
            $this->get($from)->assertRedirect($to);
        }
    }
}
