<?php

namespace Tests\Feature;

use App\Models\Testimonial;
use Tests\TestCase;

/**
 * A review's route binding reads only the trailing id, so any slug ending
 * in that id resolves. Search Console held ten such variants
 * (/reviews/gs-construction-review-10), each canonicalised to itself. One
 * URL per review: every other spelling is sent to the real one.
 */
class ReviewSlugRedirectTest extends TestCase
{
    public function test_a_review_reached_by_any_other_slug_is_redirected_to_its_real_one(): void
    {
        $review = Testimonial::create([
            'reviewer_name' => 'Kathy M.',
            'project_location' => 'Hoffman Estates, IL',
            'project_type' => 'kitchen',
            'review_description' => 'Wonderful kitchen.',
            'review_date' => '2026-05-01',
            'star_rating' => 5,
        ]);

        $this->assertSame("hoffman-estates-il-kitchen-review-{$review->id}", $review->slug);

        $this->get("/reviews/gs-construction-review-{$review->id}")
            ->assertStatus(301)
            ->assertRedirect("/reviews/{$review->slug}");

        $this->get("/reviews/{$review->slug}")->assertOk();
    }

    public function test_pagination_state_from_an_older_build_is_kept_out_of_crawlers(): void
    {
        $robots = (string) file_get_contents(public_path('robots.txt'));

        // Googlebot's group and the default group both carry the rule.
        $this->assertSame(3, substr_count($robots, 'Disallow: /*_page='));
    }
}
