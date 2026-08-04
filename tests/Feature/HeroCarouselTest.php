<?php

namespace Tests\Feature;

use App\Support\HeroSlides;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * <x-hero-carousel> is rendered on shared views (about, faq, costs, financing,
 * process, testimonials, insurance-claims), so it has to behave for tenants that
 * are nothing like GS Construction.
 *
 * Two properties matter more than how it looks:
 *
 *  1. It must not emit an H1. Every page it was added to already owns exactly one
 *     carefully-written H1, usually followed by a speakable answer paragraph. A
 *     hero that added a second one would be an SEO regression on all of them.
 *  2. It must render nothing at all when there are no slides. ProjectImage is
 *     BelongsToSite, so a tenant with no photography gets an empty collection —
 *     the alternative to rendering nothing is an empty framed box on every page.
 */
class HeroCarouselTest extends TestCase
{
    private function render(string $template, array $data = []): string
    {
        return Blade::render($template, $data);
    }

    public function test_it_emits_no_heading_when_given_no_text(): void
    {
        $html = $this->render(
            '<x-hero-carousel :slides="$slides" />',
            ['slides' => HeroSlides::placeholders(['A', 'B'])],
        );

        $this->assertStringContainsString('ui-carousel', $html);
        $this->assertStringNotContainsString('<h1', $html);
    }

    public function test_it_emits_one_heading_when_given_a_title(): void
    {
        $html = $this->render(
            '<x-hero-carousel :slides="$slides" title="Hello" />',
            ['slides' => HeroSlides::placeholders(['A'])],
        );

        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('Hello', $html);
    }

    public function test_it_renders_nothing_without_slides(): void
    {
        $this->assertSame('', trim($this->render('<x-hero-carousel :slides="[]" title="Ignored" />')));
    }

    public function test_placeholder_slides_are_self_contained_and_distinct(): void
    {
        $slides = HeroSlides::placeholders(['One', 'Two', 'Three']);

        $this->assertCount(3, $slides);
        // Distinct, or the carousel looks broken when it advances.
        $this->assertCount(3, array_unique(array_column($slides, 'url')));

        foreach ($slides as $slide) {
            // No external host: a client site may only publish imagery that
            // client supplied, and these must not become a licensing question.
            $this->assertStringStartsWith('data:image/svg+xml', $slide['url']);
        }
    }

    public function test_only_the_first_slide_is_eager(): void
    {
        $html = $this->render(
            '<x-hero-carousel :slides="$slides" eager />',
            ['slides' => HeroSlides::placeholders(['A', 'B', 'C'])],
        );

        $this->assertSame(1, substr_count($html, 'fetchpriority="high"'));
        $this->assertSame(2, substr_count($html, 'loading="lazy"'));
    }
}
