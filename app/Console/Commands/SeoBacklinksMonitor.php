<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Lightweight backlink / mention monitor.
 *
 * Real backlink indexes (Ahrefs/Majestic) cost money and Google's `link:`
 * operator is gone. The next-best free-ish proxy:
 *
 *   1. SerpApi search for `"<bare-host>" -site:<host>` — every external page
 *      that mentions our domain. Filter out social-only mentions if desired.
 *   2. Compare today's set of referring hosts against the previously-saved
 *      snapshot in storage/app/seo/backlink-hosts.json. Surface NEW and LOST
 *      hosts week-over-week.
 *
 * Costs 1 SerpApi search per run (≈4/month at weekly cadence).
 */
class SeoBacklinksMonitor extends Command
{
    protected $signature = 'seo:backlinks-monitor
        {--host= : Override host (defaults to APP_URL host)}
        {--pages=3 : SerpApi result pages to walk (10 results each)}
        {--markdown : Save report to storage/app/reports/backlinks-monitor.md}';

    protected $description = 'Track external hosts mentioning gs.construction; surface new and lost referring domains week-over-week.';

    public function handle(): int
    {
        $apiKey = (string) config('services.serpapi.api_key', '');
        if ($apiKey === '') {
            $this->error('SERPAPI_API_KEY not set.');
            return self::FAILURE;
        }

        $host = $this->normalizeHost((string) ($this->option('host') ?: parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'gs.construction'));
        $pages = max(1, (int) $this->option('pages'));
        $this->info("Searching mentions of {$host} across {$pages} SerpApi page(s)…");

        $foundHosts = [];
        $samples = []; // host => sample URL
        for ($p = 0; $p < $pages; $p++) {
            $start = $p * 10;
            $results = $this->fetchSerp($apiKey, "\"{$host}\" -site:{$host}", $start);
            if ($results === null) {
                $this->warn("  page {$p} failed");
                break;
            }
            foreach ($results as $row) {
                $link = (string) ($row['link'] ?? '');
                if ($link === '') continue;
                $h = $this->normalizeHost((string) parse_url($link, PHP_URL_HOST));
                if ($h === '' || $h === $host) continue;
                $foundHosts[$h] = true;
                $samples[$h] = $samples[$h] ?? $link;
            }
            usleep(250000);
        }
        $current = array_keys($foundHosts);
        sort($current);
        $this->line('  ' . count($current) . ' referring host(s) found this run.');

        // Compare with previous snapshot.
        $disk = Storage::disk('local');
        $snapPath = 'seo/backlink-hosts.json';
        $previous = [];
        if ($disk->exists($snapPath)) {
            $previous = (array) (json_decode((string) $disk->get($snapPath), true)['hosts'] ?? []);
        }
        $new = array_values(array_diff($current, $previous));
        $lost = array_values(array_diff($previous, $current));

        $this->newLine();
        $this->line('<fg=cyan>--- New referring hosts (' . count($new) . ') ---</>');
        foreach (array_slice($new, 0, 15) as $h) {
            $this->line('  + ' . $h . '   ' . ($samples[$h] ?? ''));
        }
        $this->newLine();
        $this->line('<fg=cyan>--- Lost referring hosts (' . count($lost) . ') ---</>');
        foreach (array_slice($lost, 0, 15) as $h) $this->line('  - ' . $h);

        // Persist new snapshot.
        $disk->put($snapPath, json_encode([
            'updated_at' => now()->toIso8601String(),
            'hosts' => $current,
            'samples' => $samples,
        ], JSON_PRETTY_PRINT));

        if ($this->option('markdown')) {
            $this->saveMarkdown($current, $new, $lost, $samples);
        }
        if (! empty($lost)) {
            logger()->warning('seo:backlinks-monitor lost hosts', ['lost' => $lost]);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    protected function fetchSerp(string $apiKey, string $query, int $start): ?array
    {
        try {
            $resp = Http::timeout(40)->get('https://serpapi.com/search.json', [
                'engine' => 'google',
                'q' => $query,
                'start' => $start,
                'num' => 10,
                'hl' => 'en',
                'gl' => 'us',
                'api_key' => $apiKey,
            ]);
        } catch (\Throwable) {
            return null;
        }
        if (! $resp->successful()) return null;
        $j = $resp->json();
        if (! empty($j['error'])) return null;
        return (array) ($j['organic_results'] ?? []);
    }

    protected function normalizeHost(string $h): string
    {
        $h = strtolower(trim($h));
        return preg_replace('/^www\./', '', $h) ?? $h;
    }

    /**
     * @param array<int, string> $current
     * @param array<int, string> $new
     * @param array<int, string> $lost
     * @param array<string, string> $samples
     */
    protected function saveMarkdown(array $current, array $new, array $lost, array $samples): void
    {
        $md = "# Backlink / mention monitor\n\n";
        $md .= 'Run: ' . now()->toIso8601String() . "\n\n";
        $md .= '**Referring hosts this run:** ' . count($current) . "\n\n";

        $md .= '## New (' . count($new) . ")\n\n";
        if (empty($new)) {
            $md .= "_None._\n";
        } else {
            $md .= "| Host | Sample URL |\n|---|---|\n";
            foreach ($new as $h) $md .= "| {$h} | " . ($samples[$h] ?? '') . " |\n";
        }
        $md .= "\n## Lost (" . count($lost) . ")\n\n";
        $md .= empty($lost) ? "_None._\n" : ('- ' . implode("\n- ", $lost) . "\n");

        $md .= "\n## All current referring hosts\n\n";
        $md .= empty($current) ? "_None._\n" : ('- ' . implode("\n- ", $current) . "\n");

        Storage::disk('local')->put('reports/backlinks-monitor.md', $md);
        $this->info('Saved: storage/app/reports/backlinks-monitor.md');
    }
}
