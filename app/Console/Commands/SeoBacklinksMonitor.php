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
 *   1. Brave search for `"<bare-host>" -site:<host>` — every external page
 *      that mentions our domain. Filter out social-only mentions if desired.
 *   2. Compare today's set of referring hosts against the previously-saved
 *      snapshot in storage/app/seo/backlink-hosts.json. Surface NEW and LOST
 *      hosts week-over-week.
 *
 * Costs 1 search per run (≈4/month at weekly cadence).
 */
class SeoBacklinksMonitor extends Command
{
    protected $signature = 'seo:backlinks-monitor
        {--host= : Override host (defaults to APP_URL host)}
        {--pages=3 : Search result pages to walk (10 results each)}
        {--confirm-runs=2 : Consecutive missing runs required before host is marked lost}
        {--markdown : Save report to storage/app/reports/backlinks-monitor.md}';

    protected $description = 'Track external hosts mentioning gs.construction; surface new and lost referring domains week-over-week.';

    public function handle(): int
    {
        if (! app(\App\Services\BraveSearchService::class)->isConfigured()) {
            $this->error('BRAVE_SEARCH_API_KEY not set.');
            return self::FAILURE;
        }

        $host = $this->normalizeHost((string) ($this->option('host') ?: parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'gs.construction'));
        $pages = max(1, (int) $this->option('pages'));
        $this->info("Searching mentions of {$host} across {$pages} Brave page(s)…");

        $foundHosts = [];
        $samples = []; // host => sample URL
        for ($p = 0; $p < $pages; $p++) {
            $start = $p * 10;
            $results = $this->fetchSerp("\"{$host}\" -site:{$host}", $start);
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
        $state = [];
        if ($disk->exists($snapPath)) {
            $snapshot = (array) json_decode((string) $disk->get($snapPath), true);
            $previous = (array) ($snapshot['hosts'] ?? []);
            $state = (array) ($snapshot['state'] ?? []);
        }

        foreach ($previous as $h) {
            if (! isset($state[$h]) || ! is_array($state[$h])) {
                $state[$h] = ['missing_runs' => 0, 'last_seen_at' => null, 'sample' => null];
            }
        }

        $confirmRuns = max(1, (int) $this->option('confirm-runs'));
        $new = array_values(array_diff($current, $previous));
        $lostCandidates = array_values(array_diff($previous, $current));

        foreach ($current as $h) {
            $state[$h] = [
                'missing_runs' => 0,
                'last_seen_at' => now()->toIso8601String(),
                'sample' => $samples[$h] ?? ($state[$h]['sample'] ?? null),
            ];
        }
        foreach ($lostCandidates as $h) {
            $missing = (int) ($state[$h]['missing_runs'] ?? 0) + 1;
            $state[$h] = [
                'missing_runs' => $missing,
                'last_seen_at' => $state[$h]['last_seen_at'] ?? null,
                'sample' => $state[$h]['sample'] ?? null,
            ];
        }

        $lost = array_values(array_filter($lostCandidates, fn (string $h) => (int) ($state[$h]['missing_runs'] ?? 0) >= $confirmRuns));

        $hqPatterns = (array) config('seo.backlinks.high_quality_host_patterns', []);
        $highQualityHosts = array_values(array_filter($current, function (string $host) use ($hqPatterns) {
            foreach ($hqPatterns as $p) {
                if (@preg_match('/' . $p . '/i', $host)) {
                    return true;
                }
            }
            return false;
        }));

        $this->newLine();
        $this->line('<fg=cyan>--- New referring hosts (' . count($new) . ') ---</>');
        foreach (array_slice($new, 0, 15) as $h) {
            $this->line('  + ' . $h . '   ' . ($samples[$h] ?? ''));
        }
        $this->newLine();
        $this->line('<fg=cyan>--- Lost referring hosts (' . count($lost) . ') ---</>');
        foreach (array_slice($lost, 0, 15) as $h) $this->line('  - ' . $h);

        $this->newLine();
        $this->line('<fg=cyan>--- High-quality referring hosts (' . count($highQualityHosts) . ') ---</>');
        if (empty($highQualityHosts)) {
            $this->warn('  none detected — this matches Bing "lack of high-quality inbound links" warnings.');
        } else {
            foreach (array_slice($highQualityHosts, 0, 20) as $h) {
                $this->line('  * ' . $h);
            }
        }

        // Persist new snapshot.
        $disk->put($snapPath, json_encode([
            'updated_at' => now()->toIso8601String(),
            'hosts' => $current,
            'samples' => $samples,
            'state' => $state,
        ], JSON_PRETTY_PRINT));

        if ($this->option('markdown')) {
            $this->saveMarkdown($current, $new, $lost, $samples, $highQualityHosts);
        }
        if (! empty($lost)) {
            logger()->warning('seo:backlinks-monitor lost hosts', ['lost' => $lost]);
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    protected function fetchSerp(string $query, int $start): ?array
    {
        // Brave pages are 20 results wide, so
        // the caller's 10-per-page $start values map onto overlapping pages —
        // harmless here because hosts are deduped into a set.
        return app(\App\Services\BraveSearchService::class)
            ->organicResults($query, 20, $start);
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
     * @param array<int, string> $highQualityHosts
     */
    protected function saveMarkdown(array $current, array $new, array $lost, array $samples, array $highQualityHosts): void
    {
        $md = "# Backlink / mention monitor\n\n";
        $md .= 'Run: ' . now()->toIso8601String() . "\n\n";
        $md .= '**Referring hosts this run:** ' . count($current) . "\n\n";
        $md .= '**High-quality referring hosts:** ' . count($highQualityHosts) . "\n\n";

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

        $md .= "\n## High-quality referring hosts\n\n";
        $md .= empty($highQualityHosts) ? "_None detected. This can trigger Bing authority warnings._\n" : ('- ' . implode("\n- ", $highQualityHosts) . "\n");

        Storage::disk('local')->put('reports/backlinks-monitor.md', $md);
        $this->info('Saved: storage/app/reports/backlinks-monitor.md');
    }
}
