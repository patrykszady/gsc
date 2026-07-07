<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\UsesSearchConsoleApi;
use App\Models\GscCoverageState;
use App\Models\GscCoverageStateHistory;
use App\Models\GscRichResultIssue;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Run Search Console URL Inspection on a slice of our sitemap so we maintain a fresh
 * coverage map (verdict / canonical / fetch state) for every important page — not just the
 * ones flagged by reactive tools. Persists every result to `gsc_coverage_states` and writes
 * a markdown summary highlighting verdict changes since last run.
 *
 * Quota: the URL Inspection API is limited to ~2,000 calls/day per property. We default to
 * all sitemap URLs (or --limit override) and stagger calls 250ms apart.
 */
class SeoGscInspectBulk extends Command
{
    use UsesSearchConsoleApi;

    protected $signature = 'seo:gsc-inspect-bulk
        {--limit=0 : Maximum URLs to inspect this run (0 = all sitemap URLs)}
        {--sitemap= : Path to sitemap XML (default public/sitemap.xml)}
        {--strategy=stale : URL selection: stale|random|all}
        {--site= : GSC site URL override}
        {--markdown : Write reports/gsc-inspect-bulk.md}';

    protected $description = 'Bulk-run GSC URL Inspection against the sitemap and persist coverage state.';

    public function handle(): int
    {
        $token = $this->gscAccessToken();
        if (! $token) return self::FAILURE;

        $site = $this->gscSiteUrl($this->option('site'));
        $urls = $this->loadSitemapUrls((string) ($this->option('sitemap') ?: public_path('sitemap.xml')));
        if (empty($urls)) {
            $this->error('Sitemap has no URLs.');
            return self::FAILURE;
        }
        $this->info(sprintf('Loaded %d sitemap URLs.', count($urls)));

        $limit = (int) $this->option('limit');
        if ($limit <= 0) {
            $limit = count($urls);
        }

        $urls = $this->prioritize($urls, (string) $this->option('strategy'), $limit);
        $this->info(sprintf('Inspecting %d URLs (strategy=%s).', count($urls), $this->option('strategy')));

        $changes = [];
        $failures = 0;
        $inspected = 0;
        $richIssues = 0;

        foreach ($urls as $u) {
            usleep(250_000);
            try {
                $resp = Http::withToken($token)
                    ->timeout(30)
                    ->retry(2, 2000, fn ($e) => $e instanceof \Illuminate\Http\Client\ConnectionException, throw: false)
                    ->post(
                    'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',
                    ['inspectionUrl' => $u, 'siteUrl' => $site]
                );
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                // A transient network timeout must not abort the whole sweep.
                $failures++;
                $this->line(sprintf('  err   net  %s (%s)', $u, $e->getMessage()));

                continue;
            }
            if (! $resp->successful()) {
                $failures++;
                $this->line(sprintf('  err   %3d  %s', $resp->status(), $u));
                if ($resp->status() === 429) {
                    $this->warn('Quota hit; stopping early.');
                    break;
                }
                continue;
            }
            $inspected++;
            $inspection = $resp->json()['inspectionResult'] ?? [];
            $r = $inspection['indexStatusResult'] ?? [];
            $change = $this->persist($u, $r, $inspection['richResultsResult'] ?? []);
            if (($change['changed'] ?? false) === true) {
                $changes[] = $change;
            }
            $richIssues += (int) ($change['rich_issue_count'] ?? 0);
            $verdict = $r['verdict'] ?? '?';
            $coverage = $r['coverageState'] ?? '?';
            $this->line(sprintf('  %-7s %-45s %s', $verdict, substr($coverage, 0, 45), $u));
        }

        $this->info(sprintf('Done. inspected=%d failures=%d changes=%d rich_issues=%d', $inspected, $failures, count($changes), $richIssues));

        if ($this->option('markdown')) {
            $this->writeReport($inspected, $failures, $changes);
        }

        return self::SUCCESS;
    }

    /** @return array<int,string> */
    protected function loadSitemapUrls(string $path): array
    {
        if (! is_file($path)) return [];
        $xml = @simplexml_load_string((string) file_get_contents($path));
        if (! $xml) return [];
        $urls = [];
        foreach ($xml->url ?? [] as $u) {
            $loc = (string) $u->loc;
            if ($loc !== '') $urls[] = $loc;
        }
        return $urls;
    }

    /**
     * Pick URLs that need inspection most: oldest inspected_at first (stale),
     * else random sample, else all in declared order capped at the limit.
     *
     * @param array<int,string> $urls
     * @return array<int,string>
     */
    protected function prioritize(array $urls, string $strategy, int $limit): array
    {
        if ($strategy === 'random') {
            shuffle($urls);
            return array_slice($urls, 0, $limit);
        }
        if ($strategy === 'all') {
            return array_slice($urls, 0, $limit);
        }
        // stale: known rows ordered by inspected_at ASC, then never-inspected URLs to fill.
        $known = GscCoverageState::query()
            ->whereIn('url', $urls)
            ->orderBy('inspected_at')
            ->pluck('url')
            ->all();
        $unseen = array_values(array_diff($urls, $known));
        $merged = array_merge($unseen, $known); // never-seen first, then oldest stale
        return array_slice($merged, 0, $limit);
    }

