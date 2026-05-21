<?php

namespace App\Console\Commands;

use App\Models\ClarityDailyMetric;
use App\Services\MicrosoftClarityService;
use Illuminate\Console\Command;

class SyncMicrosoftClarity extends Command
{
    protected $signature = 'seo:clarity-sync
        {--days=3 : Number of days to sync (Clarity API supports 1-3)}
        {--dry-run : Fetch and print sample rows without writing DB}';

    protected $description = 'Sync Microsoft Clarity daily metrics for SEO/GEO monitoring';

    public function handle(MicrosoftClarityService $svc): int
    {
        if (! $svc->isConfigured()) {
            $this->error('Clarity not configured. Set MICROSOFT_CLARITY_ID and MICROSOFT_CLARITY_API_TOKEN in .env.');
            return self::FAILURE;
        }

        $requestedDays = max(1, (int) $this->option('days'));
        $days = min($requestedDays, \App\Services\MicrosoftClarityService::MAX_DAYS);
        $dry = (bool) $this->option('dry-run');
        $projectId = (string) config('services.microsoft.clarity.project_id');

        if ($requestedDays !== $days) {
            $this->warn("Clarity API supports max " . \App\Services\MicrosoftClarityService::MAX_DAYS . " days; using {$days}.");
        }

        $rows = $svc->fetchDailyMetrics($days);
        if ($rows === null) {
            $this->error('Clarity fetch failed: ' . ($svc->getLastError() ?: 'unknown error'));
            return self::FAILURE;
        }

        $this->info('Fetched ' . count($rows) . ' Clarity rows.');

        if ($dry) {
            foreach (array_slice($rows, 0, 10) as $row) {
                $this->line(json_encode($row, JSON_UNESCAPED_SLASHES));
            }
            return self::SUCCESS;
        }

        $upserts = 0;
        foreach ($rows as $r) {
            ClarityDailyMetric::updateOrCreate(
                [
                    'project_id' => $projectId,
                    'date' => $r['date'],
                ],
                [
                    'sessions' => $r['sessions'],
                    'users' => $r['users'],
                    'pageviews' => $r['pageviews'],
                    'scroll_depth' => $r['scroll_depth'],
                    'active_time_seconds' => $r['active_time_seconds'],
                    'bounce_rate' => $r['bounce_rate'],
                    'dead_clicks' => $r['dead_clicks'],
                    'rage_clicks' => $r['rage_clicks'],
                    'quickbacks' => $r['quickbacks'],
                ]
            );
            $upserts++;
        }

        $this->info("Upserted {$upserts} Clarity metric rows.");
        return self::SUCCESS;
    }
}
