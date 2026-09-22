<?php

namespace Tests\Unit\Reviews;

use SsSystems\Platform\Reviews\AngiScraper;
use PHPUnit\Framework\TestCase;

/**
 * The shared scraper runner, read through the command line it would run —
 * no browser. Pins the two things the 2026-09-21 fix hangs on: the
 * package's own script is what runs, and proxy sessions exit from the US.
 */
class AngiScraperTest extends TestCase
{
    private function scraper(array $overrides = []): AngiScraper
    {
        return new AngiScraper(...($overrides + [
            'brandName' => 'J. Peterson Design',
            'chromeProfileDir' => '/tmp/profile',
            'nodeModules' => '/srv/site/node_modules',
            'proxy' => 'http://user:secret@na.proxy.example.com:2334',
        ]));
    }

    public function test_the_scraper_that_runs_is_the_one_shipped_with_the_package(): void
    {
        $script = AngiScraper::script();

        $this->assertStringEndsWith('/resources/scripts/scrape-angi-reviews.mjs', $script);
        $this->assertFileExists($script);
        $this->assertFileExists(dirname($script).'/lib/angi-page.mjs');
        $this->assertStringContainsString(escapeshellarg($script), $this->scraper()->command('https://www.angi.com/x.htm', '/tmp/out.json'));
    }

    public function test_proxy_sessions_exit_from_the_us_unless_told_otherwise(): void
    {
        $command = $this->scraper()->command('https://www.angi.com/x.htm', '/tmp/out.json');

        $this->assertStringContainsString("--proxy-region='us'", $command);
        $this->assertStringContainsString("--proxy='http://user:secret@na.proxy.example.com:2334'", $command);
        $this->assertStringNotContainsString('--skip-direct', $command, 'this server is tried first');

        $this->assertStringContainsString("--proxy-region='ca'", $this->scraper(['proxyRegion' => 'ca'])->command('https://www.angi.com/x.htm', '/tmp/out.json'));
        $this->assertStringContainsString(' --skip-direct', $this->scraper(['skipDirect' => true])->command('https://www.angi.com/x.htm', '/tmp/out.json'));
    }

    public function test_it_runs_headed_on_a_virtual_display_unless_headless_is_asked_for(): void
    {
        $headed = $this->scraper()->command('https://www.angi.com/x.htm', '/tmp/out.json');
        $this->assertStringStartsWith("'xvfb-run' -a --server-args='-screen 0 1440x2400x24' 'node' ", $headed);
        $this->assertStringNotContainsString('--headless', $headed);

        $headless = $this->scraper(['headless' => true])->command('https://www.angi.com/x.htm', '/tmp/out.json');
        $this->assertStringStartsWith("'node' ", $headless);
        $this->assertStringContainsString(' --headless', $headless);
    }

    public function test_each_failure_has_words_the_site_owner_can_act_on(): void
    {
        $this->assertSame(
            'Angi’s bot protection blocked the read, from this server and from the backup connection. It often works again on the next run.',
            AngiScraper::explain('blocked', null, 'J. Peterson Design'),
        );
        $this->assertSame('That Angi page belongs to Atlas GS Construction Partners, not GS Construction.', AngiScraper::explain('wrong_business', 'Atlas GS Construction Partners', 'GS Construction'));
        $this->assertSame('That Angi page belongs to another business, not GS Construction.', AngiScraper::explain('wrong_business', '', 'GS Construction'));
        $this->assertSame('Angi’s profile page carried no review data.', AngiScraper::explain('no_structured_data', null, 'GS Construction'));
        $this->assertSame('Angi’s profile page carried no review data.', AngiScraper::explain('scraper_failed', null, 'GS Construction'));
    }
}
