<?php

namespace App\Services\Seo;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads Search Console performance for a specific page URL over a date window.
 * Used to (a) capture a baseline when an action is applied and (b) re-measure
 * after the learning window so the autopilot can tell whether the change worked.
 *
 * GSC data lags ~2 days at the source, so callers should leave a buffer before
 * trusting the "after" number.
 */
class MetricProbe
{
    /** Number of trailing days aggregated for a baseline/measurement sample. */
    public const WINDOW_DAYS = 28;

    /** Days to wait after apply before the "after" sample is meaningful. */
    public const MEASURE_AFTER_DAYS = 21;

    /**
     * Aggregate clicks/impressions/ctr/position for one page URL over the
     * [end-($days-1) .. end] window. `end` defaults to today.
     *
     * @return array{clicks:float,impressions:float,ctr:float,position:float,days:int}
     */
    public function forPage(string $pageUrl, int $days = self::WINDOW_DAYS, ?Carbon $end = null): array
    {
        $blank = ['clicks' => 0.0, 'impressions' => 0.0, 'ctr' => 0.0, 'position' => 0.0, 'days' => $days];

        if (! Schema::hasTable('gsc_query_metrics') || $pageUrl === '') {
            return $blank;
        }

        $end = ($end ?: Carbon::today())->copy()->startOfDay();
        $start = $end->copy()->subDays(max(1, $days) - 1);

        // Match on the exact page plus its trailing-slash variant so we don't
        // miss rows from canonical/normalization differences.
        $variants = array_unique([$pageUrl, rtrim($pageUrl, '/'), rtrim($pageUrl, '/') . '/']);

        $row = DB::table('gsc_query_metrics')
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->whereIn('page', $variants)
            ->selectRaw('SUM(clicks) c, SUM(impressions) i, AVG(position) p')
            ->first();

        $clicks = (float) ($row->c ?? 0);
        $impr = (float) ($row->i ?? 0);

        return [
            'clicks' => $clicks,
            'impressions' => $impr,
            'ctr' => $impr > 0 ? round($clicks / $impr * 100, 3) : 0.0,
            'position' => round((float) ($row->p ?? 0), 2),
            'days' => $days,
        ];
    }

    /**
     * Pick the single scalar the outcome is judged on for a given metric name.
     * Position is "lower is better"; everything else is "higher is better".
     */
    public function scalar(array $sample, string $metric): float
    {
        return (float) ($sample[$metric] ?? 0.0);
    }

    /** True when the metric improves as the number goes DOWN (position only). */
    public function lowerIsBetter(string $metric): bool
    {
        return $metric === 'position';
    }
}
