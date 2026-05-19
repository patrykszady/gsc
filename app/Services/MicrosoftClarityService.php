<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MicrosoftClarityService
{
    protected ?string $lastError = null;

    /**
     * Clarity Data Export API supports only the last 1-3 days.
     */
    public const MAX_DAYS = 3;

    public function isConfigured(): bool
    {
        return filled(config('services.microsoft.clarity.project_id'))
            && filled(config('services.microsoft.clarity.api_token'));
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Fetch Clarity dashboard export metrics.
     *
     * Returns a single normalized snapshot row:
     * ['date' => 'YYYY-MM-DD', 'sessions' => int, ...]
     */
    public function fetchDailyMetrics(int $days = 28): ?array
    {
        $token = (string) config('services.microsoft.clarity.api_token');
        $baseUrl = rtrim((string) config('services.microsoft.clarity.base_url', 'https://www.clarity.ms/export-data/api/v1'), '/');

        if ($token === '') {
            $this->lastError = 'Clarity API token missing';
            return null;
        }

        $url = "{$baseUrl}/project-live-insights";

        // Clarity API only accepts 1..3 days.
        $days = max(1, min(self::MAX_DAYS, $days));

        // Bearer auth is required by Clarity Data Export API docs.
        $attempts = [
            [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
        ];

        $query = [
            'numOfDays' => $days,
            // A stable dimension keeps response structure deterministic.
            'dimension1' => 'OS',
        ];

        $response = null;
        foreach ($attempts as $headers) {
            $resp = Http::timeout(45)
                ->acceptJson()
                ->withHeaders($headers)
                ->get($url, $query);

            if ($resp->successful()) {
                $response = $resp;
                break;
            }

            Log::warning('Clarity API call failed', [
                'status' => $resp->status(),
                'body' => mb_substr($resp->body(), 0, 500),
                'auth_header' => array_key_first($headers),
            ]);
        }

        if (! $response || ! $response->successful()) {
            $this->lastError = 'Clarity API request failed (check token/project id and API permissions).';
            return null;
        }

        $payload = $response->json();

        // Clarity payload shapes can vary; normalize robustly.
        if (! is_array($payload)) {
            $this->lastError = 'Unexpected Clarity payload shape.';
            return null;
        }

        // API returns an array of metric groups:
        // [ { metricName: "Traffic", information: [...] }, ... ]
        $summary = [
            'date' => now()->toDateString(),
            'sessions' => 0,
            'users' => 0,
            'pageviews' => 0,
            'scroll_depth' => 0.0,
            'active_time_seconds' => 0,
            'bounce_rate' => 0.0,
            'dead_clicks' => 0,
            'rage_clicks' => 0,
            'quickbacks' => 0,
        ];

        foreach ($payload as $metricGroup) {
            if (! is_array($metricGroup)) {
                continue;
            }

            $metricName = (string) ($metricGroup['metricName'] ?? '');
            $infoRows = $metricGroup['information'] ?? [];
            if (! is_array($infoRows)) {
                continue;
            }

            foreach ($infoRows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                // Traffic group commonly carries session/user/pageview totals.
                if (strcasecmp($metricName, 'Traffic') === 0 || strcasecmp($metricName, 'Popular Pages') === 0) {
                    $summary['sessions'] += $this->toInt($row, ['totalSessionCount', 'sessionCount', 'sessions']);
                    $summary['users'] += $this->toInt($row, ['distantUserCount', 'uniqueUsers', 'users']);
                    $summary['pageviews'] += $this->toInt($row, ['pageViews', 'totalPageViews', 'views']);
                    $summary['scroll_depth'] = max($summary['scroll_depth'], $this->toFloat($row, ['ScrollDepth', 'scrollDepth']));
                    $summary['active_time_seconds'] = max($summary['active_time_seconds'], $this->toInt($row, ['engagementTime', 'activeTimeSeconds', 'activeTime']));
                    $summary['bounce_rate'] = max($summary['bounce_rate'], $this->toFloat($row, ['bounceRate', 'BounceRate']));
                }

                // Interaction quality metrics may come under dedicated metric groups.
                $summary['dead_clicks'] += $this->toInt($row, ['deadClickCount', 'deadClicks']);
                $summary['rage_clicks'] += $this->toInt($row, ['rageClickCount', 'rageClicks']);
                $summary['quickbacks'] += $this->toInt($row, ['quickbackClick', 'quickbacks']);
            }
        }

        return [$summary];
    }

    protected function toInt(array $row, array $keys): int
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $row) && is_numeric($row[$k])) {
                return (int) $row[$k];
            }
        }

        return 0;
    }

    protected function toFloat(array $row, array $keys): float
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $row) && is_numeric($row[$k])) {
                return round((float) $row[$k], 4);
            }
        }

        return 0.0;
    }
}
