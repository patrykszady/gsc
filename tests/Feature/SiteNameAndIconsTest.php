<?php

namespace Tests\Feature;

use App\Support\SiteIcons;
use Tests\TestCase;

/**
 * Google showed "gs.construction" above this site's results and a badge too
 * wide to read at 18 px (2026-09-24). The homepage heading now opens with the
 * same full name the WebSite node, the title and og:site_name use, and the
 * icon set is square.
 */
class SiteNameAndIconsTest extends TestCase
{
    public function test_the_homepage_heading_uses_the_full_site_name(): void
    {
        $this->get('/')->assertOk()->assertSee('<h1', false)->assertSee(e(config('brand.display_name')).' —', false);
    }

    public function test_every_icon_in_the_set_is_square(): void
    {
        foreach (['favicon-96x96.png', 'apple-touch-icon.png', 'android-chrome-192x192.png', 'android-chrome-512x512.png'] as $file) {
            [$w, $h] = getimagesize(SiteIcons::path($file, 'gsc'));
            $this->assertSame($w, $h, $file);
        }
    }
}
