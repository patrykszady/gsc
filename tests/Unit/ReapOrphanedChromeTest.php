<?php

namespace Tests\Unit;

use App\Console\Commands\ReapOrphanedChrome;
use PHPUnit\Framework\TestCase;

/**
 * Which processes chrome:reap-orphans would kill, from `ps` listings shaped
 * like hive-prod's on 2026-10-01 (pid, ppid, age in seconds, RSS in KB,
 * command line): Puppeteer-launched browsers that are orphaned, too big or
 * too old, each with its whole tree; never the Menards browser or the other
 * Chromes the apps start themselves.
 */
class ReapOrphanedChromeTest extends TestCase
{
    private const CHROME = '/home/forge/.cache/puppeteer/chrome/linux-146/chrome-linux64/chrome';

    private const PS = <<<'PS'
        1      0 11000000 9000 /usr/lib/systemd/systemd --system
      262007   1 1935000 300000 CHROME --allow-pre-commit-input --headless=new --remote-debugging-port=0 --user-data-dir=/home/forge/gs.construction/releases/1/storage/app/instagram-puppeteer
      262014 262007 1935000 90000 CHROME --type=zygote --no-sandbox
      262086 262014 1935000 70000 CHROME --type=renderer --enable-automation
      300000   1 60 5000 CHROME --allow-pre-commit-input --remote-debugging-pipe --user-data-dir=/tmp/puppeteer_dev_profile-young
      738752   1 1400000 1400000 /opt/google/chrome/chrome --user-data-dir=/home/forge/hive.contractors/storage/app/menards-browser --no-first-run
      2137193  1 450000 450000 /usr/bin/google-chrome --remote-debugging-port=9313 --window-size=1366,900 --user-data-dir=/home/forge/hive.contractors/storage/files/_temp_
      410000   1 90 90000 node scripts/scrape-instagram-location.mjs --user-data-dir=/x
      410001 410000 90 150000 CHROME --allow-pre-commit-input --remote-debugging-pipe --user-data-dir=/x
    PS;

    /** @return array<int, array{reason: string, pids: array<int, int>, rss_kb: int}> */
    private function doomed(string $ps, int $orphanAge = 120, int $maxProcesses = 60, int $maxRssMb = 2048, int $maxAge = 10800): array
    {
        return ReapOrphanedChrome::doomedTrees(
            ReapOrphanedChrome::parse(str_replace('CHROME', self::CHROME, $ps)),
            $orphanAge,
            $maxProcesses,
            $maxRssMb * 1024,
            $maxAge,
        );
    }

    public function test_it_kills_an_orphaned_puppeteer_browser_with_its_whole_tree(): void
    {
        $doomed = $this->doomed(self::PS);

        $this->assertSame([262007], array_keys($doomed));
        $this->assertSame('orphaned', $doomed[262007]['reason']);
        $this->assertSame([262007, 262014, 262086], $doomed[262007]['pids']);
        $this->assertSame(460000, $doomed[262007]['rss_kb']);
    }

    public function test_a_young_orphan_is_left_until_it_passes_the_orphan_age(): void
    {
        $this->assertSame([262007, 300000], array_keys($this->doomed(self::PS, orphanAge: 30)));
    }

    public function test_it_kills_a_runaway_whose_script_is_still_running(): void
    {
        // The 2026-10-01 22:37 browser: ~126 renderers under a live parent.
        $renderers = implode("\n", array_map(
            fn (int $i) => sprintf('%d 410001 90 66000 CHROME --type=renderer --enable-automation', 500000 + $i),
            range(1, 126),
        ));

        $doomed = $this->doomed(self::PS."\n".$renderers);

        $this->assertSame('over 60 processes', $doomed[410001]['reason']);
        $this->assertCount(127, $doomed[410001]['pids']);
        $this->assertArrayNotHasKey(410000, $doomed);
    }

    public function test_it_kills_a_browser_over_the_memory_limit(): void
    {
        $this->assertSame('over 100 MB', $this->doomed(self::PS, maxRssMb: 100)[410001]['reason']);
    }

    public function test_it_kills_a_browser_running_past_the_age_limit(): void
    {
        $this->assertSame('running over 0h', $this->doomed(self::PS, maxAge: 60)[410001]['reason']);
    }

    public function test_the_menards_browser_and_app_launched_chromes_are_never_touched(): void
    {
        $doomed = $this->doomed(self::PS, orphanAge: 0, maxProcesses: 0, maxRssMb: 0, maxAge: 0);

        $this->assertArrayNotHasKey(738752, $doomed);
        $this->assertArrayNotHasKey(2137193, $doomed);
        $this->assertFalse(ReapOrphanedChrome::isPuppeteerBrowser(self::CHROME.' --allow-pre-commit-input --user-data-dir=/home/forge/hive.contractors/storage/app/menards-browser'));
    }

    public function test_it_logs_the_profile_never_the_command_line(): void
    {
        $this->assertSame('/x', ReapOrphanedChrome::profileOf(self::CHROME.' --allow-pre-commit-input --proxy-server=http://user:secret@host:1 --user-data-dir=/x'));
        $this->assertSame('(temporary)', ReapOrphanedChrome::profileOf(self::CHROME.' --allow-pre-commit-input'));
    }
}
