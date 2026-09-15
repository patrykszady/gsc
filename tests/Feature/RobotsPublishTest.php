<?php

namespace Tests\Feature;

use App\Support\Seo\CrawlFiles;
use Tests\TestCase;

/** The interim static copy for nginx: the default site's robots.txt, and only that site's. */
class RobotsPublishTest extends TestCase
{
    public function test_it_writes_the_default_sites_robots_to_public(): void
    {
        @unlink(public_path('robots.txt'));

        $this->artisan('robots:publish')->assertExitCode(0)->run();

        $written = (string) file_get_contents(public_path('robots.txt'));
        $this->assertStringContainsString('STATIC COPY for the default site', $written);
        $this->assertStringContainsString(CrawlFiles::robots(), $written, 'the same text the route renders for the default site');
        $this->assertStringContainsString('Sitemap: https://gs.construction/sitemap.xml', $written);

        unlink(public_path('robots.txt'));
    }
}
