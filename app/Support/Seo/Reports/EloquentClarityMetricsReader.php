<?php

namespace App\Support\Seo\Reports;

use App\Models\ClarityDailyMetric;
use App\Models\ClientError;
use App\Services\MicrosoftClarityService;
use App\Support\Seo\ClaritySettings;
use SsSystems\Platform\Reports\Contracts\ClarityMetricsReader;

/**
 * Everything ClarityHealthReport reads — the kit's `clarity_metrics`
 * capability — wrapping the site's existing MicrosoftClarityService (the
 * live Data Export API call) and ClaritySettings (is it configured, the
 * project id), plus the stored clarity_daily_metrics rows and the on-site
 * ClientError beacon, exactly like the original SeoClarityHealth read them.
 */
final class EloquentClarityMetricsReader implements ClarityMetricsReader
{
    public function __construct(
        private readonly MicrosoftClarityService $service,
        private readonly ClaritySettings $settings,
    ) {}

    public function isConfigured(): bool
    {
        return $this->service->isConfigured();
    }

    public function projectId(): ?string
    {
        return $this->settings->projectId();
    }

    public function fetchLiveSnapshot(): ?array
    {
        $rows = $this->service->fetchDailyMetrics(MicrosoftClarityService::MAX_DAYS);

        return $rows !== null ? ($rows[0] ?? null) : null;
    }

    public function lastError(): ?string
    {
        return $this->service->getLastError();
    }

    public function latestStoredMetric(): ?array
    {
        $metric = ClarityDailyMetric::query()
            ->where('project_id', (string) $this->settings->projectId())
            ->orderByDesc('date')
            ->first();

        return $metric ? $this->toArray($metric) : null;
    }

    public function storedRowCount(): int
    {
        return ClarityDailyMetric::query()
            ->where('project_id', (string) $this->settings->projectId())
            ->count();
    }

    public function baselineMetrics(string $beforeDate, int $limit): array
    {
        return ClarityDailyMetric::query()
            ->where('project_id', (string) $this->settings->projectId())
            ->where('date', '<', $beforeDate)
            ->orderByDesc('date')
            ->limit($limit)
            ->get(['sessions', 'script_errors'])
            ->map(fn (ClarityDailyMetric $row): array => [
                'sessions' => (int) $row->sessions,
                'script_errors' => (int) $row->script_errors,
            ])
            ->all();
    }

    public function beaconErrorCount(\DateTimeInterface $since): int
    {
        return ClientError::query()->where('last_seen_at', '>=', $since)->count();
    }

    /**
     * @return array{date: string, sessions: int, users: int, pageviews: int, scroll_depth: float, active_time_seconds: int, bounce_rate: float, dead_clicks: int, rage_clicks: int, quickbacks: int, script_errors: int, error_clicks: int}
     */
    private function toArray(ClarityDailyMetric $row): array
    {
        return [
            'date' => (string) $row->date?->toDateString(),
            'sessions' => (int) $row->sessions,
            'users' => (int) $row->users,
            'pageviews' => (int) $row->pageviews,
            'scroll_depth' => (float) $row->scroll_depth,
            'active_time_seconds' => (int) $row->active_time_seconds,
            'bounce_rate' => (float) $row->bounce_rate,
            'dead_clicks' => (int) $row->dead_clicks,
            'rage_clicks' => (int) $row->rage_clicks,
            'quickbacks' => (int) $row->quickbacks,
            'script_errors' => (int) $row->script_errors,
            'error_clicks' => (int) $row->error_clicks,
        ];
    }
}
