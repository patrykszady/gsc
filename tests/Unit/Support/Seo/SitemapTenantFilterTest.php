<?php

namespace Tests\Unit\Support\Seo;

use App\Models\Site;
use App\Support\ExclusivePaths;
use App\Support\Seo\SitemapTenantFilter;
use Tests\TestCase;

/**
 * The gate that decides whether a discovered URL belongs in a tenant's
 * sitemap. Everything it answers, the coarse path-prefix check could not:
 * 'services' is claimed by two tenants, and a legacy redirect is invisible to
 * a route-table walk.
 */
class SitemapTenantFilterTest extends TestCase
{
    private function gsc(): Site
    {
        return Site::query()->where('slug', 'gsc')->firstOrFail();
    }

    private function jpd(): Site
    {
        return Site::query()->where('slug', 'jpeterson')->firstOrFail();
    }

    public function test_a_service_sub_page_needs_that_slug_in_the_tenants_own_services_config(): void
    {
        foreach (['kitchen-remodeling', 'bathroom-remodeling', 'home-remodeling', 'basement-remodeling', 'home-additions', 'mudroom-remodeling'] as $slug) {
            $this->assertTrue(SitemapTenantFilter::allows("/services/{$slug}", $this->gsc()), "gsc serves /services/{$slug}");
            $this->assertFalse(SitemapTenantFilter::allows("/services/{$slug}", $this->jpd()), "/services/{$slug} 404s on the studio's site");
        }

        // The index itself is a real page on both.
        $this->assertTrue(SitemapTenantFilter::allows('/services', $this->gsc()));
        $this->assertTrue(SitemapTenantFilter::allows('/services', $this->jpd()));
    }

    public function test_a_shared_alias_belongs_to_whichever_tenant_links_it(): void
    {
        // On gs.construction both 301 to /projects and /reviews.
        $this->assertFalse(SitemapTenantFilter::allows('/portfolio', $this->gsc()));
        $this->assertFalse(SitemapTenantFilter::allows('/testimonials', $this->gsc()));

        // On the studio's site they are the real pages, and its nav says so.
        $this->assertTrue(SitemapTenantFilter::allows('/portfolio', $this->jpd()));
        $this->assertTrue(SitemapTenantFilter::allows('/testimonials', $this->jpd()));
    }

    public function test_a_page_family_backed_by_shared_config_stays_with_the_site_that_fills_it(): void
    {
        foreach (['/compare/4ever-remodeling', '/costs/kitchen-remodel-cost', '/insurance-claims/storm-damage', '/trades/tile', '/permits/arlington-heights', '/design-partners'] as $path) {
            $this->assertTrue(SitemapTenantFilter::allows($path, $this->gsc()), "gsc fills {$path}");
            $this->assertFalse(SitemapTenantFilter::allows($path, $this->jpd()), "{$path} is not the studio's content");
        }
    }

    public function test_an_exclusively_claimed_path_is_still_refused(): void
    {
        $this->assertFalse(SitemapTenantFilter::allows('/projects', $this->jpd()));
        $this->assertFalse(SitemapTenantFilter::allows('/reviews', $this->jpd()));
        $this->assertFalse(SitemapTenantFilter::allows('/chicago', $this->gsc()));
    }

    public function test_universal_paths_and_the_home_page_are_left_alone(): void
    {
        foreach (['/', '/contact', '/about'] as $path) {
            $this->assertTrue(SitemapTenantFilter::allows($path, $this->gsc()), $path);
        }

        $this->assertTrue(SitemapTenantFilter::allows('/contact', $this->jpd()));
    }

    /**
     * The default site must come out exactly as the old prefix-only check left
     * it, otherwise this change quietly rewrites the live business's sitemap.
     */
    public function test_the_gate_changes_nothing_for_the_default_site(): void
    {
        $paths = [
            '/', '/about', '/contact', '/services', '/services/kitchen-remodeling',
            '/compare/4ever-remodeling', '/costs/kitchen-remodel-cost', '/trades/tile',
            '/permits/palatine', '/design-partners', '/projects', '/reviews',
            '/areas-served', '/areas-served/palatine', '/service-area/60067',
        ];

        foreach ($paths as $path) {
            $this->assertSame(
                ExclusivePaths::allows('gsc', trim($path, '/')),
                SitemapTenantFilter::allows($path, $this->gsc()),
                "the gate must be a no-op for gs.construction at {$path}",
            );
        }
    }
}
