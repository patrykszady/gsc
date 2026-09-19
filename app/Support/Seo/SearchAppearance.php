<?php

namespace App\Support\Seo;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * How our results LOOK on Google — a review snippet, a product snippet, an AI
 * Overview — rather than how many there were.
 *
 * This answers a question the rest of the dashboard cannot: thin non-brand CTR
 * reads as a ranking problem, but if the impressions are landing inside AI
 * Overviews or rich results that never get clicked, the ranking is fine and the
 * result's shape is the story.
 *
 * The data behind it (gsc_search_appearance_metrics) was empty on every site
 * until 2026-09-18, because the sync asked Google for
 * ['date', 'searchAppearance'] in one call and Google answers that with a 400:
 * "Cannot group by search appearance dimension together with another
 * dimension." The sync only warned, so nothing ever noticed. See
 * SyncGoogleSearchConsole::syncSearchAppearance().
 *
 * Kept BYTE-IDENTICAL in gsc and jpeterson-design (change it in one, copy it to
 * the other): the ss-systems central admin renders one card from this shape for
 * every tenant. The table query is passed IN rather than built here, because
 * that is the one place the sites genuinely differ — gsc scopes it per site
 * through Tenancy, jpeterson-design is a single site.
 */
final class SearchAppearance
{
    public const TABLE = 'gsc_search_appearance_metrics';

    /**
     * Google's raw dimension keys are SCREAMING_SNAKE pipeline identifiers. The
     * admin must not print those at an owner, and mapping them here rather than
     * in Blade keeps both tenants wording them the same.
     */
    public const LABELS = [
        'AI_OVERVIEW' => 'AI Overviews',
        'AMP_BLUE_LINK' => 'Fast-loading mobile pages',
        'AMP_STORY' => 'Web stories',
        'AMP_TOP_STORIES' => 'Top stories',
        'EVENT_DETAILS' => 'Event listings',
        'EVENT_LISTING' => 'Event listings',
        'FAQ_RICH_RESULT' => 'FAQ answers',
        'HOW_TO_RICH_RESULT' => 'How-to steps',
        'JOB_DETAILS' => 'Job postings',
        'JOB_LISTING' => 'Job postings',
        'LEARNING_VIDEO' => 'Learning videos',
        'MATH_SOLVERS' => 'Math solvers',
        'MERCHANT_LISTINGS' => 'Shopping listings',
        'ORGANIC_SHOPPING' => 'Shopping results',
        'PRACTICE_PROBLEMS' => 'Practice problems',
        'PRODUCT_SNIPPETS' => 'Product details',
        'RECIPE_FEATURE' => 'Recipe features',
        'RECIPE_RICH_RESULT' => 'Recipe results',
        'REVIEW_SNIPPET' => 'Star ratings',
        'SPECIAL_ANNOUNCEMENT' => 'Announcements',
        'SUBSCRIBED_CONTENT' => 'Subscribed content',
        'TRANSLATED_RESULT' => 'Translated results',
        'VIDEO' => 'Videos',
        'WEBLITE' => 'Lite pages',
    ];

    /**
     * @param  Closure():Builder  $query  a fresh builder for TABLE, scoped however the site scopes it
     * @return array{available: bool, days: int, rows: array<int, array<string, mixed>>, total_impressions: int, total_clicks: int}
     */
    public static function snapshot(Closure $query, int $days = 28): array
    {
        $empty = ['available' => false, 'days' => $days, 'rows' => [], 'total_impressions' => 0, 'total_clicks' => 0];

        if (! Schema::hasTable(self::TABLE)) {
            return $empty;
        }

        $end = Carbon::today();
        $start = $end->copy()->subDays($days - 1);
        $priorStart = $start->copy()->subDays($days);
        $priorEnd = $start->copy()->subDay();

        $current = self::totals($query, $start, $end);

        if ($current === []) {
            return $empty;
        }

        $prior = self::totals($query, $priorStart, $priorEnd);

        $rows = [];

        foreach ($current as $appearance => $totals) {
            $was = $prior[$appearance] ?? null;

            $rows[] = [
                'appearance' => $appearance,
                'label' => self::label($appearance),
                'clicks' => $totals['clicks'],
                'impressions' => $totals['impressions'],
                // Recomputed from the totals rather than averaging the daily
                // ctr/position columns, which would weight a quiet day the same
                // as a busy one.
                'ctr' => $totals['impressions'] > 0 ? round($totals['clicks'] / $totals['impressions'] * 100, 2) : 0.0,
                'position' => $totals['position_weight'] > 0
                    ? round($totals['position_sum'] / $totals['position_weight'], 1)
                    : 0.0,
                'prior_clicks' => $was['clicks'] ?? null,
                'prior_impressions' => $was['impressions'] ?? null,
            ];
        }

        // Impressions, not clicks: a rich result that is seen constantly and
        // clicked rarely is exactly what this card exists to surface.
        usort($rows, fn ($a, $b) => $b['impressions'] <=> $a['impressions']);

        return [
            'available' => true,
            'days' => $days,
            'rows' => $rows,
            'total_impressions' => array_sum(array_column($rows, 'impressions')),
            'total_clicks' => array_sum(array_column($rows, 'clicks')),
        ];
    }

    public static function label(string $appearance): string
    {
        if (isset(self::LABELS[$appearance])) {
            return self::LABELS[$appearance];
        }

        // An appearance Google added since this list was written still has to
        // read as words, never as SCREAMING_SNAKE.
        return ucfirst(strtolower(str_replace('_', ' ', $appearance)));
    }

    /**
     * @param  Closure():Builder  $query
     * @return array<string, array{clicks: int, impressions: int, position_sum: float, position_weight: int}>
     */
    protected static function totals(Closure $query, Carbon $start, Carbon $end): array
    {
        $rows = $query()
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get(['appearance', 'clicks', 'impressions', 'position']);

        $totals = [];

        foreach ($rows as $row) {
            $key = (string) $row->appearance;

            $totals[$key] ??= ['clicks' => 0, 'impressions' => 0, 'position_sum' => 0.0, 'position_weight' => 0];

            $impressions = (int) $row->impressions;

            $totals[$key]['clicks'] += (int) $row->clicks;
            $totals[$key]['impressions'] += $impressions;
            // Average position weighted by impressions, the way Google reports it.
            $totals[$key]['position_sum'] += (float) $row->position * $impressions;
            $totals[$key]['position_weight'] += $impressions;
        }

        return $totals;
    }
}
