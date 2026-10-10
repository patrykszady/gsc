<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use Tests\TestCase;

/**
 * A past build gave each extra <livewire:projects-grid> on a page its own
 * hashed pagination param (`?08c56c61_page=2`) to avoid colliding with the
 * others. Nothing produces that shape any more — ProjectsGrid::render() and
 * ProjectPhotosGallery::pageName() use plain names ('page', 'page-area-{id}')
 * — but Google still crawls the old URLs it indexed, and robots.txt used to
 * disallow `/*_page=`, which only stopped Google from RE-crawling to see the
 * canonical. A 301 that drops just that param lets it fold the URL in.
 */
class RedirectLegacyPaginationParamsTest extends TestCase
{
    public function test_a_hashed_pagination_param_redirects_to_the_bare_path(): void
    {
        $this->get('/?08c56c61_page=2')
            ->assertStatus(301)
            ->assertRedirect('/');
    }

    public function test_it_strips_only_the_legacy_param_and_keeps_the_rest(): void
    {
        $this->get('/projects?type=kitchen&7db88787_page=3')
            ->assertStatus(301)
            ->assertRedirect('/projects?type=kitchen');
    }

    public function test_more_than_one_legacy_param_at_once_is_fully_stripped(): void
    {
        $this->get('/?08c56c61_page=2&7db88787_page=4')
            ->assertStatus(301)
            ->assertRedirect('/');
    }

    /**
     * Any key ending in `_page` is treated as legacy, not only the 8-hex-char
     * shape. A served town, not a retired one — AppServiceProvider's 'area'
     * route binding 301s a retired town's slug itself (inside
     * SubstituteBindings, which runs before this middleware), which would
     * otherwise race this test's own redirect for an unrelated reason.
     */
    public function test_a_non_hex_key_ending_in_underscore_page_also_redirects(): void
    {
        AreaServed::create(['city' => 'Hawthorn Woods', 'slug' => 'hawthorn-woods', 'state' => 'IL']);

        $this->get('/areas-served/hawthorn-woods?7db88787_page=3')
            ->assertStatus(301)
            ->assertRedirect('/areas-served/hawthorn-woods');
    }

    public function test_plain_page_on_projects_is_left_alone(): void
    {
        $this->get('/projects?page=2')->assertOk();
    }

    public function test_a_request_with_no_legacy_param_is_left_alone(): void
    {
        $this->get('/projects?type=kitchen')->assertOk();
    }

    /**
     * The 301 above is the mechanism that lets Google resolve these URLs now
     * — robots.txt blocking re-crawl was the thing stopping it from working
     * (see tests/Feature/ReviewSlugRedirectTest's superseded version of this
     * assertion).
     */
    public function test_robots_no_longer_disallows_the_old_pagination_param(): void
    {
        $robots = \App\Support\Seo\CrawlFiles::robots();

        $this->assertStringNotContainsString('_page=', $robots);
    }
}
