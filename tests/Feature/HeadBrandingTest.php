<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * What search engines read from the head for the site name and the favicon.
 *
 * Google shows the bare domain as the site name when its signals disagree,
 * and shows one favicon per host, chosen among the candidates it is given —
 * so every name signal must match and the icon candidates must be few and
 * on stable URLs.
 */
class HeadBrandingTest extends TestCase
{
    public function test_every_site_name_signal_says_the_same_thing(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $name = e(config('brand.display_name'));

        $this->assertSame('GS Construction &amp; Remodeling', $name);
        $this->assertStringContainsString('<meta property="og:site_name" content="'.$name.'">', $html);
        $this->assertStringContainsString('<meta name="application-name" content="'.$name.'">', $html);
        $this->assertStringContainsString('<meta name="apple-mobile-web-app-title" content="'.$name.'">', $html);
        // The WebSite schema is the signal Google weighs most.
        $this->assertMatchesRegularExpression('/"@type":\s*"WebSite".{0,400}"name":\s*"GS Construction & Remodeling"/s', $html);
    }

    public function test_the_favicon_candidates_are_few_and_on_stable_urls(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        preg_match_all('/<link[^>]+rel="(?:shortcut )?icon"[^>]*>/i', $html, $m);
        $icons = $m[0];

        $this->assertCount(3, $icons, 'one PNG for Google, one SVG for browsers, one .ico for Bing: '.implode("\n", $icons));
        $this->assertStringContainsString('sizes="96x96"', implode("\n", $icons), 'Google wants a square PNG that is a multiple of 48px');
        foreach ($icons as $tag) {
            $this->assertStringNotContainsString('?v=', $tag, 'a favicon URL must not change: '.$tag);
        }
        $this->assertSame(1, substr_count($html, 'rel="shortcut icon"'), 'exactly one .ico candidate, not the SEO package\'s as well');
    }
}
