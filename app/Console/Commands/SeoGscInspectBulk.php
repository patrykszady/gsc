<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\UsesSearchConsoleApi;
use App\Models\GscCoverageState;
use App\Models\GscCoverageStateHistory;
use App\Models\GscRichResultIssue;
use App\Models\Tracked404;
use App\Support\Seo\UrlInspectionQuota;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Run Search Console URL Inspection on a slice of our sitemap so we maintain a fresh
 * coverage map (verdict / canonical / fetch state) for every important page — not just the
 * ones flagged by reactive tools. Persists every result to `gsc_coverage_states` and writes
 * a markdown summary highlighting verdict changes since last run.
 *
 * Quota: the URL Inspection API allows 2,000 calls/day and 600/minute per property, shared
 * with the Console-export import and the admin's inspect button, so every call is counted
 * through UrlInspectionQuota and the run stops while it still has an allowance left.
 *
 * Scope: the sitemap is the canonical set, but Search Console's "why pages aren't indexed"
 * report counts URLs the sitemap never carried — the paths Googlebot 404s on, and anything
 * imported from a Console export. --include decides which of those pools the sweep keeps
 * fresh alongside the sitemap.
 */
class SeoGscInspectBulk extends Command
{
    use UsesSearchConsoleApi;

    protected $signature = 'seo:gsc-inspect-bulk
        {--limit=0 : Maximum URLs to inspect this run (0 = all sitemap URLs)}
        {--sitemap= : Path to sitemap XML (default public/sitemap.xml)}
        {--strategy=stale : URL selection: stale|random|all}
        {--include=sitemap,coverage,tracked : Pools to sweep: sitemap, coverage (rows the sitemap no longer carries), tracked (paths Googlebot 404s on)}
        {--urls=* : Inspect these URLs instead of the sitemap (a Console export, tracked 404s)}
        {--source=sitemap : What the rows are: sitemap|console|tracked}
        {--reason= : The Console reason the URLs were exported under, kept on each row}
        {--site= : GSC site URL override}
        {--markdown : Write reports/gsc-inspect-bulk.md}';

    protected $description = 'Bulk-run GSC URL Inspection against the sitemap and persist coverage state.';

