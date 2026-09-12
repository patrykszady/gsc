<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use App\Support\Areas\RetiredAreaRedirect;
use Tests\TestCase;

/** Pages for towns no longer served redirect to the nearest served town; dead old-site paths answer 410. */
class RetiredAreaRedirectTest extends TestCase
{
    private function served(string $name, string $slug, float $lat, float $lng): AreaServed
    {
        return AreaServed::create(['city' => $name, 'slug' => $slug, 'state' => 'IL', 'latitude' => $lat, 'longitude' => $lng]);
    }

    public function test_a_retired_town_redirects_to_its_nearest_served_neighbour_keeping_the_spoke(): void
    {
        $this->served('Tinley Park', 'tinley-park', 41.5734, -87.7845);
        $this->served('Evanston', 'evanston', 42.0451, -87.6877);

        $this->assertSame('/areas-served/tinley-park', RetiredAreaRedirect::target('orland-park'));
        $this->assertSame('/areas-served/tinley-park/services/kitchen-remodeling', RetiredAreaRedirect::target('orland-park', '/services/kitchen-remodeling'));
        $this->assertNull(RetiredAreaRedirect::target('evanston'), 'a served town is never redirected');

        $this->get('/areas-served/orland-park')->assertRedirect('/areas-served/tinley-park');
        $this->get('/areas-served/orland-park/services/kitchen-remodeling')->assertRedirect('/areas-served/tinley-park/services/kitchen-remodeling');
        $this->get('/areas-served/orland-park/testimonials')->assertStatus(301);
    }

    public function test_a_town_the_gazetteer_does_not_know_goes_to_the_areas_index(): void
    {
        $this->served('Evanston', 'evanston', 42.0451, -87.6877);

        $this->assertSame('/areas-served', RetiredAreaRedirect::target('deerpath', '/contact'));
        $this->get('/areas-served/deerpath/contact')->assertRedirect('/areas-served');
    }

    public function test_old_site_paths_with_no_successor_are_gone(): void
    {
        foreach (['/2024/01', '/2024/01/some-post', '/category/projects', '/index.php', '/images/services/bathroom-hero.jpg', '/wp-content/uploads/x.jpg'] as $path) {
            $this->get($path)->assertStatus(410);
        }

        // Live pages a careless pattern could have caught stay live.
        $this->get('/faq')->assertOk();
        $this->get('/feed/updates.atom')->assertOk();
        $this->get('/api/admin/v1/platforms/nope/reviews/sync', ['Accept' => 'application/json'])->assertNotFound();

        // Paths with a successor still redirect, never 410.
        $this->get('/kitchen')->assertRedirect('/services/kitchen-remodeling');
    }
}
