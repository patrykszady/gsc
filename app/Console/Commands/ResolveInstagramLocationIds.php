<?php

namespace App\Console\Commands;

use App\Models\AreaServed;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class ResolveInstagramLocationIds extends Command
{
    protected $signature = 'instagram:resolve-locations
                            {--state=Illinois : State suffix appended to each city for the IG search}
                            {--limit=0 : Max number of unresolved cities to process this run (0 = all)}
                            {--force : Re-resolve even rows that already have an ig_location_id}
                            {--delay-ms=4500 : Delay between IG queries (ms)}';

    protected $description = 'Resolve and cache Instagram location IDs for service-area cities.';

    public function handle(): int
    {
        $userDataDir = env('INSTAGRAM_PUPPETEER_USER_DATA_DIR', storage_path('app/instagram-puppeteer'));
        if (! is_dir($userDataDir)) {
            $this->error("Instagram session dir not found at {$userDataDir}.");
            $this->line('Log in first: node scripts/instagram-login.mjs --user-data-dir=' . $userDataDir);
            return self::FAILURE;
        }

        $state = trim((string) $this->option('state'));
        $force = (bool) $this->option('force');
        $limit = (int) $this->option('limit');
        $delayMs = (int) $this->option('delay-ms');

        $query = AreaServed::query()->orderBy('city');
        if (! $force) {
            $query->whereNull('ig_location_id');
        }
        if ($limit > 0) {
            $query->limit($limit);
        }

        $areas = $query->get();
        if ($areas->isEmpty()) {
            $this->info('Nothing to resolve.');
            return self::SUCCESS;
        }

        $this->info("Resolving {$areas->count()} cities via Instagram (user-data-dir: {$userDataDir})");

        $queries = $areas->map(fn ($a) => $a->city . ($state ? ", {$state}" : ''))->all();
        $queryToArea = [];
        foreach ($areas as $i => $a) {
            $queryToArea[$queries[$i]] = $a;
        }

        $scriptPath = base_path('scripts/scrape-instagram-location.mjs');
        $process = new Process([
            'node',
            $scriptPath,
            '--user-data-dir=' . $userDataDir,
            '--delay-ms=' . $delayMs,
        ]);
        $process->setTimeout(60 * 30); // 30 min
        $process->setInput(implode("\n", $queries) . "\n");

        $resolved = 0;
        $failed = 0;

        $process->run(function ($type, $buffer) use (&$resolved, &$failed, $queryToArea) {
            if ($type !== Process::OUT) {
                $this->getOutput()->write("<comment>{$buffer}</comment>");
                return;
            }
            foreach (preg_split('/\r?\n/', trim($buffer)) as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $row = json_decode($line, true);
                if (! is_array($row) || empty($row['query'])) continue;

                /** @var AreaServed|null $area */
                $area = $queryToArea[$row['query']] ?? null;
                if (! $area) continue;

                if (! empty($row['id'])) {
                    $area->update(['ig_location_id' => $row['id']]);
                    $resolved++;
                    $this->line(sprintf('  <info>✓</info> %s → %s (%s)', $row['query'], $row['id'], $row['name'] ?? ''));
                } else {
                    $failed++;
                    $this->line(sprintf('  <comment>×</comment> %s → %s', $row['query'], $row['error'] ?? 'unknown'));
                }
            }
        });

        $exit = $process->getExitCode();
        $this->newLine();
        $this->info("Resolved: {$resolved}");
        if ($failed > 0) $this->warn("Unresolved: {$failed}");
        if ($exit !== 0) {
            $this->warn("Scraper exited with code {$exit} — session may need re-login.");
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
