<?php

namespace App\Console\Commands;

use App\Models\AreaServed;
use App\Models\OAuthToken;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class SearchConsoleAudit extends Command
{
    protected $signature = 'search-console:audit
        {--site= : Search Console site URL (default config seo.search_console.site_url)}
        {--days=28 : Number of days to look back for performance metrics}
        {--top=25 : How many top queries / pages to show}
        {--inspect : Run URL Inspection on key landing pages (slower; uses extra quota)}
        {--json= : Optional file path to dump the full audit as JSON}';

    protected $description = 'Audit Google Search Console: top queries/pages, sitemap status, and (optional) URL indexing inspection for our city pages.';

    public function handle(): int
    {
        $token = $this->fetchAccessToken();
        if (! $token) return self::FAILURE;

        $site = (string) ($this->option('site') ?: config('seo.search_console.site_url'));
        $days = max(1, (int) $this->option('days'));
        $topN = max(5, (int) $this->option('top'));

        $end = Carbon::now()->subDays(2)->toDateString(); // GSC has ~2 day lag
        $start = Carbon::now()->subDays(2 + $days)->toDateString();

        $this->info("Site: {$site}    Range: {$start} → {$end}");
        $this->newLine();

        $bundle = [
            'site' => $site,
            'range' => ['start' => $start, 'end' => $end],
            'sitemaps' => null,
            'top_queries' => null,
            'top_pages' => null,
            'city_pages' => null,
            'inspections' => null,
        ];

        // ----- Sitemaps -----
        $sm = Http::withToken($token)->timeout(30)->get(
            sprintf('https://www.googleapis.com/webmasters/v3/sites/%s/sitemaps', rawurlencode($site))
        );
        if ($sm->successful()) {
            $maps = $sm->json()['sitemap'] ?? [];
            $bundle['sitemaps'] = $maps;
            $this->line('SITEMAPS:');
            foreach ($maps as $m) {
                $warns = $m['warnings'] ?? 0;
                $errs  = $m['errors']   ?? 0;
                $submitted = $m['contents'][0]['submitted'] ?? '?';
                $indexed   = $m['contents'][0]['indexed']   ?? '?';
                $this->line(sprintf('  %-60s submitted=%s indexed=%s warns=%s errs=%s', $m['path'] ?? '', $submitted, $indexed, $warns, $errs));
            }
        } else {
            $this->warn('Sitemaps API failed: ' . $sm->status() . ' ' . $sm->body());
        }
        $this->newLine();

        // ----- Top queries -----
        $bundle['top_queries'] = $this->searchAnalytics($token, $site, $start, $end, ['query'], $topN);
        $this->line("TOP QUERIES (last {$days}d):");
        $this->printAnalytics($bundle['top_queries'], 'query');
        $this->newLine();

        // ----- Top pages -----
        $bundle['top_pages'] = $this->searchAnalytics($token, $site, $start, $end, ['page'], $topN);
        $this->line("TOP PAGES (last {$days}d):");
        $this->printAnalytics($bundle['top_pages'], 'page');
        $this->newLine();

        // ----- City pages performance -----
        $cityRows = $this->searchAnalytics($token, $site, $start, $end, ['page'], 1000, [
            'dimensionFilterGroups' => [[
                'filters' => [[
                    'dimension' => 'page',
                    'operator' => 'contains',
                    'expression' => '/areas-served/',
                ]],
            ]],
        ]);
        $bundle['city_pages'] = $cityRows;
        $this->line('CITY PAGE PERFORMANCE (/areas-served/*):');
        if (empty($cityRows)) {
            $this->warn('  (none receiving impressions in this range)');
        } else {
            $this->printAnalytics(array_slice($cityRows, 0, $topN), 'page');
        }
        $this->newLine();

        // ----- Detect un-impression-ed city pages -----
        $publicBase = $this->publicBaseUrl($site);
        $allCityUrls = AreaServed::query()->orderBy('city')->get()
            ->map(fn ($a) => $publicBase . '/areas-served/' . $a->slug)
            ->all();
        $impressed = collect($cityRows)->pluck('keys.0')->filter()->all();
        $missing = array_values(array_diff($allCityUrls, $impressed));
        $this->line('CITY PAGES WITH ZERO IMPRESSIONS (' . count($missing) . ' / ' . count($allCityUrls) . '):');
        foreach (array_slice($missing, 0, 30) as $u) $this->line('  - ' . $u);
        if (count($missing) > 30) $this->line('  …and ' . (count($missing) - 30) . ' more');
        $this->newLine();

        // ----- Optional URL inspection -----
        if ($this->option('inspect')) {
            $bundle['inspections'] = $this->inspectKeyUrls($token, $site);
        }

        if ($path = $this->option('json')) {
            file_put_contents($path, json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->info("Full audit written to {$path}");
        }

        return self::SUCCESS;
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

        if (! $resp->successful()) {
            $this->error('Refresh failed: ' . $resp->body());
            return null;
        }
        $d = $resp->json();
        $row->access_token = $d['access_token'] ?? null;
        $row->access_token_expires_at = now()->addSeconds(((int) ($d['expires_in'] ?? 3600)) - 120);
        $row->save();

        return $row->access_token;
    }

    /**
     * @param array<int,string>      $dims
     * @param array<string,mixed>    $extra
     * @return array<int,array<string,mixed>>
     */
    protected function searchAnalytics(string $token, string $site, string $start, string $end, array $dims, int $rowLimit, array $extra = []): array
    {
        $body = array_merge([
            'startDate' => $start,
            'endDate' => $end,
            'dimensions' => $dims,
            'rowLimit' => $rowLimit,
            'dataState' => 'all',
        ], $extra);

        $url = sprintf('https://www.googleapis.com/webmasters/v3/sites/%s/searchAnalytics/query', rawurlencode($site));
        $resp = Http::withToken($token)->timeout(60)->post($url, $body);

        if (! $resp->successful()) {
            $this->warn('Search Analytics failed (' . implode(',', $dims) . '): ' . $resp->status() . ' ' . substr($resp->body(), 0, 200));
            return [];
        }

        return $resp->json()['rows'] ?? [];
    }

    /** @param array<int,array<string,mixed>> $rows */
    protected function printAnalytics(array $rows, string $label): void
    {
        if (empty($rows)) { $this->warn('  (no rows)'); return; }
        foreach ($rows as $r) {
            $key = $r['keys'][0] ?? '';
            $clk = (int) ($r['clicks'] ?? 0);
            $imp = (int) ($r['impressions'] ?? 0);
            $ctr = $imp ? round(($clk / $imp) * 100, 1) : 0;
            $pos = round((float) ($r['position'] ?? 0), 1);
            $this->line(sprintf('  pos=%-5s ctr=%-5s clicks=%-5d impr=%-6d  %s', $pos, $ctr . '%', $clk, $imp, $key));
        }
    }

    /**
     * Derive the public https origin from the configured site URL.
     * Handles both `sc-domain:example.com` and `https://example.com/` formats.
     */
    protected function publicBaseUrl(string $site): string
    {
        if (str_starts_with($site, 'sc-domain:')) {
            return 'https://' . substr($site, strlen('sc-domain:'));
        }
        return rtrim($site, '/');
    }

    /** @return array<int,array<string,mixed>> */
    protected function inspectKeyUrls(string $token, string $site): array
    {
        // Inspect the homepage, three service pages, and ten priority city pages.
        $base = $this->publicBaseUrl($site);
        $urls = [
            $base . '/',
            $base . '/services/kitchen-remodeling',
            $base . '/services/bathroom-remodeling',
            $base . '/services/home-remodeling',
            $base . '/services/basement-remodeling',
            $base . '/services/home-additions',
            $base . '/areas-served',
        ];
        AreaServed::query()->orderBy('city')->limit(10)->get()
            ->each(function ($a) use (&$urls, $base) { $urls[] = $base . '/areas-served/' . $a->slug; });

        $out = [];
        $this->line('URL INSPECTION:');
        foreach ($urls as $u) {
            $resp = Http::withToken($token)->timeout(45)->post(
                'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',
                ['inspectionUrl' => $u, 'siteUrl' => $site]
            );
            if (! $resp->successful()) {
                $this->warn('  ' . $u . ' — ' . $resp->status() . ' ' . substr($resp->body(), 0, 100));
                continue;
            }
            $r = $resp->json()['inspectionResult']['indexStatusResult'] ?? [];
            $verdict = $r['verdict'] ?? '?';
            $cov = $r['coverageState'] ?? '?';
            $crawled = $r['lastCrawlTime'] ?? 'never';
            $this->line(sprintf('  %-7s %-32s %s', $verdict, substr($cov, 0, 32), $u . '  [crawled ' . $crawled . ']'));
            $out[] = ['url' => $u, 'verdict' => $verdict, 'coverage' => $cov, 'last_crawled' => $crawled];
            usleep(300_000);
        }
        return $out;
    }
}
