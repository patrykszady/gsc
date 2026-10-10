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

    /**
     * Superseded 2026-10-10: disallowing `/*_page=` stopped Google from
     * re-crawling a URL it already had indexed, so it could never see the
     * rel=canonical pointing at the bare page — Search Console reported
     * "Indexed, though blocked by robots.txt" instead. App\Http\Middleware\
     * RedirectLegacyPaginationParams now 301s the param away instead (see
     * RedirectLegacyPaginationParamsTest), which Google CAN follow.
     */
    public function test_pagination_state_from_an_older_build_is_no_longer_blocked_by_robots(): void
    {
        $robots = \App\Support\Seo\CrawlFiles::robots();

        $this->assertStringNotContainsString('_page=', $robots);
    }
}
