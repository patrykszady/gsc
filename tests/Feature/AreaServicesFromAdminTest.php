<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use App\Models\AreaServiceContent;
use App\Models\Service;
use App\Models\Site;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Areas × services (Patryk, 2026-11-03): the services each town has pages
 * for are the admin's Services screen (/admin/gsc/services — App\Models\
 * Service), not a list in code. A service row maps to its public service
 * page by project type (ServiceCatalog); one with no page yet is left out
 * of every town and the admin API says so.
 */
class AreaServicesFromAdminTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function town(string $city, string $slug): AreaServed
    {
        return AreaServed::create([
            'city' => $city, 'slug' => $slug, 'state' => 'IL',
            'local_intro' => str_repeat("{$city} copy about the town. ", 40),
        ]);
    }

    public function test_a_towns_services_are_the_admins_services_in_the_admins_order(): void
    {
        Service::create(['name' => 'Mudroom / Laundry', 'slug' => 'mudroom', 'sort_order' => 1]);
        Service::create(['name' => 'Kitchen Remodel', 'slug' => 'kitchen', 'sort_order' => 2]);
        $kenilworth = $this->town('Kenilworth', 'kenilworth');
        Cache::flush();

        $this->assertSame(['mudroom-remodeling', 'kitchen-remodeling'], $kenilworth->serviceSlugs());

        $this->get('/areas-served/kenilworth/services/kitchen-remodeling')->assertOk();
        $this->get('/areas-served/kenilworth/services/mudroom-remodeling')->assertOk();
        $this->get('/areas-served/kenilworth/services/bathroom-remodeling')->assertNotFound();
        $this->get('/areas-served/kenilworth/services/kitchens')->assertStatus(301)
            ->assertRedirect('/areas-served/kenilworth/services/kitchen-remodeling');

        $page = $this->get('/areas-served/kenilworth')->assertOk();
        $page->assertSee('/areas-served/kenilworth/services/mudroom-remodeling', false);
        $page->assertDontSee('/areas-served/kenilworth/services/bathroom-remodeling', false);
    }

    public function test_a_service_without_a_public_page_is_left_out_of_every_town_and_the_admin_api_says_so(): void
    {
        Service::create(['name' => 'Kitchen Remodel', 'slug' => 'kitchen', 'sort_order' => 1]);
        $exterior = Service::create(['name' => 'Exterior/Siding', 'slug' => 'exterior', 'sort_order' => 2]);
        $kenilworth = $this->town('Kenilworth', 'kenilworth');
        Cache::flush();

        $this->assertSame(['kitchen-remodeling'], $kenilworth->serviceSlugs());
        $this->assertNull($exterior->url());
        $this->assertNull($exterior->toApiArray()['public_url']);
        $this->assertSame(url('/services/kitchen-remodeling'), Service::firstWhere('slug', 'kitchen')->toApiArray()['public_url']);
        $this->get('/areas-served/kenilworth/services/exterior')->assertNotFound();
    }

    public function test_with_no_services_in_admin_yet_every_catalog_service_with_a_project_type_counts(): void
    {
        $this->assertSame(0, Service::count());

        Tenancy::for(Site::query()->where('slug', 'gsc')->firstOrFail(), function (): void {
            $this->assertSame(
                ['kitchen-remodeling', 'bathroom-remodeling', 'basement-remodeling', 'home-additions', 'home-remodeling', 'mudroom-remodeling'],
                AreaServiceContent::services(),
            );
        });
    }

    /**
     * A bare console run (`php artisan sitemap:generate` on the box) binds no
     * site; the catalog then loads the default site's config itself, so the
     * list is never empty there — on 2026-11-04 it was, and the prod sitemap
     * lost every town service page.
     */
    public function test_the_list_is_the_same_with_no_site_bound_as_after_a_request_binds_one(): void
    {
        Service::create(['name' => 'Kitchen Remodel', 'slug' => 'kitchen']);

        $this->assertFalse(app()->bound('site.current'));
        $this->assertSame(['kitchen-remodeling'], AreaServiceContent::services());
        $this->get('/areas-served')->assertOk();
        $this->assertSame(['kitchen-remodeling'], AreaServiceContent::services());
    }
}
