<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Business Profile Performance API (free, official).
 *
 * Reuses the OAuth token from GoogleBusinessProfileService (same
 * business.manage scope works for both endpoints).
 *
 * Daily metrics docs:
 *   https://developers.google.com/my-business/reference/performance/rest/v1/locations/fetchMultiDailyMetricsTimeSeries
 *
 * Search keywords docs:
 *   https://developers.google.com/my-business/reference/performance/rest/v1/locations.searchkeywords.impressions.monthly/list
 */
class GoogleBusinessProfilePerformanceService
{
    protected const API_BASE = 'https://businessprofileperformance.googleapis.com/v1';

    public const DAILY_METRICS = [
        'BUSINESS_IMPRESSIONS_DESKTOP_MAPS',
        'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
        'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
        'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
        'BUSINESS_CONVERSATIONS',
        'BUSINESS_DIRECTION_REQUESTS',
        'CALL_CLICKS',
        'WEBSITE_CLICKS',
        'BUSINESS_BOOKINGS',
        'BUSINESS_FOOD_ORDERS',
        'BUSINESS_FOOD_MENU_CLICKS',
    ];

    public function __construct(protected GoogleBusinessProfileService $gbp) {}

    /**
     * Last error from a failed API call — surfaces actionable detail
     * (HTTP status, body excerpt, remediation hint) to console callers.
     *
     * @var array{status?:int, body?:string, message?:string, hint?:string}|null
     */
    public ?array $lastError = null;

    public function isConfigured(): bool
    {
        return $this->gbp->isConfigured();
    }

    /**
     * Evict the cached access token so the next getAuthorizedToken() call
     * is forced through the refresh path. Used to recover from stale 401s.
     */
    protected function forceTokenRefresh(): void
    {
        \Illuminate\Support\Facades\Cache::forget('google_business_profile_access_token');
    }

    /**
     * Capture an error response in $lastError with a remediation hint.
     */
    protected function captureError(\Illuminate\Http\Client\Response $resp, string $context): void
    {
        $status = $resp->status();
        $body = mb_substr((string) $resp->body(), 0, 600);
        $hint = match (true) {
            $status === 401 => 'OAuth token rejected. Re-authorize Google Business Profile at /admin/platforms (refresh token may be revoked).',
            $status === 403 => 'Permission denied. Verify the OAuth account has Owner/Manager access to this GBP location.',
            $status === 404 => 'Location not found. Verify GOOGLE_BUSINESS_PROFILE_LOCATION_ID matches a location the OAuth account owns.',
            $status === 429 => 'Rate limit exceeded. Wait and retry.',
            default => null,
        };
        $this->lastError = array_filter([
            'status' => $status,
            'body' => $body,
            'message' => $context,
            'hint' => $hint,
        ]);
    }

    /**
     * Detect Google's "API not enabled in this GCP project" 403 response so
     * scheduled callers can treat it as "nothing to sync" instead of a hard
     * failure (which spams the scheduler error channel every run).
     */
    protected function isServiceDisabled(\Illuminate\Http\Client\Response $resp): bool
    {
        if ($resp->status() !== 403) {
            return false;
        }
        $reason = (string) ($resp->json('error.details.0.reason') ?? '');
        $status = (string) ($resp->json('error.status') ?? '');
        return $reason === 'SERVICE_DISABLED' || $status === 'PERMISSION_DENIED';
    }

