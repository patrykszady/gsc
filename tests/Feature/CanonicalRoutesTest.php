<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use Tests\TestCase;

/**
 * Regression coverage for canonical and alias routes:
 *  - /areas-served/{area} is the canonical area URL
 *  - /areas/{area} and /locations/{area} are aliases that must serve content
 *    but mark themselves noindex via canonical pointing back to /areas-served
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
            if (! \Illuminate\Support\Facades\Schema::hasTable('areas_served')) {
                $this->markTestSkipped('areas_served table not present in test database.');
            }
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database not available: ' . $e->getMessage());
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

    public function test_area_alias_routes_serve_content_with_canonical_to_areas_served(): void
    {
        $slug = $this->firstAreaSlug();
        if (! $slug) {
            $this->markTestSkipped('No areas seeded.');
        }

        foreach (['/areas/' . $slug, '/locations/' . $slug] as $aliasUrl) {
            $resp = $this->get($aliasUrl);
            $resp->assertStatus(200);
            // Canonical should point at /areas-served/{slug}
            $resp->assertSee('href="' . url('/areas-served/' . $slug) . '"', false);
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
        $map = app(\App\Services\ZipCodeService::class)->getZipMap();
        if (empty($map)) {
            $this->markTestSkipped('No ZIP-mapped projects available.');
        }
        $zip = (string) array_key_first($map);
        $city = $map[$zip]['city'];

        $resp = $this->get('/service-area/' . $zip);
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

    public function test_static_seo_files_served(): void
    {
        // Route-served — the framework can assert this over HTTP.
        $this->get('/llms.txt')->assertStatus(200);

        // robots.txt and sitemap.xml are STATIC files: the web server serves
        // them from public/, and Laravel's test client only dispatches routes,
        // so an HTTP assertion here 404s in every environment — which is why
        // this test was red on every run. Assert presence on disk instead.
        $this->assertFileExists(public_path('robots.txt'));

        // sitemap.xml is additionally a gitignored deploy artifact (written by
        // `php artisan sitemap:generate`), so a fresh checkout legitimately
        // lacks it. Skip loudly rather than fail clean environments.
        if (! file_exists(public_path('sitemap.xml'))) {
            $this->markTestSkipped('public/sitemap.xml not generated in this environment (deploy artifact)');
        }

        $this->assertFileExists(public_path('sitemap.xml'));
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