    /**
     * Persist + return a change description when verdict/coverage/canonical shifted, else null.
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>|null
     */
    protected function persist(string $url, array $r, array $richResults): array
    {
        $verdict = $r['verdict'] ?? null;
        $coverage = $r['coverageState'] ?? null;
        $pageFetch = $r['pageFetchState'] ?? null;
        $lastCrawl = isset($r['lastCrawlTime']) ? Carbon::parse($r['lastCrawlTime']) : null;
        $userCanon = $r['userCanonical'] ?? null;
        $googleCanon = $r['googleCanonical'] ?? null;

        $existing = GscCoverageState::where('url', $url)->first();
        $changed = ! $existing
            || $existing->verdict !== $verdict
            || $existing->coverage_state !== $coverage
            || $existing->page_fetch_state !== $pageFetch
            || $existing->google_canonical !== $googleCanon;

        GscCoverageState::updateOrCreate(
            ['url' => $url],
            [
                'verdict' => $verdict,
                'coverage_state' => $coverage,
                'robots_txt_state' => $r['robotsTxtState'] ?? null,
                'indexing_state' => $r['indexingState'] ?? null,
                'page_fetch_state' => $pageFetch,
                'sitemap_url' => $r['sitemap'][0] ?? null,
                'last_crawl_time' => $lastCrawl,
                'user_canonical' => $userCanon,
                'google_canonical' => $googleCanon,
                'inspected_at' => now(),
                'last_changed_at' => $changed ? now() : ($existing->last_changed_at ?? now()),
                'consecutive_failures' => $verdict !== 'PASS'
                    ? (($existing->consecutive_failures ?? 0) + 1)
                    : 0,
            ]
        );

        $richIssueCount = $this->persistRichResults($url, $richResults);

        if (! $changed) {
            return [
                'url' => $url,
                'prev_verdict' => $existing?->verdict,
                'verdict' => $verdict,
                'prev_coverage' => $existing?->coverage_state,
                'coverage' => $coverage,
                'rich_issue_count' => $richIssueCount,
                'changed' => false,
            ];
        }

        GscCoverageStateHistory::create([
            'url' => $url,
            'verdict' => $verdict,
            'coverage_state' => $coverage,
            'page_fetch_state' => $pageFetch,
            'observed_at' => now(),
        ]);

        return [
            'url' => $url,
            'prev_verdict' => $existing?->verdict,
            'verdict' => $verdict,
            'prev_coverage' => $existing?->coverage_state,
            'coverage' => $coverage,
            'rich_issue_count' => $richIssueCount,
            'changed' => true,
        ];
    }

    /**
     * Persist current rich-result issues for a URL from URL Inspection response.
     *
     * @param array<string,mixed> $richResults
     */
    protected function persistRichResults(string $url, array $richResults): int
    {
        GscRichResultIssue::query()->where('url', $url)->delete();

        $items = $richResults['detectedItems'] ?? [];
        if (! is_array($items) || empty($items)) {
            return 0;
        }

        $rows = [];
        $inspectedAt = now();
        $verdict = is_string($richResults['verdict'] ?? null) ? (string) $richResults['verdict'] : null;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = (string) ($item['richResultType'] ?? $item['detectedItemType'] ?? 'Unknown');

            $issues = $item['issues'] ?? [];
            if (! is_array($issues) || empty($issues)) {
                continue;
            }

            foreach ($issues as $issue) {
                if (! is_array($issue)) {
                    continue;
                }

                $rows[] = [
                    'url' => $url,
                    'rich_result_type' => $type,
                    'issue_severity' => (string) ($issue['severity'] ?? 'UNKNOWN'),
                    'issue_type' => (string) ($issue['issueType'] ?? $issue['issueMessage'] ?? 'unknown_issue'),
                    'issue_message' => (string) ($issue['issueMessage'] ?? $issue['issueType'] ?? 'Unknown issue'),
                    'verdict' => $verdict,
                    'inspected_at' => $inspectedAt,
                    'created_at' => $inspectedAt,
                    'updated_at' => $inspectedAt,
                ];
            }
        }

        if (! empty($rows)) {
            GscRichResultIssue::query()->insert($rows);
        }

        return count($rows);
    }

    /**
     * @param array<int,array<string,mixed>> $changes
     */
    protected function writeReport(int $inspected, int $failures, array $changes): void
    {
        $lines = [];
        $lines[] = '# GSC URL Inspection — bulk run';
        $lines[] = '';
        $lines[] = '_Generated: ' . now()->toIso8601String() . '_';
        $lines[] = '';
        $lines[] = "- Inspected: **{$inspected}**";
        $lines[] = "- API failures: **{$failures}**";
        $lines[] = '- State changes: **' . count($changes) . '**';
        $lines[] = '';

        $totals = GscCoverageState::query()
            ->selectRaw('verdict, coverage_state, count(*) as n')
            ->groupBy('verdict', 'coverage_state')
            ->orderByDesc('n')
            ->get();
        $lines[] = '## Current coverage snapshot';
        $lines[] = '';
        $lines[] = '| Verdict | Coverage state | Count |';
        $lines[] = '|---|---|---|';
        foreach ($totals as $t) {
            $lines[] = sprintf('| %s | %s | %d |', $t->verdict ?? '?', $t->coverage_state ?? '?', $t->n);
        }
        $lines[] = '';

        if ($changes) {
            $lines[] = '## State changes this run';
            $lines[] = '';
            $lines[] = '| URL | Verdict (prev → now) | Coverage (prev → now) |';
            $lines[] = '|---|---|---|';
            foreach ($changes as $c) {
                $lines[] = sprintf(
                    '| %s | %s → %s | %s → %s |',
                    $c['url'],
                    $c['prev_verdict'] ?? '–',
                    $c['verdict'] ?? '–',
                    $c['prev_coverage'] ?? '–',
                    $c['coverage'] ?? '–',
                );
            }
            $lines[] = '';
        }

        Storage::disk('local')->put('reports/gsc-inspect-bulk.md', implode("\n", $lines));
        $this->info('Wrote reports/gsc-inspect-bulk.md');
    }
}
