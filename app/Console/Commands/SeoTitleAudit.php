<?php

namespace App\Console\Commands;

use App\Models\GscQueryMetric;
use App\Models\OAuthToken;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Surfaces "high impressions, low CTR" pages — these are the fastest wins because the page is
 * already ranking; only the title/meta description (or snippet) is failing to earn the click.
 * Can also flag "ranking on page 1 but no clicks at all" cases.
 */
class SeoTitleAudit extends Command
{
    protected $signature = 'seo:title-audit
        {--site= : GSC site URL override}
        {--days=28 : Look-back window}
        {--min-impr=20 : Minimum impressions to consider}
        {--max-ctr=2.0 : Flag pages with CTR (%) below this}
        {--max-pos=20 : Only consider pages ranking better than this average position}
        {--limit=40 : Max rows to show}';

    protected $description = 'List pages that get impressions but few clicks — title/meta rewrite candidates.';

    public function handle(): int
    {
        $site = (string) ($this->option('site') ?: config('seo.search_console.site_url'));
        $days = max(1, (int) $this->option('days'));
        $minImpr = (int) $this->option('min-impr');
        $maxCtr = (float) $this->option('max-ctr');
        $maxPos = (float) $this->option('max-pos');
        $limit = (int) $this->option('limit');

        $token = $this->fetchAccessToken();

        $end = Carbon::now()->subDays(2)->toDateString();
        $start = Carbon::now()->subDays(2 + $days)->toDateString();

        $candidates = [];
        if ($token) {
            $url = sprintf('https://www.googleapis.com/webmasters/v3/sites/%s/searchAnalytics/query', rawurlencode($site));
            $resp = Http::withToken($token)->timeout(60)->post($url, [
                'startDate' => $start,
                'endDate' => $end,
                'dimensions' => ['page'],
                'rowLimit' => 5000,
                'dataState' => 'all',
            ]);

            if (! $resp->successful()) {
                $this->error('GSC fetch failed: ' . $resp->body());
                return self::FAILURE;
            }

            $rows = $resp->json()['rows'] ?? [];
            $candidates = $this->buildCandidatesFromRows($rows, $minImpr, $maxCtr, $maxPos);
        } else {
            $this->warn('Falling back to local gsc_query_metrics cache (reauth with: php artisan seo:gsc-auth).');
            $candidates = $this->buildCandidatesFromStoredMetrics($site, $start, $end, $minImpr, $maxCtr, $maxPos);
        }

        // Sort by greatest opportunity: impressions × (target_ctr - actual_ctr).
        usort($candidates, fn ($a, $b) => ($b['impr'] * (5 - $b['ctr'])) <=> ($a['impr'] * (5 - $a['ctr'])));

        if (empty($candidates)) {
            $this->info('No candidates matched. Loosen --min-impr or --max-ctr.');
            return self::SUCCESS;
        }

        $this->info("Title/meta rewrite candidates (last {$days}d, impr ≥ {$minImpr}, CTR < {$maxCtr}%, pos ≤ {$maxPos}):");
        $this->newLine();
        $rowsOut = [];
        foreach (array_slice($candidates, 0, $limit) as $c) {
            $rowsOut[] = [
                round($c['pos'], 1),
                round($c['ctr'], 2) . '%',
                $c['clk'],
                $c['impr'],
                $this->shortenUrl($c['page']),
            ];
        }
        $this->table(['Pos', 'CTR', 'Clicks', 'Impr', 'Page'], $rowsOut);
        $this->newLine();
        $this->line('Tip: prioritize pages with pos ≤ 10 first — they only need a better snippet.');

        return self::SUCCESS;
    }

    protected function shortenUrl(string $url): string
    {
        return preg_replace('#^https?://[^/]+#', '', $url) ?: $url;
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

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array{page:string,impr:int,clk:int,pos:float,ctr:float}>
     */
    protected function buildCandidatesFromRows(array $rows, int $minImpr, float $maxCtr, float $maxPos): array
    {
        $candidates = [];
        foreach ($rows as $r) {
            $page = (string) ($r['keys'][0] ?? '');
            $impr = (int) ($r['impressions'] ?? 0);
            $clk = (int) ($r['clicks'] ?? 0);
            $pos = (float) ($r['position'] ?? 999);
            $ctr = $impr ? ($clk / $impr) * 100 : 0;
            if ($page !== '' && $impr >= $minImpr && $pos <= $maxPos && $ctr < $maxCtr) {
                $candidates[] = compact('page', 'impr', 'clk', 'pos', 'ctr');
            }
        }

        return $candidates;
    }

    /**
     * @return array<int,array{page:string,impr:int,clk:int,pos:float,ctr:float}>
     */
    protected function buildCandidatesFromStoredMetrics(string $site, string $start, string $end, int $minImpr, float $maxCtr, float $maxPos): array
    {
        $query = GscQueryMetric::query()
            ->selectRaw('page, SUM(impressions) as impr, SUM(clicks) as clk, AVG(position) as pos')
            ->whereBetween('date', [$start, $end])
            ->whereNotNull('page')
            ->where('page', '!=', '')
            ->groupBy('page');

        if ($site !== '') {
            $query->where('site_url', $site);
        }

        $rows = $query->get();

        if ($rows->isEmpty() && $site !== '') {
            $this->warn('No cached rows for configured site_url; retrying across all cached sites.');
            $rows = GscQueryMetric::query()
                ->selectRaw('page, SUM(impressions) as impr, SUM(clicks) as clk, AVG(position) as pos')
                ->whereBetween('date', [$start, $end])
                ->whereNotNull('page')
                ->where('page', '!=', '')
                ->groupBy('page')
                ->get();
        }

        $candidates = [];
        foreach ($rows as $row) {
            $page = (string) $row->page;
            $impr = (int) $row->impr;
            $clk = (int) $row->clk;
            $pos = (float) $row->pos;
            $ctr = $impr ? ($clk / $impr) * 100 : 0;

            if ($impr >= $minImpr && $pos <= $maxPos && $ctr < $maxCtr) {
                $candidates[] = compact('page', 'impr', 'clk', 'pos', 'ctr');
            }
        }

        return $candidates;
    }
}
