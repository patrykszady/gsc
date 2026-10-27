<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use Tests\TestCase;

/**
 * livewire/main-project-hero-slider's visible per-slide heading is
 * `x-html`-bound to `slide.title` and only ever filled in by Alpine, so a
 * crawler that never runs JavaScript (most AI crawlers) saw an empty <h2> —
 * the homepage's and every area home page's hero heading was invisible in
 * the raw HTML. The first slide's own title must now sit inside that
 * element server-side too, while Alpine keeps overwriting it the same way
 * once it hydrates.
 */
class HomeHeroTitleServerRenderedTest extends TestCase
{
    /** The raw text between the x-html hero heading's opening and closing tag. */
    private function heroH2Content(string $html): string
    {
        $marker = "x-html=\"slide.title.replace('\\n', '<br>')\"";
        $pos = strpos($html, $marker);
        $this->assertNotFalse($pos, 'hero heading markup not found in rendered HTML');

        $after = substr($html, $pos + strlen($marker));
        $close = strpos($after, '</h2>');
        $this->assertNotFalse($close, 'no closing </h2> found after the hero heading markup');

        return substr($after, 0, $close);
    }

    public function test_the_homepages_first_slide_title_is_in_the_raw_html(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        // home.blade.php's first slide is always titled "Kitchens" (the
        // button text is randomized, the title is not).
        $this->assertStringContainsString('Kitchens', $this->heroH2Content($html));
    }

    public function test_an_area_home_pages_first_slide_title_is_in_the_raw_html(): void
    {
        AreaServed::create(['city' => 'Schaumburg', 'slug' => 'schaumburg']);

        $html = $this->get('/areas-served/schaumburg')->assertOk()->getContent();

        // area-page.blade.php's "home" branch leads with the same "Kitchens" slide.
        $this->assertStringContainsString('Kitchens', $this->heroH2Content($html));
    }
}
