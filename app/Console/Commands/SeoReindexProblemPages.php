<?php

namespace App\Console\Commands;

use App\Models\AreaServed;
use App\Models\GscCoverageState;
use App\Models\GscCoverageStateHistory;
use App\Models\OAuthToken;
use App\Services\IndexNowService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Push the current GSC "problem" URLs back through IndexNow + warm Cloudflare cache so the next
 * Googlebot crawl sees a fresh 200. Uses Search Console URL Inspection as the live source of
 * truth for which pages are flagged "Crawled - currently not indexed" or have stale 4xx/5xx
 * coverage. Falls back to a hard-coded list when --auto isn't passed.
 */
class SeoReindexProblemPages extends Command
{
    /** @var array<int,string> */
    protected array $excludedGone410 = [];

    /** @var array<int,string> */
    protected array $excludedNotInSitemap = [];

    protected $signature = 'seo:reindex-problem-pages
        {--urls=* : Specific URLs to resubmit (skips auto-detection)}
        {--auto : Pull problem URLs from latest GSC inspection of priority pages}
        {--site= : GSC site URL override}
        {--dry-run : Show what would be submitted without calling IndexNow}';

    protected $description = 'Resubmit problem URLs to IndexNow + warm cache so Googlebot re-crawls them sooner.';

