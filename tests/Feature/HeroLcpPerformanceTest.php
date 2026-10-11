<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use Tests\TestCase;

/**
 * The "area" and "home" templates carry the page's mobile LCP element: the
 * hero slider's first slide. SEO review (2026-10-10): PageSpeed field data
 * showed p75 mobile LCP of 4.8s ("area") and 4.1s ("home") against 1.0-1.2s
 * on desktop. Lighthouse's own LCP-breakdown traced most of that to "Load
 * Delay" (the preload competing with fonts/CSS discovered first in <head>)
 * and "Render Delay" (an opacity fade-in on the LCP image itself delays the
 * paint Chrome attributes to it by the transition's own duration).
 *
 * Four structural properties keep it fast; this test pins all four so a
 * future change can't silently reintroduce any of them:
 *
 *  1. The <head> preload's href/srcset/sizes exactly match the rendered
 *     <img>'s — a mismatch means the browser fetches the resource twice.
 *  2. The image is eager (no loading="lazy") and fetchpriority="high", and
 *     carries a sizes attribute so the browser can pick the right candidate
 *     before it has even laid out the box.
 *  3. It paints at full opacity the instant it decodes — no CSS
 *     opacity-transition fade-in.
 *  4. The preload is the EARLIEST resource hint in <head>, ahead of the
 *     font preloads and the Vite stylesheet, so it isn't discovered (and
 *     made to compete for bandwidth) after them.
 */
class HeroLcpPerformanceTest extends TestCase
{
    private function firstSlidePreloadTag(string $html): string
    {
        preg_match('/<link rel="preload" as="image"[^>]*>/', $html, $m);
        $this->assertNotEmpty($m, 'no hero image preload link found in <head>');

        return $m[0];
    }

    private function firstSlideImgTag(string $html): string
    {
        $marker = strpos($html, 'x-ref="firstSlideImg"');
        $this->assertNotFalse($marker, 'no firstSlideImg element found in the rendered page');
        $start = strrpos(substr($html, 0, $marker), '<img');
        $end = strpos($html, '>', $marker) + 1;

        return substr($html, $start, $end - $start);
    }

    private function attr(string $tag, string $name): ?string
    {
        preg_match('/\b'.preg_quote($name, '/').'="([^"]*)"/', $tag, $m);

        return $m[1] ?? null;
    }

    private function assertHeroIsOptimizedForLcp(string $html): void
    {
        $preload = $this->firstSlidePreloadTag($html);
        $img = $this->firstSlideImgTag($html);

        // 1. The preload must resolve to the exact same request the <img>
        //    will make, or the browser pays for the image twice.
        $this->assertSame($this->attr($img, 'src'), $this->attr($preload, 'href'));
        $this->assertSame($this->attr($img, 'srcset'), $this->attr($preload, 'imagesrcset'));
        $this->assertSame($this->attr($img, 'sizes'), $this->attr($preload, 'imagesizes'));

        // 2. Eager, prioritized, and sized.
        $this->assertSame('high', $this->attr($img, 'fetchpriority'));
        $this->assertStringNotContainsString('loading="lazy"', $img);
        $this->assertNotEmpty($this->attr($img, 'sizes'));

        // 3. No fade-in: the LCP candidate must not carry an opacity
        //    transition, which otherwise delays the paint Chrome credits to
        //    it by the transition's own duration.
        $this->assertStringNotContainsString('transition-opacity', $img);
        $this->assertStringNotContainsString('opacity-0', $img);

        // 4. The preload must be the earliest resource hint in <head> — ahead
        //    of the font preloads and the stylesheet, which would otherwise
        //    be discovered (and compete for bandwidth/priority) first.
        $preloadPos = strpos($html, $preload);
        $fontPos = strpos($html, 'rel="preload" as="font"');
        $cssPos = strpos($html, 'rel="stylesheet"');
        $this->assertNotFalse($fontPos, 'expected a font preload in <head>');
        $this->assertNotFalse($cssPos, 'expected a stylesheet link in <head>');
        $this->assertLessThan($fontPos, $preloadPos, 'the hero preload must come before the font preloads in <head>');
        $this->assertLessThan($cssPos, $preloadPos, 'the hero preload must come before the stylesheet in <head>');
    }

    public function test_the_home_pages_hero_is_optimized_for_lcp(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertHeroIsOptimizedForLcp($html);
    }

    public function test_an_area_pages_hero_is_optimized_for_lcp(): void
    {
        AreaServed::create([
            'city' => 'Kenilworth', 'slug' => 'kenilworth', 'state' => 'IL',
            'local_intro' => str_repeat('Unique local copy about the town. ', 60),
        ]);

        $html = $this->get('/areas-served/kenilworth')->assertOk()->getContent();

        $this->assertHeroIsOptimizedForLcp($html);
    }
}
