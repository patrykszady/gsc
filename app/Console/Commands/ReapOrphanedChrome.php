<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Kills headless Chrome left behind by a Puppeteer script that died without
 * closing it (2026-10-01: an Instagram browser had run 22 days on hive-prod
 * with ~85 processes, and another 72 days, holding memory and all of the
 * swap). scripts/lib/browser-guard.mjs stops the scripts leaking; this is
 * the net under it, for every site on the server.
 *
 * Only a browser that is all of these is touched: headless, launched by
 * Puppeteer (--remote-debugging-port=0), orphaned (its parent is PID 1 —
 * a running script is always its parent) and older than --min-age. The
 * headed browsers on Xvfb (Menards, the citations session, Instagram's
 * remote login) never match. Its whole process tree goes with it.
 */
class ReapOrphanedChrome extends Command
{
    protected $signature = 'chrome:reap-orphans {--min-age=3600 : Seconds an orphaned browser must have been running} {--dry : List what would be killed}';

    protected $description = 'Kill headless Puppeteer Chrome orphaned by a script that died';

    public function handle(): int
    {
        $ps = new Process(['ps', '-eo', 'pid=,ppid=,etimes=,args=']);
        $ps->run();

        if (! $ps->isSuccessful()) {
            $this->warn('ps failed: '.trim($ps->getErrorOutput()));

            return self::FAILURE;
        }

        $processes = self::parse($ps->getOutput());
        $doomed = self::orphanedTrees($processes, (int) $this->option('min-age'));

        if ($doomed === []) {
            $this->info('No orphaned headless Chrome.');

            return self::SUCCESS;
        }

        foreach ($doomed as $root => $pids) {
            $this->line(sprintf('%s browser %d (%d processes, running %s)', $this->option('dry') ? 'Would kill' : 'Killing', $root, count($pids), gmdate('z\d H\h', $processes[$root]['age'])));

            if (! $this->option('dry')) {
                foreach ($pids as $pid) {
                    @posix_kill($pid, SIGKILL);
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{pid: int, ppid: int, age: int, args: string}>
     */
    public static function parse(string $psOutput): array
    {
        $processes = [];

        foreach (preg_split('/\R/', trim($psOutput)) as $line) {
            if (preg_match('/^\s*(\d+)\s+(\d+)\s+(\d+)\s+(.*)$/', $line, $m)) {
                $processes[(int) $m[1]] = ['pid' => (int) $m[1], 'ppid' => (int) $m[2], 'age' => (int) $m[3], 'args' => $m[4]];
            }
        }

        return $processes;
    }

    /**
     * Each orphaned headless Puppeteer browser's pid => its pid and every
     * descendant's.
     *
     * @param  array<int, array{pid: int, ppid: int, age: int, args: string}>  $processes
     * @return array<int, array<int, int>>
     */
    public static function orphanedTrees(array $processes, int $minAge): array
    {
        $children = [];
        foreach ($processes as $process) {
            $children[$process['ppid']][] = $process['pid'];
        }

        $trees = [];
        foreach ($processes as $process) {
            $args = $process['args'];

            $isOrphanedPuppeteerBrowser = $process['ppid'] === 1
                && $process['age'] >= $minAge
                && preg_match('#(^|/)(chrome|chromium|google-chrome)(\s|$)#', $args)
                && str_contains($args, '--headless')
                && str_contains($args, '--remote-debugging-port=0')
                && ! str_contains($args, '--type=');

            if (! $isOrphanedPuppeteerBrowser) {
                continue;
            }

            $tree = [];
            $queue = [$process['pid']];
            while ($queue !== []) {
                $pid = array_shift($queue);
                $tree[] = $pid;
                array_push($queue, ...($children[$pid] ?? []));
            }

            $trees[$process['pid']] = $tree;
        }

        return $trees;
    }
}
