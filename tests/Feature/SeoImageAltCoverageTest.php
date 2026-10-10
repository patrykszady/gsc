<?php

namespace Tests\Feature;

use App\Models\Testimonial;
use App\Livewire\TestimonialsSection;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The health-check's image-alt-coverage score (vendor ss-systems/platform-kit
 * HealthCheckReport::scoreUrl()) scans every <img> in the chrome-stripped
 * page body and counts one against coverage unless it is either
 * aria-hidden="true" or carries a non-empty alt="..." attribute. Confirmed
 * against the real gsc tenant (2026-10-10) that /services/home-remodeling and
 * every /compare/{slug} page score exactly 11 images / 3 missing, and the 3
 * were always the same three: <x-five-stars>'s two rating-icon <img>s (alt=""
 * with the accessible name delegated to the wrapping role="img" span, which
 * this regex-based scan cannot see) and the testimonials carousel's own
 * photo (alt="" outright, no aria-hidden).
 */
class SeoImageAltCoverageTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** Mirrors HealthCheckReport::scoreUrl()'s per-tag decorative/alt check. */
    private function imgTagsMissingAlt(string $html): array
    {
        $tags = preg_match_all('#<img\b[^>]*>#i', $html, $m) ? $m[0] : [];

        return array_values(array_filter($tags, function (string $tag): bool {
            if (preg_match('#\baria-hidden=["\']true["\']#i', $tag)) {
                return false;
            }

            return ! (preg_match('#\balt=["\']([^"\']*)["\']#i', $tag, $am) && trim($am[1]) !== '');
        }));
    }

    public function test_five_stars_rating_icons_are_marked_decorative_on_the_img_itself(): void
    {
        $html = (string) $this->blade('<x-five-stars size="h-9" />');

        $this->assertSame(2, substr_count($html, '<img'), 'expected the light/dark icon pair');
        $this->assertSame([], $this->imgTagsMissingAlt($html), 'five-stars icons must not score as missing alt');

        // Still accessible: the rating is announced once, from the wrapper.
        $this->assertStringContainsString('role="img"', $html);
        $this->assertStringContainsString('aria-label="Rated 5 out of 5"', $html);
    }

    public function test_five_stars_decorative_mode_still_hides_the_imgs_too(): void
    {
        $html = (string) $this->blade('<x-five-stars size="h-6" label="" />');

        $this->assertSame([], $this->imgTagsMissingAlt($html));
        $this->assertStringContainsString('aria-hidden="true"', $html);
    }

    public function test_testimonial_photo_gets_real_alt_text_from_its_own_data(): void
    {
        Testimonial::create([
            'reviewer_name' => 'Pat K.',
            'project_location' => 'Palatine, IL',
            'project_type' => 'kitchen',
            'review_description' => 'Fantastic kitchen remodel.',
            'review_date' => now()->subMonth(),
            'star_rating' => 5,
        ]);

        $html = Livewire::test(TestimonialsSection::class)->html();

        $this->assertStringContainsString('alt="Kitchen project in Palatine, IL"', $html);
        $this->assertSame([], $this->imgTagsMissingAlt($html), 'no content image should score as missing alt');
    }

    public function test_testimonial_photo_falls_back_to_a_real_non_empty_alt_with_no_reviews_at_all(): void
    {
        $html = Livewire::test(TestimonialsSection::class)->html();

        $this->assertStringContainsString('alt="Remodeling project by GS Construction"', $html);
        $this->assertSame([], $this->imgTagsMissingAlt($html));
    }

    public function test_services_home_remodeling_page_has_no_content_images_missing_alt(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $html = (string) $this->get('/services/home-remodeling')->assertOk()->getContent();
        $contentHtml = preg_replace('#<(header|nav|footer)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;

        $this->assertSame([], $this->imgTagsMissingAlt($contentHtml));
    }

    public function test_a_compare_page_has_no_content_images_missing_alt(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $slug = (array) config('competitors.competitors', []);
        $slug = $slug[0]['slug'] ?? null;
        $this->assertNotNull($slug, 'no competitor configured to test against');

        $html = (string) $this->get("/compare/{$slug}")->assertOk()->getContent();
        $contentHtml = preg_replace('#<(header|nav|footer)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;

        $this->assertSame([], $this->imgTagsMissingAlt($contentHtml));
    }
}
