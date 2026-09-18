<?php

namespace Tests\Feature\Seo;

use App\Models\Site;
use App\Support\Seo\CrawlFiles;
use App\Support\Tenancy;
use Tests\TestCase;

/**
 * What each tenant's sitemap actually contains.
 *
 * The studio's had six of gs.construction's service pages, every one a 404 on
 * her own domain, and was missing /portfolio, /testimonials and her three
 * market pages — which are the site.
 */
class SitemapPerTenantTest extends TestCase
{
    private function jpd(): Site
    {
        $site = Site::query()->where('slug', 'jpeterson')->firstOrFail();
        $site->forceFill(['is_active' => true])->save();
        Site::forgetActive();

        return $site->fresh();
    }

    /** @return list<string> the paths in a tenant's generated sitemap */
    private function generate(Site $site): array
    {
        Tenancy::for($site, function () {
            $this->artisan('sitemap:generate')->assertExitCode(0)->run();
        });

        $path = CrawlFiles::sitemapPath($site);
        $this->assertFileExists($path);
        preg_match_all('/<loc>([^<]+)<\/loc>/', (string) file_get_contents($path), $m);

        return array_map(fn (string $url): string => '/'.trim((string) parse_url($url, PHP_URL_PATH), '/'), $m[1]);
    }

    public function test_the_studios_sitemap_holds_its_own_pages_and_nothing_elses(): void
    {
        $paths = $this->generate($this->jpd());

        foreach (['/portfolio', '/testimonials', '/chicago', '/atlanta', '/south-haven', '/services', '/about', '/contact', '/process'] as $mine) {
            $this->assertContains($mine, $paths, "{$mine} is one of the studio's pages");
        }

        foreach ([
            '/services/kitchen-remodeling', '/services/bathroom-remodeling', '/services/home-remodeling',
            '/services/basement-remodeling', '/services/home-additions', '/services/mudroom-remodeling',
            '/design-partners', '/projects', '/reviews', '/areas-served',
        ] as $theirs) {
            $this->assertNotContains($theirs, $paths, "{$theirs} 404s on the studio's site");
        }

        @unlink(CrawlFiles::sitemapPath($this->jpd()));
    }

    public function test_the_default_sites_own_pages_are_untouched(): void
    {
        $paths = $this->generate(Site::query()->where('slug', 'gsc')->firstOrFail());

        foreach ([
            '/', '/about', '/contact', '/services', '/services/kitchen-remodeling',
            '/services/bathroom-remodeling', '/design-partners', '/areas-served',
        ] as $expected) {
            $this->assertContains($expected, $paths, "gs.construction still advertises {$expected}");
        }

        // Its own aliases stay out: both 301 on this site.
        $this->assertNotContains('/portfolio', $paths);
        $this->assertNotContains('/testimonials', $paths);

        // And another tenant's market pages never appear here.
        foreach (['/chicago', '/atlanta', '/south-haven'] as $notMine) {
            $this->assertNotContains($notMine, $paths, "{$notMine} belongs to the studio");
        }
    }
}