    public function handle(): int
    {
        $token = $this->gscAccessToken();
        if (! $token) {
            return self::FAILURE;
        }

        $site = $this->gscSiteUrl($this->option('site'));
        $explicit = array_values(array_filter(array_map('trim', (array) $this->option('urls'))));
        if ($explicit !== []) {
            $urls = $explicit;
        } else {
            $sitemapUrls = $this->loadSitemapUrls((string) ($this->option('sitemap') ?: public_path('sitemap.xml')));
            $pools = array_map('trim', explode(',', (string) $this->option('include')));
            $urls = in_array('sitemap', $pools, true) ? $sitemapUrls : [];
            $offSitemap = $this->offSitemapUrls($pools, $sitemapUrls);
            $urls = array_values(array_unique(array_merge($urls, $offSitemap)));
            if ($offSitemap !== []) {
                $this->info(sprintf('Loaded %d sitemap URL(s) + %d that the sitemap does not carry.', count($sitemapUrls), count($offSitemap)));
            } else {
                $this->info(sprintf('Loaded %d sitemap URLs.', count($sitemapUrls)));
            }
        }

        if (empty($urls)) {
            $this->error('Nothing to inspect.');

            return self::FAILURE;
        }

        $limit = (int) $this->option('limit');
        if ($limit <= 0) {
            $limit = count($urls);
        }

        $urls = $explicit !== [] ? $urls : $this->prioritize($urls, (string) $this->option('strategy'), $limit);

        // The allowance is the property's, not this command's: an import or an
        // admin inspection earlier today has already spent part of it.
        $allowed = UrlInspectionQuota::reserve(count($urls));
        if ($allowed <= 0) {
            $this->warn(sprintf(
                'The URL Inspection allowance for today is spent (%d/%d). It resets %s.',
                UrlInspectionQuota::used(), UrlInspectionQuota::dailyLimit(), UrlInspectionQuota::resetsAt()->diffForHumans()
            ));

            return self::SUCCESS;
        }
        if ($allowed < count($urls)) {
            $this->warn(sprintf('Only %d of %d URLs fit in today\'s remaining allowance; the rest go on the next run.', $allowed, count($urls)));
            $urls = array_slice($urls, 0, $allowed);
        }

        $this->info(sprintf('Inspecting %d URLs (strategy=%s, %d left in today\'s allowance).', count($urls), $this->option('strategy'), UrlInspectionQuota::remaining()));

        $changes = [];
        $failures = 0;
        $inspected = 0;
        $richIssues = 0;

        $inspect = function (string $u) use (&$token, $site) {
            return Http::withToken($token)
                // 60s, not 30: the URL Inspection API intermittently takes
                // >30s per call (three cURL-28 aborts in the July-August log,
                // each killing that night's sweep via the schedule's failure
                // hook). The retry below only covers connection drops; a slow
                // -but-alive response needs the longer budget.
                ->timeout(60)
                ->retry(2, 2000, fn ($e) => $e instanceof ConnectionException, throw: false)
                ->post(
                    'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',
                    ['inspectionUrl' => $u, 'siteUrl' => $site]
                );
        };
        $lastTokenRefresh = null;

        // 600 calls a minute is the other ceiling; 250ms apart is 240/min.
        $pacing = max(100_000, (int) ceil(60_000_000 / UrlInspectionQuota::perMinuteLimit()) * 2);

        foreach ($urls as $u) {
            usleep($pacing);
            UrlInspectionQuota::consume();
            try {
                $resp = $inspect($u);

                // A full sweep outlives the ~1h access token: refresh in place
                // on 401 and retry the same URL instead of failing the tail of
                // the run. Rate-limited so a genuinely broken credential can't
                // hammer the token endpoint.
                if ($resp->status() === 401
                    && ($lastTokenRefresh === null || $lastTokenRefresh->diffInMinutes(now()) >= 5)) {
                    $lastTokenRefresh = now();
                    $this->warn('  access token expired mid-sweep — refreshing…');
                    $fresh = $this->gscAccessToken(forceRefresh: true);
                    if ($fresh) {
                        $token = $fresh;
                        $resp = $inspect($u);
                    }
                }
            } catch (ConnectionException $e) {
                // A transient network timeout must not abort the whole sweep.
                $failures++;
                $this->line(sprintf('  err   net  %s (%s)', $u, $e->getMessage()));

                continue;
            }
            if (! $resp->successful()) {
                $failures++;
                $this->line(sprintf('  err   %3d  %s', $resp->status(), $u));
                if ($resp->status() === 429) {
                    // Google is the authority on the allowance, whatever our
                    // own count says — another client shares this property.
                    UrlInspectionQuota::markExhausted();
                    $this->warn('Google refused on quota; stopping early. It resets '.UrlInspectionQuota::resetsAt()->diffForHumans().'.');
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

        $this->info(sprintf(
            'Done. inspected=%d failures=%d changes=%d rich_issues=%d allowance_left=%d',
            $inspected, $failures, count($changes), $richIssues, UrlInspectionQuota::remaining()
        ));

        if ($this->option('markdown')) {
            $this->writeReport($inspected, $failures, $changes);
        }

        return self::SUCCESS;
    }

    /**
     * The URLs Search Console counts that our sitemap does not carry.
     *
     * 'coverage' keeps refreshing rows whose URL has left the sitemap or
     * arrived from a Console export — without this the sweep can never
     * revisit them, so a fix there would never show. 'tracked' adds the
     * paths Googlebot actually 404s on, which is the only way those appear
     * in our own copy of the report at all.
     *
     * @param  list<string>  $pools
     * @param  list<string>  $sitemapUrls
     * @return list<string>
     */
    protected function offSitemapUrls(array $pools, array $sitemapUrls): array
    {
        $known = array_flip($sitemapUrls);
        $extra = [];

        if (in_array('coverage', $pools, true)) {
            foreach (GscCoverageState::query()->orderBy('inspected_at')->pluck('url') as $url) {
                if (! isset($known[$url])) {
                    $extra[$url] = true;
                }
            }
        }

        if (in_array('tracked', $pools, true)) {
            $base = rtrim((string) config('app.url'), '/');
            $paths = Tracked404::query()
                ->where('user_agent', 'like', '%Googlebot%')
                ->orderByDesc('hit_count')
                ->pluck('path');
            foreach ($paths as $path) {
                $url = $base.'/'.ltrim((string) $path, '/');
                if (! isset($known[$url])) {
                    $extra[$url] = true;
                }
            }
        }

        return array_keys($extra);
    }

    /** @return array<int,string> */
    protected function loadSitemapUrls(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $xml = @simplexml_load_string((string) file_get_contents($path));
        if (! $xml) {
            return [];
        }
        $urls = [];
        foreach ($xml->url ?? [] as $u) {
            $loc = (string) $u->loc;
            if ($loc !== '') {
                $urls[] = $loc;
            }
        }

        return $urls;
    }

    /**
     * Pick URLs that need inspection most: oldest inspected_at first (stale),
     * else random sample, else all in declared order capped at the limit.
     *
     * @param  array<int,string>  $urls
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
     * @param  array<string,mixed>  $r
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
                // A sitemap sweep never demotes a row imported from the
                // Console: it keeps the source that first explained it.
                'source' => $existing?->source && $existing->source !== 'sitemap' ? $existing->source : (string) ($this->option('source') ?: 'sitemap'),
                'console_reason' => $this->option('reason') ? (string) $this->option('reason') : ($existing->console_reason ?? null),
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
     * @param  array<string,mixed>  $richResults
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
     * @param  array<int,array<string,mixed>>  $changes
     */
    protected function writeReport(int $inspected, int $failures, array $changes): void
    {
        $lines = [];
        $lines[] = '# GSC URL Inspection — bulk run';
        $lines[] = '';
        $lines[] = '_Generated: '.now()->toIso8601String().'_';
        $lines[] = '';
        $lines[] = "- Inspected: **{$inspected}**";
        $lines[] = "- API failures: **{$failures}**";
        $lines[] = '- State changes: **'.count($changes).'**';
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
