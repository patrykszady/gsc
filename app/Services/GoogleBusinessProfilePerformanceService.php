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

    public function isConfigured(): bool
    {
        return $this->gbp->isConfigured();
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
        $token = $this->gbp->getAuthorizedToken();
        if (! $token) {
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

        $resp = Http::withToken($token)->timeout(45)->get($url . '?' . $query);

        if (! $resp->successful()) {
            Log::warning('GBP Performance: daily metrics fetch failed', [
                'status' => $resp->status(),
                'body' => $resp->body(),
            ]);
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
                Log::warning('GBP Performance: keywords fetch failed', [
                    'status' => $resp->status(),
                    'body' => $resp->body(),
                ]);
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
