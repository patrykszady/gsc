<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use Tests\TestCase;

/**
 * The town sub-pages Search Console listed under "Excluded by noindex" are
 * indexable now, and a retired town's lead-pipe page follows its neighbours
 * instead of answering 404.
 */
class AreaSubPagesIndexableTest extends TestCase
{
    private function area(string $city, string $slug, ?float $lat = null, ?float $lng = null): AreaServed
    {
        return AreaServed::create([
            'city' => $city, 'slug' => $slug, 'state' => 'IL', 'latitude' => $lat, 'longitude' => $lng,
            'local_intro' => str_repeat('Unique local copy about the town. ', 60),
        ]);
    }

    public function test_a_towns_sub_pages_and_service_pages_are_served_without_noindex(): void
    {
        $this->area('Kenilworth', 'kenilworth');

        foreach ([
            '/areas-served/kenilworth/about',
            '/areas-served/kenilworth/services',
            '/areas-served/kenilworth/services/basement-remodeling',
            '/areas-served/kenilworth/contact',
        ] as $url) {
            $this->get($url)->assertOk()->assertDontSee('noindex', false);
        }

        // Lists of the neighbours' work in a town with no project or review of
        // its own: served, but kept out of the index.
        foreach ([
            '/areas-served/kenilworth/projects',
            '/areas-served/kenilworth/testimonials',
        ] as $url) {
            $this->get($url)->assertOk()->assertSee('noindex', false);
        }
    }

    public function test_a_retired_towns_lead_pipe_page_redirects_like_its_other_spokes(): void
    {
        $this->area('Tinley Park', 'tinley-park', 41.5731, -87.7845);

        // Orland Park is in the gazetteer but not served; its nearest served town is Tinley Park.
        $this->get('/areas-served/orland-park/lead-pipe-replacement')
            ->assertStatus(301)
            ->assertRedirect('/areas-served/tinley-park/lead-pipe-replacement');
    }
}
