<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\UsesSearchConsoleApi;
use App\Models\AreaServed;
use App\Models\GscCoverageState;
use App\Models\GscCoverageStateHistory;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Daily canary for the most critical URLs (homepage, top services, top areas). The Search Console
 * API does not expose manual actions or security issues programmatically, but a sudden verdict
 * regression on the homepage is the best automated signal we can get for either — alongside
 * Cloudflare / robots / canonical issues.
 *
 * Exits non-zero (and logs at error level) when:
 *   - Any URL verdict regresses from PASS → anything else
 *   - The homepage coverage state changes for any reason
 *   - The robots.txt state changes
 *
 * Pair with a scheduler `->emailOutputOnFailure(env('SEO_ALERT_EMAIL'))` for delivery.
 */
class SeoGscCriticalHealth extends Command
{
    use UsesSearchConsoleApi;

    protected $signature = 'seo:gsc-critical-health
        {--site= : GSC site URL override}
        {--top-areas=5 : How many top areas to include in the critical set}
        {--markdown : Write reports/gsc-critical-health.md}';

    protected $description = 'Daily canary: alert on verdict regressions on critical URLs (manual-action proxy).';

    public function handle(): int
    {
        $token = $this->gscAccessToken();
        if (! $token) return self::FAILURE;

        $site = $this->gscSiteUrl($this->option('site'));
        $base = $this->gscBaseUrl($this->option('site'));

        $critical = [
            $base . '/',
            $base . '/reviews',
            $base . '/projects',
            $base . '/areas-served',
            $base . '/services/kitchen-remodeling',
            $base . '/services/bathroom-remodeling',
            $base . '/services/home-remodeling',
            $base . '/services/basement-remodeling',
            $base . '/services/home-additions',
        ];
        AreaServed::query()
            ->orderByDesc('city')
            ->limit((int) $this->option('top-areas'))
            ->get()
            ->each(function ($a) use (&$critical, $base) {
                $critical[] = $base . '/areas-served/' . $a->slug;
            });

        $alerts = [];
        $rows = [];

        foreach ($critical as $url) {
            $prev = GscCoverageState::where('url', $url)->first();
            $resp = Http::withToken($token)->timeout(30)->post(
                'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',
                ['inspectionUrl' => $url, 'siteUrl' => $site]
            );
            usleep(250_000);
            if (! $resp->successful()) {
                $alerts[] = ['url' => $url, 'kind' => 'api_error', 'detail' => 'HTTP ' . $resp->status()];
                continue;
            }
            $r = $resp->json()['inspectionResult']['indexStatusResult'] ?? [];
            $verdict = $r['verdict'] ?? '?';
            $coverage = $r['coverageState'] ?? '?';
            $robots = $r['robotsTxtState'] ?? '?';

            // Persist current snapshot so the bulk inspector and conflict report stay current.
            $this->persist($url, $r, $prev);

            $regressed = $prev && $prev->verdict === 'PASS' && $verdict !== 'PASS';
            $robotsChanged = $prev && $prev->robots_txt_state && $prev->robots_txt_state !== $robots;
            $homeCovChanged = $url === ($base . '/') && $prev && $prev->coverage_state !== $coverage;

            $rows[] = compact('url', 'verdict', 'coverage', 'robots');

            if ($regressed) {
                $alerts[] = ['url' => $url, 'kind' => 'verdict_regression', 'detail' => "PASS → {$verdict} / {$coverage}"];
            }
            if ($robotsChanged) {
                $alerts[] = ['url' => $url, 'kind' => 'robots_change', 'detail' => "{$prev->robots_txt_state} → {$robots}"];
            }
            if ($homeCovChanged) {
                $alerts[] = ['url' => $url, 'kind' => 'homepage_coverage_change', 'detail' => "{$prev->coverage_state} → {$coverage}"];
            }

            $this->line(sprintf('  %-7s %-30s robots=%-15s %s', $verdict, substr($coverage, 0, 30), $robots, $url));
        }

        if ($alerts) {
            $this->error('Critical-health alerts: ' . count($alerts));
            foreach ($alerts as $a) {
                $msg = sprintf('[%s] %s — %s', $a['kind'], $a['url'], $a['detail']);
                $this->error('  ' . $msg);
                Log::channel(config('logging.default'))->error('seo:gsc-critical-health ' . $msg);
            }
        } else {
            $this->info('All critical URLs PASS.');
        }

        if ($this->option('markdown')) {
            $this->writeReport($rows, $alerts);
        }

        return $alerts ? self::FAILURE : self::SUCCESS;
    }

    protected function persist(string $url, array $r, ?GscCoverageState $existing): void
    {
        $verdict = $r['verdict'] ?? null;
        $coverage = $r['coverageState'] ?? null;
        $pageFetch = $r['pageFetchState'] ?? null;
        $googleCanon = $r['googleCanonical'] ?? null;

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
                'last_crawl_time' => isset($r['lastCrawlTime']) ? Carbon::parse($r['lastCrawlTime']) : null,
                'user_canonical' => $r['userCanonical'] ?? null,
                'google_canonical' => $googleCanon,
                'inspected_at' => now(),
                'last_changed_at' => $changed ? now() : ($existing->last_changed_at ?? now()),
                'consecutive_failures' => $verdict !== 'PASS'
                    ? (($existing->consecutive_failures ?? 0) + 1)
                    : 0,
            ]
        );

        if ($changed) {
            GscCoverageStateHistory::create([
                'url' => $url,
                'verdict' => $verdict,
                'coverage_state' => $coverage,
                'page_fetch_state' => $pageFetch,
                'observed_at' => now(),
            ]);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,array<string,mixed>> $alerts
     */
    protected function writeReport(array $rows, array $alerts): void
    {
        $lines = [];
        $lines[] = '# GSC Critical-page health';
        $lines[] = '';
        $lines[] = '_Generated: ' . now()->toIso8601String() . '_';
        $lines[] = '';
        $lines[] = '_Note: Google does not expose Manual Actions or Security Issues via API. This daily canary watches the most critical URLs for verdict regressions, which is the best automated proxy for those events plus Cloudflare / robots / canonical incidents._';
        $lines[] = '';
        if ($alerts) {
            $lines[] = '## ⚠ Alerts';
            $lines[] = '';
            foreach ($alerts as $a) {
                $lines[] = "- **{$a['kind']}** — {$a['url']} — {$a['detail']}";
            }
            $lines[] = '';
        } else {
            $lines[] = '_All critical URLs PASS._';
            $lines[] = '';
        }
        $lines[] = '## Snapshot';
        $lines[] = '';
        $lines[] = '| URL | Verdict | Coverage | robots |';
        $lines[] = '|---|---|---|---|';
        foreach ($rows as $r) {
            $lines[] = sprintf('| %s | %s | %s | %s |', $r['url'], $r['verdict'], $r['coverage'], $r['robots']);
        }
        Storage::disk('local')->put('reports/gsc-critical-health.md', implode("\n", $lines));
        $this->info('Wrote reports/gsc-critical-health.md');
    }
}
