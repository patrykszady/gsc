<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Dev and preview copies turn every crawler away in robots.txt, on top of
 * the noindex header (owner's call, 2026-09-29: "all our sites on ss.systems
 * block automated access"). Live tenants keep their own robots.txt.
 */
class DevHostRobotsTest extends TestCase
{
    public function test_a_preview_host_disallows_everything(): void
    {
        $this->get('http://dev-jpeterson.ss.systems/robots.txt')
            ->assertOk()
            ->assertSee("User-agent: *\nDisallow: /\n", false)
            ->assertDontSee('Sitemap:');
    }

    public function test_a_dev_mirror_disallows_everything(): void
    {
        foreach (['http://dev.ss.systems/robots.txt', 'http://dev-gsc.ss.systems/robots.txt', 'http://dev.gs.construction/robots.txt'] as $url) {
            $this->get($url)->assertOk()->assertSee("User-agent: *\nDisallow: /\n", false)->assertDontSee('Sitemap:');
        }
    }

    public function test_a_live_tenant_keeps_its_own_robots_even_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->get('https://gs.construction/robots.txt')
            ->assertOk()
            ->assertSee('Sitemap: https://gs.construction/sitemap.xml', false)
            ->assertDontSee('no crawling');
    }
}
