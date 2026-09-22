<?php

namespace App\Support\Seo\Reports;

use App\Models\GscQueryMetric;
use SsSystems\Platform\Reports\Contracts\QueryMetricsReader;

/**
 * Wraps gsc_query_metrics for the kit's `query_metrics` capability —
 * ContentDecayReport (the reference port), ContentGapReport, and
 * HealthReport's local-rankings pillar. GscQueryMetric uses BelongsToSite,
 * so a plain Eloquent query is already tenant-scoped, exactly like the
 * original SeoContentDecay/SeoContentGap commands' own queries.
 */
final class EloquentQueryMetricsReader implements QueryMetricsReader
{
    public function pageMetrics(string $from, string $to): array
    {
        return GscQueryMetric::query()
            ->whereBetween('date', [$from, $to])
            ->selectRaw('page, SUM(impressions) as impr, SUM(clicks) as clicks, SUM(impressions * position) as weighted_pos')
            ->groupBy('page')
            ->get()
            ->map(function ($row): array {
                $impressions = (int) $row->impr;

                return [
                    'page' => (string) $row->page,
                    'impressions' => $impressions,
                    'clicks' => (int) $row->clicks,
                    'position' => $impressions > 0 ? round(((float) $row->weighted_pos) / $impressions, 2) : null,
                ];
            })
            ->all();
    }

    public function queryPageMetrics(string $from, string $to, int $minImpressions): array
    {
        return GscQueryMetric::query()
            ->whereBetween('date', [$from, $to])
            ->selectRaw('query, page, SUM(impressions) as impr, SUM(clicks) as clicks, SUM(impressions * position) as wpos')
            ->groupBy('query', 'page')
            ->havingRaw('SUM(impressions) >= ?', [$minImpressions])
            ->get()
            ->map(function ($row): array {
                $impressions = (int) $row->impr;

                return [
                    'query' => (string) $row->query,
                    'page' => (string) ($row->page ?? ''),
                    'impressions' => $impressions,
                    'clicks' => (int) $row->clicks,
                    // NOT pre-rounded — ContentGapReport rounds after receiving it.
                    'position' => $impressions > 0 ? ((float) $row->wpos) / $impressions : 0.0,
                ];
            })
            ->all();
    }
}
