<?php

namespace App\Console\Commands;

use App\Models\GscQueryMetric;
use App\Services\GoogleSearchConsoleService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Sync Google Search Console search-analytics data.
 *
 * Defaults to the last 7 days. GSC data lags ~2 days, so we shift the
 * window back by --lag-days (default 2). Upserts rows so we can re-sync
 * idempotently to capture late-arriving data.
 */
class SyncGoogleSearchConsole extends Command
{
    protected $signature = 'seo:gsc-sync
        {--days=7 : Number of days back to sync}
        {--lag-days=2 : Skip the most recent N days (GSC data lags)}
        {--site= : Override site URL (default from config)}
        {--limit=25000 : Max rows per page}
        {--queue : Deprecated no-op alias for compatibility}
        {--dry-run}';

    protected $description = 'Sync Google Search Console query/page/country/device metrics';

    public function handle(GoogleSearchConsoleService $svc): int
    {
        if ((bool) $this->option('queue')) {
            $cmdLine = implode(' ', $_SERVER['argv'] ?? []);
            logger('seo-sync')->warning('Deprecated --queue option used for seo:gsc-sync', [
                'command_line' => $cmdLine,
                'calling_user' => get_current_user(),
                'pid' => getmypid(),
                'timestamp' => now()->toIso8601String(),
            ]);
            $this->warn('Option --queue is deprecated and ignored for seo:gsc-sync.');
            $this->line("Command line: {$cmdLine}");
        }

        if (! $svc->isConfigured()) {
            $this->error('Search Console not configured. Run: php artisan seo:gsc-auth');
            return self::FAILURE;
        }

        $siteUrl = (string) ($this->option('site') ?: config('services.google.search_console.site_url'));
        $days = max(1, (int) $this->option('days'));
        $lag = max(0, (int) $this->option('lag-days'));
        $limit = (int) $this->option('limit');
        $dry = (bool) $this->option('dry-run');

        $end = Carbon::today()->subDays($lag);
        $start = $end->copy()->subDays($days - 1);

        $this->info("Site: {$siteUrl}");
        $this->info("Range: {$start->toDateString()} → {$end->toDateString()}");

        $startRow = 0;
        $totalInserted = 0;
        $totalUpdated = 0;

        do {
            $rows = $svc->querySearchAnalytics(
                siteUrl: $siteUrl,
                startDate: $start->toDateString(),
                endDate: $end->toDateString(),
                dimensions: ['date', 'query', 'page', 'country', 'device'],
                rowLimit: $limit,
                startRow: $startRow,
            );

            if ($rows === null) {
                $this->error('Query failed: ' . json_encode($svc->getLastError()));
                return self::FAILURE;
            }

            $count = count($rows);
            $this->line("Fetched {$count} rows (startRow={$startRow})");

            foreach ($rows as $r) {
                $keys = $r['keys'] ?? [];
                [$date, $query, $page, $country, $device] = array_pad($keys, 5, null);

                if (! $date || ! $query || ! $page) {
                    continue;
                }

                $payload = [
                    'date' => $date,
                    'site_url' => mb_substr($siteUrl, 0, 191),
                    'query' => mb_substr((string) $query, 0, 500),
                    'page' => mb_substr((string) $page, 0, 500),
                    'country' => mb_substr((string) ($country ?? ''), 0, 8) ?: null,
                    'device' => mb_substr((string) ($device ?? ''), 0, 16) ?: null,
                    'impressions' => (int) ($r['impressions'] ?? 0),
                    'clicks' => (int) ($r['clicks'] ?? 0),
                    'ctr' => round((float) ($r['ctr'] ?? 0), 4),
                    'position' => round((float) ($r['position'] ?? 0), 2),
                ];
                // Deterministic SHA1 of the natural unique key — avoids the
                // MySQL 3072-byte unique-index limit on long query/page values.
                $payload['dim_hash'] = sha1(implode('|', [
                    $payload['date'],
                    $payload['site_url'],
                    $payload['query'],
                    $payload['page'],
                    $payload['country'] ?? '',
                    $payload['device'] ?? '',
                ]));

                if ($dry) {
                    continue;
                }

                $row = GscQueryMetric::updateOrCreate(
                    ['dim_hash' => $payload['dim_hash']],
                    $payload
                );

                $row->wasRecentlyCreated ? $totalInserted++ : $totalUpdated++;
            }

            $startRow += $count;
            // GSC returns up to `limit` rows; loop until fewer come back.
        } while (isset($count) && $count >= $limit);

        $this->info("Done. Inserted={$totalInserted} Updated={$totalUpdated}" . ($dry ? ' (dry-run)' : ''));

        // Also capture true site-wide daily totals. The query-dimension pull
        // above silently drops clicks/impressions from anonymized queries, so
        // its per-day sums under-report vs the GSC UI. A date-only query
        // returns the real totals, which /admin/seo-reports uses for headline
        // numbers and the daily trend.
        $this->syncDailyTotals($svc, $siteUrl, $start, $end, $dry);

        return self::SUCCESS;
    }

    /**
     * Upsert true daily totals from a date-dimension-only query.
     */
    protected function syncDailyTotals(
        GoogleSearchConsoleService $svc,
        string $siteUrl,
        Carbon $start,
        Carbon $end,
        bool $dry,
    ): void {
        $rows = $svc->querySearchAnalytics(
            siteUrl: $siteUrl,
            startDate: $start->toDateString(),
            endDate: $end->toDateString(),
            dimensions: ['date'],
            rowLimit: 1000,
            startRow: 0,
        );

        if ($rows === null) {
            $this->warn('Daily-totals query failed: ' . json_encode($svc->getLastError()));
            return;
        }

        $written = 0;
        foreach ($rows as $r) {
            $date = $r['keys'][0] ?? null;
            if (! $date) {
                continue;
            }

            if ($dry) {
                $written++;
                continue;
            }

            \App\Models\GscDailyTotal::updateOrCreate(
                ['date' => $date, 'site_url' => mb_substr($siteUrl, 0, 191)],
                [
                    'clicks' => (int) ($r['clicks'] ?? 0),
                    'impressions' => (int) ($r['impressions'] ?? 0),
                    'ctr' => round((float) ($r['ctr'] ?? 0), 5),
                    'position' => round((float) ($r['position'] ?? 0), 2),
                ]
            );
            $written++;
        }

        $this->info("Daily totals upserted: {$written} day(s)" . ($dry ? ' (dry-run)' : ''));
    }
}
