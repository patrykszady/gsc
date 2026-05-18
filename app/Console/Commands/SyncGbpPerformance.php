<?php

namespace App\Console\Commands;

use App\Models\GbpDailyMetric;
use App\Models\GbpSearchKeyword;
use App\Services\GoogleBusinessProfilePerformanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Sync Google Business Profile Performance API metrics.
 * Reuses the existing GBP OAuth token (business.manage scope).
 */
class SyncGbpPerformance extends Command
{
    protected $signature = 'gbp:metrics-sync
        {--days=14 : Days back to sync for daily metrics}
        {--location= : Override location ID (default from config)}
        {--with-keywords : Also sync monthly search keywords}
        {--dry-run}';

    protected $description = 'Sync GBP Performance API daily metrics (and optionally monthly keywords)';

    public function handle(GoogleBusinessProfilePerformanceService $svc): int
    {
        if (! $svc->isConfigured()) {
            $this->error('GBP not configured (missing OAuth or location_id).');
            return self::FAILURE;
        }

        $locationId = (string) ($this->option('location') ?: config('services.google.business_profile.location_id'));
        $days = max(1, (int) $this->option('days'));
        $dry = (bool) $this->option('dry-run');

        // GBP performance data lags ~3 days
        $end = Carbon::today()->subDays(3);
        $start = $end->copy()->subDays($days - 1);

        $this->info("Location: {$locationId}");
        $this->info("Daily range: {$start->toDateString()} → {$end->toDateString()}");

        $series = $svc->fetchDailyMetrics($locationId, $start, $end);
        if ($series === null) {
            $this->error('Daily metric fetch failed.');
            if ($svc->lastError) {
                if (! empty($svc->lastError['status'])) {
                    $this->line("  HTTP {$svc->lastError['status']}");
                }
                if (! empty($svc->lastError['message'])) {
                    $this->line('  ' . $svc->lastError['message']);
                }
                if (! empty($svc->lastError['hint'])) {
                    $this->warn('  → ' . $svc->lastError['hint']);
                }
                if (! empty($svc->lastError['body'])) {
                    $this->line('  body: ' . str_replace("\n", ' ', $svc->lastError['body']));
                }
            } else {
                // Fall back to the underlying OAuth service's lastError so
                // operators still see "reauthorization_required" / etc.
                $gbpErr = app(\App\Services\GoogleBusinessProfileService::class)->lastError ?? null;
                if (is_array($gbpErr)) {
                    foreach (['message', 'error', 'error_description'] as $k) {
                        if (! empty($gbpErr[$k])) {
                            $this->line("  {$k}: {$gbpErr[$k]}");
                        }
                    }
                    if (! empty($gbpErr['reauthorization_required'])) {
                        $this->warn('  → Re-authorize Google Business Profile at /admin/platforms.');
                    }
                }
            }
            return self::FAILURE;
        }

        $upserts = 0;
        foreach ($series as $date => $metrics) {
            foreach ($metrics as $metric => $value) {
                if ($dry) {
                    $this->line("{$date} {$metric}={$value}");
                    continue;
                }
                GbpDailyMetric::updateOrCreate(
                    ['date' => $date, 'location_id' => $locationId, 'metric' => $metric],
                    ['value' => (int) $value],
                );
                $upserts++;
            }
        }
        $this->info("Daily metrics upserted: {$upserts}" . ($dry ? ' (dry-run)' : ''));

        if ($this->option('with-keywords')) {
            $now = Carbon::now();
            // Pull current + previous month
            foreach ([$now->copy()->subMonth(), $now] as $m) {
                $year = (int) $m->format('Y');
                $month = (int) $m->format('n');
                $rows = $svc->fetchSearchKeywords($locationId, $year, $month);
                if ($rows === null) {
                    $this->warn("Keywords fetch failed for {$year}-{$month}");
                    continue;
                }
                $kwUpserts = 0;
                foreach ($rows as $row) {
                    if ($dry) {
                        $this->line("{$year}-{$month} kw={$row['keyword']} imp={$row['impressions']}");
                        continue;
                    }
                    GbpSearchKeyword::updateOrCreate(
                        [
                            'location_id' => $locationId,
                            'keyword' => mb_substr($row['keyword'], 0, 255),
                            'year' => $year,
                            'month' => $month,
                        ],
                        ['impressions' => $row['impressions']],
                    );
                    $kwUpserts++;
                }
                $this->info("Keywords {$year}-{$month}: {$kwUpserts}" . ($dry ? ' (dry-run)' : ''));
            }
        }

        return self::SUCCESS;
    }
}
