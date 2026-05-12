<?php

namespace App\Console\Commands;

use App\Models\AreaServed;
use App\Models\OAuthToken;
use App\Services\IndexNowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Push the current GSC "problem" URLs back through IndexNow + warm Cloudflare cache so the next
 * Googlebot crawl sees a fresh 200. Uses Search Console URL Inspection as the live source of
 * truth for which pages are flagged "Crawled - currently not indexed" or have stale 4xx/5xx
 * coverage. Falls back to a hard-coded list when --auto isn't passed.
 */
class SeoReindexProblemPages extends Command
{
    protected $signature = 'seo:reindex-problem-pages
        {--urls=* : Specific URLs to resubmit (skips auto-detection)}
        {--auto : Pull problem URLs from latest GSC inspection of priority pages}
        {--site= : GSC site URL override}
        {--dry-run : Show what would be submitted without calling IndexNow}';

    protected $description = 'Resubmit problem URLs to IndexNow + warm cache so Googlebot re-crawls them sooner.';

    public function handle(IndexNowService $indexNow): int
    {
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
            return self::SUCCESS;
        }

        // Warm the URL so the next bot crawl serves a fresh 200 (busts CF cache too).
        foreach ($urls as $u) {
            $resp = Http::timeout(20)->withHeaders([
                'User-Agent' => 'GSConstruction-SEO-Warmer/1.0',
                'Cache-Control' => 'no-cache',
            ])->get($u);
            $this->line(sprintf('  warm  %3d  %s', $resp->status(), $u));
        }

        if (! $indexNow->isEnabled()) {
            $this->warn('IndexNow disabled in config; skipping submit.');
            return self::SUCCESS;
        }

        $ok = $indexNow->submitBatch($urls);
        $ok ? $this->info('IndexNow batch accepted.') : $this->error('IndexNow batch failed (check logs).');

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
        if (! $token) return [];

        $site = (string) ($this->option('site') ?: config('seo.search_console.site_url'));
        $base = str_starts_with($site, 'sc-domain:')
            ? 'https://' . substr($site, strlen('sc-domain:'))
            : rtrim($site, '/');

        $candidates = [
            $base . '/',
            $base . '/services/kitchen-remodeling',
            $base . '/services/bathroom-remodeling',
            $base . '/services/home-remodeling',
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
            if ($verdict !== 'PASS' || str_contains(strtolower($coverage), 'forbidden') || str_contains(strtolower($coverage), 'not indexed')) {
                $problems[] = $u;
                $this->line(sprintf('  flag  %-8s %-45s %s', $verdict, substr($coverage, 0, 45), $u));
            }
        }

        return $problems;
    }

    protected function fetchAccessToken(): ?string
    {
        $row = OAuthToken::forProvider(SearchConsoleAuth::PROVIDER);
        if (! $row || ! $row->refresh_token) {
            $this->error('No Search Console OAuth token. Run: php artisan search-console:auth');
            return null;
        }
        if ($row->hasValidAccessToken()) return $row->access_token;

        $resp = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.business_profile.client_id'),
            'client_secret' => config('services.google.business_profile.client_secret'),
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