    /**
     * Returns array<string,array<string,int>>: [date_iso => [metric => value]].
     */
    public function fetchDailyMetrics(
        string $locationId,
        \DateTimeInterface $start,
        \DateTimeInterface $end,
        array $metrics = self::DAILY_METRICS,
    ): ?array {
        $this->lastError = null;
        $token = $this->gbp->getAuthorizedToken();
        if (! $token) {
            $this->lastError = ['message' => 'No OAuth token available. Authorize at /admin/platforms.'];
            return null;
        }

        $url = self::API_BASE . "/locations/{$locationId}:fetchMultiDailyMetricsTimeSeries";
        $params = [
            'dailyMetrics' => $metrics,
            'dailyRange.start_date.year' => (int) $start->format('Y'),
            'dailyRange.start_date.month' => (int) $start->format('n'),
            'dailyRange.start_date.day' => (int) $start->format('j'),
            'dailyRange.end_date.year' => (int) $end->format('Y'),
            'dailyRange.end_date.month' => (int) $end->format('n'),
            'dailyRange.end_date.day' => (int) $end->format('j'),
        ];

        // Build query string with repeated dailyMetrics= entries.
        $query = collect($metrics)->map(fn ($m) => 'dailyMetrics=' . urlencode($m))->implode('&');
        $query .= '&' . http_build_query(array_filter($params, fn ($k) => $k !== 'dailyMetrics', ARRAY_FILTER_USE_KEY));

        $fullUrl = $url . '?' . $query;
        $resp = Http::withToken($token)->timeout(45)->get($fullUrl);

        // Cached access token may be stale; force-refresh + retry once on 401.
        if ($resp->status() === 401) {
            $this->forceTokenRefresh();
            $token = $this->gbp->getAuthorizedToken();
            if ($token) {
                $resp = Http::withToken($token)->timeout(45)->get($fullUrl);
            }
        }

        if (! $resp->successful()) {
            if ($this->isServiceDisabled($resp)) {
                Log::warning('GBP Performance: API disabled in GCP project, skipping daily metrics sync', [
                    'activation_url' => (string) ($resp->json('error.details.0.metadata.activationUrl') ?? ''),
                ]);
                return [];
            }
            Log::warning('GBP Performance: daily metrics fetch failed', [
                'status' => $resp->status(),
                'body' => $resp->body(),
            ]);
            $this->captureError($resp, 'Daily metrics fetch failed');
            return null;
        }

        $series = $resp->json('multiDailyMetricTimeSeries.0.dailyMetricTimeSeries', []);
        $out = [];
        foreach ($series as $entry) {
            $metric = $entry['dailyMetric'] ?? null;
            if (! $metric) {
                continue;
            }
            foreach (data_get($entry, 'timeSeries.datedValues', []) as $row) {
                $y = data_get($row, 'date.year');
                $m = data_get($row, 'date.month');
                $d = data_get($row, 'date.day');
                if (! $y || ! $m || ! $d) {
                    continue;
                }
                $iso = sprintf('%04d-%02d-%02d', $y, $m, $d);
                $out[$iso][$metric] = (int) ($row['value'] ?? 0);
            }
        }

        return $out;
    }

    /**
     * Monthly search-keyword impressions.
     *
     * @return array<int,array{keyword:string,impressions:int,year:int,month:int}>|null
     */
    public function fetchSearchKeywords(string $locationId, int $year, int $month): ?array
    {
        $token = $this->gbp->getAuthorizedToken();
        if (! $token) {
            return null;
        }

        $url = self::API_BASE . "/locations/{$locationId}/searchkeywords/impressions/monthly";
        $params = http_build_query([
            'monthlyRange.start_month.year' => $year,
            'monthlyRange.start_month.month' => $month,
            'monthlyRange.end_month.year' => $year,
            'monthlyRange.end_month.month' => $month,
            'pageSize' => 100,
        ]);

        $rows = [];
        $pageToken = null;
        do {
            $u = $url . '?' . $params . ($pageToken ? '&pageToken=' . urlencode($pageToken) : '');
            $resp = Http::withToken($token)->timeout(45)->get($u);
            if (! $resp->successful()) {
                if ($this->isServiceDisabled($resp)) {
                    Log::warning('GBP Performance: API disabled in GCP project, skipping keywords sync', [
                        'activation_url' => (string) ($resp->json('error.details.0.metadata.activationUrl') ?? ''),
                    ]);
                    return [];
                }
                Log::warning('GBP Performance: keywords fetch failed', [
                    'status' => $resp->status(),
                    'body' => $resp->body(),
                ]);
                $this->captureError($resp, 'Keywords fetch failed');
                return null;
            }
            foreach ($resp->json('searchKeywordsCounts', []) as $kw) {
                $rows[] = [
                    'keyword' => (string) ($kw['searchKeyword'] ?? ''),
                    'impressions' => (int) ($kw['insightsValue']['value']
                        ?? $kw['insightsValue']['threshold']
                        ?? 0),
                    'year' => $year,
                    'month' => $month,
                ];
            }
            $pageToken = $resp->json('nextPageToken');
        } while ($pageToken);

        return $rows;
    }
}
