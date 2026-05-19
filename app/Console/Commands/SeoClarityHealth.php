<?php

namespace App\Console\Commands;

use App\Models\ClarityDailyMetric;
use App\Services\MicrosoftClarityService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class SeoClarityHealth extends Command
{
    protected $signature = 'seo:clarity-health
        {--markdown : Save markdown report to storage/app/reports/clarity-health.md}';

    protected $description = 'Health check for Microsoft Clarity integration and latest metric freshness';

    public function handle(MicrosoftClarityService $svc): int
    {
        $isConfigured = $svc->isConfigured();
        $projectId = (string) config('services.microsoft.clarity.project_id');

        $apiReachable = false;
        $apiError = null;
        if ($isConfigured) {
            $rows = $svc->fetchDailyMetrics(MicrosoftClarityService::MAX_DAYS);
            if ($rows !== null) {
                $apiReachable = true;
            } else {
                $apiError = $svc->getLastError();
            }
        }

        $latest = ClarityDailyMetric::query()
            ->where('project_id', $projectId)
            ->orderByDesc('date')
            ->first();

        $rowsCount = ClarityDailyMetric::query()
            ->where('project_id', $projectId)
            ->count();

        $latestDate = $latest?->date?->toDateString();
        $latestAge = $latest?->date?->diffForHumans();

        $this->newLine();
        $this->info('=== Clarity Health ===');
        $this->line('Configured: ' . ($isConfigured ? 'yes' : 'no'));
        $this->line('API reachable: ' . ($apiReachable ? 'yes' : 'no'));
        $this->line('Project ID: ' . ($projectId !== '' ? $projectId : '(empty)'));
        $this->line('Stored rows: ' . $rowsCount);
        $this->line('Latest date: ' . ($latestDate ?? '(none)'));
        $this->line('Latest age: ' . ($latestAge ?? '(none)'));

        if ($latest) {
            $this->line('Latest metrics: ' . json_encode([
                'sessions' => $latest->sessions,
                'users' => $latest->users,
                'pageviews' => $latest->pageviews,
                'scroll_depth' => $latest->scroll_depth,
                'active_time_seconds' => $latest->active_time_seconds,
                'bounce_rate' => $latest->bounce_rate,
                'dead_clicks' => $latest->dead_clicks,
                'rage_clicks' => $latest->rage_clicks,
                'quickbacks' => $latest->quickbacks,
            ], JSON_UNESCAPED_SLASHES));
        }

        if (! $apiReachable && $apiError) {
            $this->warn('API error: ' . $apiError);
        }

        if ((bool) $this->option('markdown')) {
            $md = $this->buildMarkdown(
                isConfigured: $isConfigured,
                apiReachable: $apiReachable,
                projectId: $projectId,
                rowsCount: $rowsCount,
                latest: $latest,
                apiError: $apiError,
            );

            Storage::disk('local')->put('reports/clarity-health.md', $md);
            $this->info('Saved: storage/app/private/reports/clarity-health.md');
        }

        if (! $isConfigured || ! $apiReachable) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function buildMarkdown(
        bool $isConfigured,
        bool $apiReachable,
        string $projectId,
        int $rowsCount,
        ?ClarityDailyMetric $latest,
        ?string $apiError,
    ): string {
        $lines = [];
        $lines[] = '# Clarity health';
        $lines[] = '';
        $lines[] = 'Generated: ' . now()->toIso8601String();
        $lines[] = '';
        $lines[] = '## Status';
        $lines[] = '';
        $lines[] = '- Configured: ' . ($isConfigured ? 'yes' : 'no');
        $lines[] = '- API reachable: ' . ($apiReachable ? 'yes' : 'no');
        $lines[] = '- Project ID: ' . ($projectId !== '' ? $projectId : '(empty)');
        $lines[] = '- Stored rows: ' . $rowsCount;
        $lines[] = '- Latest row date: ' . ($latest?->date?->toDateString() ?? '(none)');
        $lines[] = '- Latest row age: ' . ($latest?->date?->diffForHumans() ?? '(none)');

        if ($latest) {
            $lines[] = '';
            $lines[] = '## Latest metrics';
            $lines[] = '';
            $lines[] = '| Metric | Value |';
            $lines[] = '|---|---:|';
            $lines[] = '| sessions | ' . (int) $latest->sessions . ' |';
            $lines[] = '| users | ' . (int) $latest->users . ' |';
            $lines[] = '| pageviews | ' . (int) $latest->pageviews . ' |';
            $lines[] = '| scroll depth | ' . (float) $latest->scroll_depth . ' |';
            $lines[] = '| active time seconds | ' . (int) $latest->active_time_seconds . ' |';
            $lines[] = '| bounce rate | ' . (float) $latest->bounce_rate . ' |';
            $lines[] = '| dead clicks | ' . (int) $latest->dead_clicks . ' |';
            $lines[] = '| rage clicks | ' . (int) $latest->rage_clicks . ' |';
            $lines[] = '| quickbacks | ' . (int) $latest->quickbacks . ' |';
        }

        if (! $apiReachable && $apiError) {
            $lines[] = '';
            $lines[] = '## API error';
            $lines[] = '';
            $lines[] = '- ' . $apiError;
        }

        return implode("\n", $lines) . "\n";
    }
}