    public function handle(IndexNowService $indexNow): int
    {
        $this->excludedGone410 = [];
        $this->excludedNotInSitemap = [];

        $urls = (array) $this->option('urls');

        if (empty($urls) && $this->option('auto')) {
            $urls = $this->detectProblemUrls();
        }

        if (empty($urls)) {
            $this->warn('No URLs provided. Use --urls=https://... or --auto.');
            return self::FAILURE;
        }

        $urls = array_values(array_unique($urls));
        $this->info('Submitting ' . count($urls) . ' URL(s):');
        foreach ($urls as $u) $this->line('  - ' . $u);

        if ($this->option('dry-run')) {
            $this->warn('Dry run. Nothing submitted.');
            $this->writeRunReport($urls, [], true, null);
            return self::SUCCESS;
        }

        // Warm URLs so the next bot crawl serves a fresh response. Skip intentionally
        // removed 410 pages so they are not repeatedly re-submitted to IndexNow.
        $submitUrls = [];
        foreach ($urls as $u) {
            $resp = Http::timeout(20)->withHeaders([
                'User-Agent' => 'GSConstruction-SEO-Warmer/1.0',
                'Cache-Control' => 'no-cache',
            ])->get($u);
            $this->line(sprintf('  warm  %3d  %s', $resp->status(), $u));

            if ($resp->status() === 410) {
                $this->line('  skip 410  ' . $u);
                $this->excludedGone410[] = $u;
                $this->markGone($u);
                continue;
            }

            $submitUrls[] = $u;
        }

        $submitUrls = array_values(array_unique($submitUrls));
        if (empty($submitUrls)) {
            $this->warn('No URLs left to submit after warm/410 filtering.');
            $this->writeRunReport($urls, [], false, true);
            return self::SUCCESS;
        }

        if (! $indexNow->isEnabled()) {
            $this->warn('IndexNow disabled in config; skipping submit.');
            $this->writeRunReport($urls, $submitUrls, false, null);
            return self::SUCCESS;
        }

        $ok = $indexNow->submitBatch($submitUrls);
        $ok ? $this->info('IndexNow batch accepted.') : $this->error('IndexNow batch failed (check logs).');

        $this->writeRunReport($urls, $submitUrls, false, $ok);

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Use GSC URL Inspection to find pages that need a re-crawl signal:
     * verdict != PASS, or coverage 'Blocked due to access forbidden' / 'Crawled - currently not indexed'.
     *
     * @return array<int,string>
     */
    protected function detectProblemUrls(): array
    {
        $token = $this->fetchAccessToken();
        if (! $token) {
            $this->warn('Falling back to cached GSC coverage states (token unavailable).');
            return $this->cachedProblemUrls();
        }

        $site = (string) ($this->option('site') ?: config('seo.search_console.site_url'));
        $base = str_starts_with($site, 'sc-domain:')
            ? 'https://' . substr($site, strlen('sc-domain:'))
            : rtrim($site, '/');

        $candidates = [
            $base . '/',
            $base . '/services/kitchen-remodeling',
            $base . '/services/bathroom-remodeling',
            $base . '/services/home-remodeling',
            $base . '/services/basement-remodeling',
            $base . '/services/home-additions',
            $base . '/areas-served',
        ];
        AreaServed::query()->orderBy('city')->get()
            ->each(function ($a) use (&$candidates, $base) { $candidates[] = $base . '/areas-served/' . $a->slug; });

        $problems = [];
        $checked = 0;
        $cap = 60; // GSC inspection quota is tight (~600/day); cap per run.
        foreach ($candidates as $u) {
            if ($checked++ >= $cap) break;
            usleep(200_000);
            $resp = Http::withToken($token)->timeout(30)->post(
                'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',
                ['inspectionUrl' => $u, 'siteUrl' => $site]
            );
            if (! $resp->successful()) continue;
            $r = $resp->json()['inspectionResult']['indexStatusResult'] ?? [];
            $verdict = $r['verdict'] ?? '?';
            $coverage = $r['coverageState'] ?? '?';

            $this->persistInspection($u, $r);

            if ($verdict !== 'PASS' || str_contains(strtolower($coverage), 'forbidden') || str_contains(strtolower($coverage), 'not indexed')) {
                $problems[] = $u;
                $this->line(sprintf('  flag  %-8s %-45s %s', $verdict, substr($coverage, 0, 45), $u));
            }
        }

        if (! empty($problems)) {
            return $problems;
        }

        $cached = $this->cachedProblemUrls();
        if (! empty($cached)) {
            $this->warn('Live inspection returned no problem URLs; using cached problem states.');
        }

        return $cached;
    }

    /**
     * Use persisted inspection results when live URL Inspection cannot run.
     *
     * @return array<int,string>
     */
    protected function cachedProblemUrls(): array
    {
        $rows = GscCoverageState::query()
            ->where('inspected_at', '>=', now()->subDays(45))
            ->where(function ($q): void {
                $q->where('verdict', '!=', 'PASS')
                    ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%forbidden%'])
                    ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%not indexed%'])
                    ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%soft 404%'])
                    ->orWhereRaw('LOWER(COALESCE(coverage_state, "")) like ?', ['%duplicate%']);
            })
            ->whereRaw('LOWER(COALESCE(page_fetch_state, "")) not like ?', ['%gone_410%'])
            ->orderByDesc('inspected_at')
            ->limit(120)
            ->pluck('url')
            ->filter()
            ->values()
            ->all();

        $rows = $this->filterToCurrentSitemap($rows);

        $this->line('  cached problem URLs: ' . count($rows));

        return $rows;
    }

    /**
     * Keep only URLs still present in the current sitemap so stale removed URLs
     * (e.g. intentional 410 routes) do not get re-submitted forever.
     *
     * @param array<int,string> $urls
     * @return array<int,string>
     */
    protected function filterToCurrentSitemap(array $urls): array
    {
        $path = public_path('sitemap.xml');
        if (! is_file($path)) {
            return $urls;
        }

        $xml = @simplexml_load_string((string) file_get_contents($path));
        if (! $xml || ! isset($xml->url)) {
            return $urls;
        }

        $inSitemap = [];
        foreach ($xml->url as $node) {
            $loc = trim((string) ($node->loc ?? ''));
            if ($loc !== '') {
                $inSitemap[$loc] = true;
            }
        }

        $kept = [];
        foreach ($urls as $u) {
            if (isset($inSitemap[$u])) {
                $kept[] = $u;
            } else {
                $this->excludedNotInSitemap[] = $u;
            }
        }

        return array_values($kept);
    }

    protected function markGone(string $url): void
    {
        GscCoverageState::query()
            ->where('url', $url)
            ->update([
                'page_fetch_state' => 'GONE_410',
                'last_changed_at' => now(),
            ]);
    }

    /**
     * @param array<int,string> $detectedUrls
     * @param array<int,string> $submittedUrls
     */
    protected function writeRunReport(array $detectedUrls, array $submittedUrls, bool $dryRun, ?bool $indexNowAccepted): void
    {
        $lines = [];
        $lines[] = '# Reindex Problem URLs — Last Run';
        $lines[] = '';
        $lines[] = '- Generated: ' . now()->toIso8601String();
        $lines[] = '- Mode: ' . ($dryRun ? 'dry-run' : 'live');
        $lines[] = '- Auto detection: ' . ($this->option('auto') ? 'yes' : 'no');
        $lines[] = '- Detected URLs: **' . count($detectedUrls) . '**';
        $lines[] = '- Submitted URLs: **' . count($submittedUrls) . '**';
        $lines[] = '- Excluded (410): **' . count($this->excludedGone410) . '**';
        $lines[] = '- Excluded (not in sitemap): **' . count($this->excludedNotInSitemap) . '**';
        if ($indexNowAccepted !== null) {
            $lines[] = '- IndexNow accepted: **' . ($indexNowAccepted ? 'yes' : 'no') . '**';
        }
        $lines[] = '';

        $lines[] = '## Submitted URLs';
        $lines[] = '';
        if (empty($submittedUrls)) {
            $lines[] = '_None_';
        } else {
            foreach ($submittedUrls as $u) {
                $lines[] = '- ' . $u;
            }
        }
        $lines[] = '';

        $lines[] = '## Excluded URLs (410 Gone)';
        $lines[] = '';
        if (empty($this->excludedGone410)) {
            $lines[] = '_None_';
        } else {
            foreach (array_values(array_unique($this->excludedGone410)) as $u) {
                $lines[] = '- ' . $u;
            }
        }
        $lines[] = '';

        $lines[] = '## Excluded URLs (not in sitemap)';
        $lines[] = '';
        if (empty($this->excludedNotInSitemap)) {
            $lines[] = '_None_';
        } else {
            foreach (array_values(array_unique($this->excludedNotInSitemap)) as $u) {
                $lines[] = '- ' . $u;
            }
        }
        $lines[] = '';

        $relativePath = 'reports/reindex-problem-pages-last.md';
        Storage::disk('local')->put($relativePath, implode("\n", $lines));
        $this->line('Wrote report: ' . Storage::disk('local')->path($relativePath));
    }

    /**
     * Upsert a GSC URL Inspection result and append a history row when state changed.
     *
     * @param array<string,mixed> $r indexStatusResult payload from Search Console.
     */
    protected function persistInspection(string $url, array $r): void
    {
        $verdict = $r['verdict'] ?? null;
        $coverage = $r['coverageState'] ?? null;
        $pageFetch = $r['pageFetchState'] ?? null;
        $lastCrawl = isset($r['lastCrawlTime']) ? Carbon::parse($r['lastCrawlTime']) : null;

        $existing = GscCoverageState::where('url', $url)->first();
        $stateChanged = ! $existing
            || $existing->verdict !== $verdict
            || $existing->coverage_state !== $coverage
            || $existing->page_fetch_state !== $pageFetch;

        $row = GscCoverageState::updateOrCreate(
            ['url' => $url],
            [
                'verdict' => $verdict,
                'coverage_state' => $coverage,
                'robots_txt_state' => $r['robotsTxtState'] ?? null,
                'indexing_state' => $r['indexingState'] ?? null,
                'page_fetch_state' => $pageFetch,
                'sitemap_url' => $r['sitemap'][0] ?? null,
                'last_crawl_time' => $lastCrawl,
                'user_canonical' => $r['userCanonical'] ?? null,
                'google_canonical' => $r['googleCanonical'] ?? null,
                'inspected_at' => now(),
                'last_changed_at' => $stateChanged ? now() : ($existing->last_changed_at ?? now()),
                'consecutive_failures' => $verdict !== 'PASS'
                    ? (($existing->consecutive_failures ?? 0) + 1)
                    : 0,
            ]
        );

        if ($stateChanged) {
            GscCoverageStateHistory::create([
                'url' => $url,
                'verdict' => $verdict,
                'coverage_state' => $coverage,
                'page_fetch_state' => $pageFetch,
                'observed_at' => now(),
            ]);
        }
    }

    protected function fetchAccessToken(): ?string
    {
        $row = OAuthToken::forProvider(SearchConsoleAuth::PROVIDER);
        if (! $row || ! $row->refresh_token) {
            $this->error('No Search Console OAuth token. Run: php artisan seo:gsc-auth');
            return null;
        }
        if ($row->hasValidAccessToken()) return $row->access_token;

        $resp = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.search_console.client_id'),
            'client_secret' => config('services.google.search_console.client_secret'),
            'refresh_token' => $row->refresh_token,
            'grant_type' => 'refresh_token',
        ]);
        if (! $resp->successful()) { $this->error('Refresh failed: ' . $resp->body()); return null; }
        $d = $resp->json();
        $row->access_token = $d['access_token'] ?? null;
        $row->access_token_expires_at = now()->addSeconds(((int) ($d['expires_in'] ?? 3600)) - 120);
        $row->save();
        return $row->access_token;
    }
}
