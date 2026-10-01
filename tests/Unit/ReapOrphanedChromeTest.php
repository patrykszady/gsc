<?php

namespace Tests\Unit;

use App\Console\Commands\ReapOrphanedChrome;
use PHPUnit\Framework\TestCase;

/**
 * Which processes chrome:reap-orphans would kill, from a `ps` listing shaped
 * like hive-prod's on 2026-10-01: only an orphaned (parent PID 1), headless,
 * Puppeteer-launched browser past the minimum age, with its whole tree.
 */
class ReapOrphanedChromeTest extends TestCase
{
    private const PS = <<<'PS'
        1      0 11000000 /usr/lib/systemd/systemd --system
      262007   1 1935000 /home/forge/.cache/puppeteer/chrome/linux-146/chrome-linux64/chrome --allow-pre-commit-input --headless=new --remote-debugging-port=0 --user-data-dir=/home/forge/gs.construction/releases/20260906022450/storage/app/instagram-puppeteer
      262014 262007 1935000 /home/forge/.cache/puppeteer/chrome/linux-146/chrome-linux64/chrome --type=zygote --no-sandbox
      262086 262014 1935000 /home/forge/.cache/puppeteer/chrome/linux-146/chrome-linux64/chrome --type=renderer --enable-automation
      300000   1 600 /home/forge/.cache/puppeteer/chrome/linux-146/chrome-linux64/chrome --headless=new --remote-debugging-port=0 --user-data-dir=/tmp/puppeteer_dev_profile-young
      738752   1 1400000 /opt/google/chrome/chrome --user-data-dir=/home/forge/hive.contractors/storage/app/menards-browser --no-first-run
      2137193  1 450000 /usr/bin/google-chrome --remote-debugging-port=9313 --window-size=1366,900 --user-data-dir=/home/forge/hive.contractors/storage/files/_temp_
      410000   1 90000 node scripts/scrape-instagram-location.mjs --user-data-dir=/x
      410001 410000 90000 /home/forge/.cache/puppeteer/chrome/linux-146/chrome-linux64/chrome --headless=new --remote-debugging-port=0 --user-data-dir=/x
    PS;

    public function test_it_picks_only_the_orphaned_headless_puppeteer_browser_with_its_whole_tree(): void
    {
        $trees = ReapOrphanedChrome::orphanedTrees(ReapOrphanedChrome::parse(self::PS), 3600);

        $this->assertSame([262007 => [262007, 262014, 262086]], $trees);
    }

    public function test_a_young_orphan_is_left_until_it_passes_the_minimum_age(): void
    {
        $trees = ReapOrphanedChrome::orphanedTrees(ReapOrphanedChrome::parse(self::PS), 300);

        $this->assertSame([262007, 300000], array_keys($trees));
    }
}
