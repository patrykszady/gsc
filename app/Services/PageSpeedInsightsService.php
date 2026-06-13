<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * PageSpeed Insights API wrapper (free, 25k req/day with API key).
 *
 * https://developers.google.com/speed/docs/insights/v5/get-started
 */
class PageSpeedInsightsService
{
    protected const API = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    /**
     * Run PSI for a URL. Returns null on failure.
     *
     * @return array{
     *   performance:?int, accessibility:?int, best_practices:?int, seo:?int,
     *   lab_lcp_ms:?int, lab_fcp_ms:?int, lab_tbt_ms:?int, lab_cls:?float, lab_si_ms:?int,
     *   field_lcp_ms:?int, field_inp_ms:?int, field_cls:?float, field_overall:?string,
     * }|null
     */
    public function run(string $url, string $strategy = 'mobile'): ?array
    {
        $key = config('services.google.pagespeed.api_key');

        // Build query with repeated category= entries.
        $query = 'url=' . urlencode($url)
            . '&strategy=' . urlencode($strategy)
            . '&category=performance&category=accessibility&category=best-practices&category=seo'
            . ($key ? '&key=' . urlencode($key) : '');

        try {
            $resp = Http::connectTimeout(15)
                ->timeout(75)
                ->retry(3, 2500, function (\Exception $exception) {
                    return $exception instanceof ConnectionException;
                })
                ->get(self::API . '?' . $query);
        } catch (ConnectionException $e) {
            $error = $e->getMessage();
            $isTimeout = str_contains(strtolower($error), 'timed out')
                || str_contains(strtolower($error), 'curl error 28');

            if ($isTimeout) {
                $metricCount = $this->bumpHourlyMetric('psi.connection_timeout');
                Log::warning('PSI: connection timeout', [
                    'url' => $url,
                    'strategy' => $strategy,
                    'error' => $error,
                    'metric' => 'psi.connection_timeout',
                    'metric_count_hour' => $metricCount,
                ]);
                return null;
            }

            Log::warning('PSI: HTTP error', [
                'url' => $url,
                'strategy' => $strategy,
                'error' => $error,
            ]);
            return null;
        } catch (\Exception $e) {
            Log::warning('PSI: HTTP error', [
                'url' => $url,
                'strategy' => $strategy,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        if (! $resp->successful()) {
            Log::warning('PSI: failed', [
                'url' => $url,
                'strategy' => $strategy,
                'status' => $resp->status(),
                'body' => mb_substr($resp->body(), 0, 500),
            ]);
            return null;
        }

        $body = $resp->json();
        $cats = data_get($body, 'lighthouseResult.categories', []);
        $audits = data_get($body, 'lighthouseResult.audits', []);
        $field = data_get($body, 'loadingExperience.metrics', []);

        return [
            'performance' => $this->pct($cats, 'performance'),
            'accessibility' => $this->pct($cats, 'accessibility'),
            'best_practices' => $this->pct($cats, 'best-practices'),
            'seo' => $this->pct($cats, 'seo'),
            'lab_lcp_ms' => $this->ms($audits, 'largest-contentful-paint'),
            'lab_fcp_ms' => $this->ms($audits, 'first-contentful-paint'),
            'lab_tbt_ms' => $this->ms($audits, 'total-blocking-time'),
            'lab_cls' => $this->num($audits, 'cumulative-layout-shift'),
            'lab_si_ms' => $this->ms($audits, 'speed-index'),
            'field_lcp_ms' => data_get($field, 'LARGEST_CONTENTFUL_PAINT_MS.percentile'),
            'field_inp_ms' => data_get($field, 'INTERACTION_TO_NEXT_PAINT.percentile'),
            'field_cls' => (function () use ($field) {
                $v = data_get($field, 'CUMULATIVE_LAYOUT_SHIFT_SCORE.percentile');
                return $v !== null ? round($v / 100, 3) : null;
            })(),
            'field_overall' => data_get($body, 'loadingExperience.overall_category'),
        ];
    }

    protected function pct(array $cats, string $key): ?int
    {
        $score = data_get($cats, "{$key}.score");
        return $score === null ? null : (int) round($score * 100);
    }

    protected function ms(array $audits, string $key): ?int
    {
        $v = data_get($audits, "{$key}.numericValue");
        return $v === null ? null : (int) round($v);
    }

    protected function num(array $audits, string $key): ?float
    {
        $v = data_get($audits, "{$key}.numericValue");
        return $v === null ? null : round((float) $v, 3);
    }

    protected function bumpHourlyMetric(string $metric): int
    {
        $bucket = now()->format('YmdH');
        $key = "metrics:{$metric}:{$bucket}";

        Cache::add($key, 0, now()->addHours(30));
        $value = Cache::increment($key);

        if (is_int($value)) {
            return $value;
        }

        return (int) Cache::get($key, 0);
    }
}
