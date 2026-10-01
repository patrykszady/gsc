<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Kills Puppeteer-launched Chrome that has outlived its script or run away,
 * for every site on the server.
 *
 * 2026-10-01, twice: in the morning an orphaned Instagram browser had run 22
 * days on hive-prod with ~85 processes; at 22:37 UTC instagram-add-location
 * .mjs was SIGKILLed at its Process timeout and its Chrome, orphaned, grew
 * to ~126 processes and ~8 GB until the droplet stopped answering and had to
 * be power-cycled. Every script now launches in pipe mode, so Chrome dies
 * with its script (scripts/lib/browser-guard.mjs); this is the net under
 * that, run every minute.
 *
 * Only a browser Puppeteer launched is ever touched: its main process
 * carries Puppeteer's own --allow-pre-commit-input. The Menards browser and
 * the other Chromes the apps start themselves never do (and anything whose
 * profile is the Menards one is skipped regardless). Such a browser goes,
 * with its whole process tree, when it is:
 *
 *  - orphaned (its parent is PID 1: the script that launched it is gone)
 *    and older than --orphan-age;
 *  - bigger than --max-processes processes or --max-rss-mb in memory;
 *  - older than --max-age.
 */
class ReapOrphanedChrome extends Command
{
    protected $signature = 'chrome:reap-orphans
        {--orphan-age=120 : Seconds an orphaned browser may live}
        {--max-processes=60 : Processes in one browser\'s tree before it is killed}
        {--max-rss-mb=2048 : Memory of one browser\'s tree before it is killed}
        {--max-age=10800 : Seconds any Puppeteer browser may run}
        {--dry : List what would be killed}';

    protected $description = 'Kill Puppeteer Chrome that outlived its script or ran away';

    public function handle(): int
    {
        $ps = new Process(['ps', '-eo', 'pid=,ppid=,etimes=,rss=,args=']);
        $ps->run();

        if (! $ps->isSuccessful()) {
            $this->warn('ps failed: '.trim($ps->getErrorOutput()));

            return self::FAILURE;
        }

        $processes = self::parse($ps->getOutput());
        $doomed = self::doomedTrees(
            $processes,
            (int) $this->option('orphan-age'),
            (int) $this->option('max-processes'),
            (int) $this->option('max-rss-mb') * 1024,
            (int) $this->option('max-age'),
        );

        if ($doomed === []) {
            $this->info('No runaway or orphaned Puppeteer Chrome.');

            return self::SUCCESS;
        }

        foreach ($doomed as $root => $tree) {
            $line = sprintf(
                '%s browser %d (%s; %d processes, %d MB, running %s), profile %s',
                $this->option('dry') ? 'Would kill' : 'Killing',
                $root,
                $tree['reason'],
                count($tree['pids']),
                intdiv($tree['rss_kb'], 1024),
                gmdate('z\d H\h i\m', $processes[$root]['age']),
                self::profileOf($processes[$root]['args']),
            );
            $this->line($line);

            if (! $this->option('dry')) {
                Log::warning('chrome:reap-orphans: '.$line);

                foreach ($tree['pids'] as $pid) {
                    @posix_kill($pid, SIGKILL);
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{pid: int, ppid: int, age: int, rss: int, args: string}>
     */
    public static function parse(string $psOutput): array
    {
        $processes = [];

        foreach (preg_split('/\R/', trim($psOutput)) as $line) {
            if (preg_match('/^\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(.*)$/', $line, $m)) {
                $processes[(int) $m[1]] = ['pid' => (int) $m[1], 'ppid' => (int) $m[2], 'age' => (int) $m[3], 'rss' => (int) $m[4], 'args' => $m[5]];
            }
        }

        return $processes;
    }

    /**
     * Each doomed Puppeteer browser's pid => why, its pid and every
     * descendant's, and their combined memory.
     *
     * @param  array<int, array{pid: int, ppid: int, age: int, rss: int, args: string}>  $processes
     * @return array<int, array{reason: string, pids: array<int, int>, rss_kb: int}>
     */
    public static function doomedTrees(array $processes, int $orphanAge, int $maxProcesses, int $maxRssKb, int $maxAge): array
    {
        $children = [];
        foreach ($processes as $process) {
            $children[$process['ppid']][] = $process['pid'];
        }

        $doomed = [];
        foreach ($processes as $process) {
            if (! self::isPuppeteerBrowser($process['args'])) {
                continue;
            }

            $tree = [];
            $queue = [$process['pid']];
            while ($queue !== []) {
                $pid = array_shift($queue);
                $tree[] = $pid;
                array_push($queue, ...($children[$pid] ?? []));
            }
            $rssKb = array_sum(array_map(fn (int $pid) => $processes[$pid]['rss'], $tree));

            $reason = match (true) {
                $process['ppid'] === 1 && $process['age'] >= $orphanAge => 'orphaned',
                count($tree) > $maxProcesses => 'over '.$maxProcesses.' processes',
                $rssKb > $maxRssKb => 'over '.intdiv($maxRssKb, 1024).' MB',
                $process['age'] > $maxAge => 'running over '.intdiv($maxAge, 3600).'h',
                default => null,
            };

            if ($reason !== null) {
                $doomed[$process['pid']] = ['reason' => $reason, 'pids' => $tree, 'rss_kb' => $rssKb];
            }
        }

        return $doomed;
    }

    /**
     * Which profile a browser ran on, to tell the scripts apart in the log;
     * never the whole command line, which can carry credentials.
     */
    public static function profileOf(string $args): string
    {
        return preg_match('#--user-data-dir=(\S+)#', $args, $m) ? $m[1] : '(temporary)';
    }

    /** A Chrome main process that Puppeteer launched, and not the Menards one. */
    public static function isPuppeteerBrowser(string $args): bool
    {
        return preg_match('#(^|/)(chrome|chromium|google-chrome)(\s|$)#', $args)
            && str_contains($args, '--allow-pre-commit-input')
            && ! str_contains($args, '--type=')
            && ! str_contains($args, 'menards-browser');
    }
}
