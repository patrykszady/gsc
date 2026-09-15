<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use App\Models\Project;
use App\Models\ProjectImage;
use App\Models\Testimonial;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Every URL Search Console still holds gets one right final answer: a single
 * 301 to the page that exists, or a 410 when nothing ever will. Built from
 * the Console's own exports of 2026-09-14 (redirect, not-found, duplicate).
 */
class LegacyUrlAnswersTest extends TestCase
{
    private function town(string $city, string $slug, ?float $lat = null, ?float $lng = null): AreaServed
    {
        return AreaServed::create(['city' => $city, 'slug' => $slug, 'state' => 'IL', 'latitude' => $lat, 'longitude' => $lng, 'local_intro' => str_repeat('Local copy. ', 60)]);
    }

    /**
     * The 'area' route binding hands these closures a town MODEL, and PHP
     * coerces a model to its JSON when the closure says `string` — 230 URLs
     * were redirecting into /areas-served/{"id":43,…} for two days.
     */
    public function test_old_town_service_urls_redirect_once_to_the_canonical_page_never_into_json(): void
    {
        $this->town('Kenilworth', 'kenilworth');

        foreach ([
            '/areas-served/kenilworth/services/kitchens' => '/areas-served/kenilworth/services/kitchen-remodeling',
            '/areas-served/kenilworth/services/bathrooms' => '/areas-served/kenilworth/services/bathroom-remodeling',
            '/areas-served/kenilworth/kitchen-remodeling' => '/areas-served/kenilworth/services/kitchen-remodeling',
            '/areas-served/kenilworth/home-remodeling' => '/areas-served/kenilworth/services/home-remodeling',
            '/areas-served/kenilworth/basement-remodeling' => '/areas-served/kenilworth/services/basement-remodeling',
            '/areas-served/kenilworth/services/mudroom-remodeling' => '/services/mudroom-remodeling',
        ] as $old => $target) {
            $response = $this->get($old);
            $response->assertStatus(301)->assertRedirect($target);
            $this->assertStringNotContainsString('{', (string) $response->headers->get('Location'), "$old must not leak the model");
        }
    }

    public function test_a_retired_towns_old_service_url_is_one_hop_to_its_neighbours_canonical_page(): void
    {
        $this->town('Tinley Park', 'tinley-park', 41.5731, -87.7845);

        // Orland Park is in the gazetteer, not served; Tinley Park is nearest.
        $this->get('/areas-served/orland-park/services/kitchens')->assertStatus(301)->assertRedirect('/areas-served/tinley-park/services/kitchen-remodeling');
        $this->get('/areas-served/orland-park/home-remodeling')->assertStatus(301)->assertRedirect('/areas-served/tinley-park/services/home-remodeling');
        $this->get('/areas-served/orland-park/services/mudroom-remodeling')->assertStatus(301)->assertRedirect('/services/mudroom-remodeling');
    }

    public function test_a_served_towns_lead_pipe_page_is_a_page_again(): void
    {
        $this->town('Kenilworth', 'kenilworth');

        // No official municipal data in the test tree, so it renders the
        // generic guide under noindex — but it renders, it does not redirect.
        $this->get('/areas-served/kenilworth/lead-pipe-replacement')->assertOk()->assertSee('Kenilworth');
    }

    public function test_a_project_addressed_by_its_old_id_is_sent_to_its_slug_and_a_gone_id_answers_410(): void
    {
        $project = Project::create(['title' => 'Palatine Kitchen', 'slug' => 'palatine-kitchen-remodel', 'project_type' => 'kitchen', 'location' => 'Palatine, IL', 'is_published' => true]);

        $this->get("/projects/{$project->id}")->assertStatus(301)->assertRedirect('/projects/palatine-kitchen-remodel');
        $this->get('/projects/99999')->assertStatus(410);
    }

    public function test_a_review_that_is_gone_answers_410_and_an_old_testimonials_link_lands_on_the_real_review(): void
    {
        $review = Testimonial::create(['reviewer_name' => 'Kathy M.', 'project_location' => 'Hoffman Estates, IL', 'project_type' => 'kitchen', 'review_description' => 'Wonderful kitchen.', 'review_date' => '2026-05-01', 'star_rating' => 5]);

        $this->get("/testimonials/chicago-home-review-{$review->id}")->assertStatus(301)->assertRedirect("/reviews/{$review->slug}");
        $this->get('/testimonials/chicago-home-review-99999')->assertStatus(410);
        $this->get('/reviews/gs-construction-review-99999')->assertStatus(410);
        // Not a review address at all: an ordinary 404.
        $this->get('/reviews/not-a-review')->assertStatus(404);
        $this->get('/testimonials/not-a-review')->assertStatus(404);
    }

    public function test_a_photo_addressed_by_its_id_is_sent_to_its_slug(): void
    {
        $project = Project::create(['title' => 'Palatine Kitchen', 'slug' => 'palatine-kitchen-remodel', 'project_type' => 'kitchen', 'location' => 'Palatine, IL', 'is_published' => true, 'completed_at' => now()]);
        Storage::disk('public')->put('projects/palatine.jpg', 'x');
        $image = ProjectImage::create(['project_id' => $project->id, 'filename' => 'palatine.jpg', 'original_filename' => 'a.jpg', 'path' => 'projects/palatine.jpg', 'mime_type' => 'image/jpeg', 'size' => 10, 'width' => 1600, 'height' => 1200, 'alt_text' => 'Bright kitchen remodel', 'sort_order' => 1]);
        if (! $image->fresh()->slug) {
            $image->forceFill(['slug' => 'bright-kitchen-remodel-palatine-il'])->save();
        }
        $slug = $image->fresh()->slug;

        // Search Console listed 14 of these under "alternate page with proper canonical".
        $this->get("/projects/palatine-kitchen-remodel/photos/{$image->id}")
            ->assertStatus(301)
            ->assertRedirect("/projects/palatine-kitchen-remodel/photos/{$slug}");
        $this->get("/projects/palatine-kitchen-remodel/photos/{$slug}")->assertOk();
    }

    public function test_leftover_old_site_paths_are_gone_or_sent_home(): void
    {
        $this->get('/index')->assertStatus(301)->assertRedirect('/');
        foreach (['/html/head/title', '/your-personal-glow-recipe/', '/a-nerdie-guide-to-moles-sun-safety/'] as $path) {
            $this->get($path)->assertStatus(410);
        }
    }

    public function test_the_geo_admin_screens_are_kept_from_crawlers_but_the_public_answers_feed_is_not(): void
    {
        $robots = (string) file_get_contents(public_path('robots.txt'));

        $this->assertSame(3, substr_count($robots, "Disallow: /geo/\n"));
        $this->assertSame(3, substr_count($robots, "Allow: /geo/answers.json\n"));
    }
}
