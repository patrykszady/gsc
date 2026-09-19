<?php

namespace App\Console\Commands;

use App\Models\ClarityDailyMetric;
use App\Models\ClarityPageMetric;
use App\Services\MicrosoftClarityService;
use Illuminate\Console\Command;

class SyncMicrosoftClarity extends Command
{
    protected $signature = 'seo:clarity-sync
        {--days=3 : Number of days to sync (Clarity API supports 1-3)}
        {--no-pages : Skip the per-page breakdown (saves one of the 10 daily API requests)}
        {--dry-run : Fetch and print sample rows without writing DB}';

    protected $description = 'Sync Microsoft Clarity daily metrics for SEO/GEO monitoring';

    public function handle(MicrosoftClarityService $svc): int
    {
        if (! $svc->isConfigured()) {
            $this->error('Clarity not configured. Set MICROSOFT_CLARITY_ID and MICROSOFT_CLARITY_API_TOKEN in .env.');

            return self::FAILURE;
        }

        $requestedDays = max(1, (int) $this->option('days'));
        $days = min($requestedDays, MicrosoftClarityService::MAX_DAYS);
        $dry = (bool) $this->option('dry-run');
        $projectId = (string) config('services.microsoft.clarity.project_id');

        if ($requestedDays !== $days) {
            $this->warn('Clarity API supports max '.MicrosoftClarityService::MAX_DAYS." days; using {$days}.");
        }

        $rows = $svc->fetchDailyMetrics($days);
        if ($rows === null) {
            $this->error('Clarity fetch failed: '.($svc->getLastError() ?: 'unknown error'));

            return self::FAILURE;
        }

        $this->info('Fetched '.count($rows).' Clarity rows.');

        if ($dry) {
            foreach (array_slice($rows, 0, 10) as $row) {
                $this->line(json_encode($row, JSON_UNESCAPED_SLASHES));
            }
            if (! $this->option('no-pages')) {
                $this->syncPages($svc, $projectId, dry: true);
            }

            return self::SUCCESS;
        }

        $upserts = 0;
        foreach ($rows as $r) {
            // whereDate(), not updateOrCreate(['date' => …]): Eloquent's `date`
            // cast writes 'Y-m-d H:i:s' and only MySQL's DATE column truncates
            // it back, so an exact-string match finds the row in production
            // and misses it everywhere else — then inserts into the unique
            // index. Same fix as SyncGoogleSearchConsole::syncDailyTotals().
            $existing = ClarityDailyMetric::whereDate('date', $r['date'])->where('project_id', $projectId)->first();
            $attrs = [
                'sessions' => $r['sessions'],
                'users' => $r['users'],
                'pageviews' => $r['pageviews'],
                'scroll_depth' => $r['scroll_depth'],
                'active_time_seconds' => $r['active_time_seconds'],
                'bounce_rate' => $r['bounce_rate'],
                'dead_clicks' => $r['dead_clicks'],
                'rage_clicks' => $r['rage_clicks'],
                'quickbacks' => $r['quickbacks'],
                'script_errors' => $r['script_errors'] ?? 0,
                'error_clicks' => $r['error_clicks'] ?? 0,
            ];
            $existing
                ? $existing->update($attrs)
                : ClarityDailyMetric::create($attrs + ['project_id' => $projectId, 'date' => $r['date']]);
            $upserts++;
        }

        $this->info("Upserted {$upserts} Clarity metric rows.");

        if (! $this->option('no-pages')) {
            $this->syncPages($svc, $projectId, dry: false);
        }

        return self::SUCCESS;
    }

    /**
     * The per-page breakdown: one extra request, one row per path for the
     * last 24 hours. A failure here is reported but does not fail the run —
     * the site-wide rows above are already written, and the freshness of
     * clarity_page_metrics is its own signal.
     */
    protected function syncPages(MicrosoftClarityService $svc, string $projectId, bool $dry): void
    {
        $pages = $svc->fetchPageMetrics();
        if ($pages === null) {
            $this->warn('Per-page fetch failed: '.($svc->getLastError() ?: 'unknown error').' — site-wide rows were still written.');

            return;
        }

        $this->info('Fetched '.count($pages).' per-page rows.');

        if ($dry) {
            foreach (array_slice($pages, 0, 10) as $row) {
                $this->line(json_encode($row, JSON_UNESCAPED_SLASHES));
            }

            return;
        }

        foreach ($pages as $r) {
            $hash = ClarityPageMetric::hashPath($r['path']);
            $attrs = [
                'path' => $r['path'],
                'sessions' => $r['sessions'],
                'rage_clicks' => $r['rage_clicks'],
                'dead_clicks' => $r['dead_clicks'],
                'quickbacks' => $r['quickbacks'],
                'script_errors' => $r['script_errors'],
                'scroll_depth' => $r['scroll_depth'],
            ];

            $existing = ClarityPageMetric::whereDate('date', $r['date'])
                ->where('project_id', $projectId)
                ->where('path_hash', $hash)
                ->first();

            $existing
                ? $existing->update($attrs)
                : ClarityPageMetric::create($attrs + ['project_id' => $projectId, 'date' => $r['date'], 'path_hash' => $hash]);
        }

        $this->info('Upserted '.count($pages).' per-page rows.');
    }
}
