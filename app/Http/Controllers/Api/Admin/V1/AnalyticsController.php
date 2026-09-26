<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Api\Admin\V1\Concerns\BuildsApiResponses;
use App\Http\Controllers\Controller;
use App\Models\TrackedEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Management API for ss-systems' Livewire\Admin\SiteAnalytics screen. Split
 * into two calls rather than one: the filtered, paginated event rows
 * (events()) and a compact aggregate (summary()) carrying the headline
 * stats, top-pages breakdown and the trend-chart series — none of which
 * need row-level data to cross the wire.
 *
 * One scope for everything (2026-09-20): `days` (7–360, default 28) and
 * `type_filter` apply to every part of the screen. The earlier
 * today/week/month/all `date_filter` and the chart-only `trend_days` are
 * gone — the tiles, the chart, the top pages and the table used to answer
 * three different questions and the owner could not tell which was which.
 */
class AnalyticsController extends Controller
{
    use BuildsApiResponses;

    /** All analytics times are presented in Central Time (Chicago). */
    protected const TZ = 'America/Chicago';

    /**
     * The windows the screen's range picker offers. 28 rather than 30 so the
     * default reads as four whole weeks, the same "last 28 days" the owner
     * sees in Search Console.
     *
     * @var array<int,int>
     */
    public const SPANS = [7, 14, 28, 60, 90, 180, 360];

    public const DEFAULT_SPAN = 28;

    public function events(Request $request): JsonResponse
    {
        $query = TrackedEvent::query()
            ->latest()
            ->where('created_at', '>=', $this->windowStart($this->days($request)));

        $paginator = $this->applyType($query, $request)->paginate($this->perPage($request));

        return $this->paginatedResponse($paginator, fn (TrackedEvent $event) => $event->toApiArray());
    }

    public function summary(Request $request): JsonResponse
    {
        $days = $this->days($request);
        $start = $this->windowStart($days);
        $priorStart = $start->copy()->subDays($days);

        // The tiles are the per-type breakdown of the window, so they never
        // narrow by type — the selected type is highlighted on the screen
        // instead of the other three collapsing to zero. Each carries the
        // prior same-length window for its chevron.
        $stats = $this->countsByType(TrackedEvent::query()->where('created_at', '>=', $start));
        $statsPrev = $this->countsByType(
            TrackedEvent::query()->where('created_at', '>=', $priorStart)->where('created_at', '<', $start)
        );

        $topPages = $this->applyType(TrackedEvent::query(), $request)
            ->where('created_at', '>=', $start)
            ->selectRaw('page_path, COUNT(*) as count')
            ->whereNotNull('page_path')
            ->groupBy('page_path')
            ->orderByDesc('count')
            ->limit(8)
            ->pluck('count', 'page_path');

        return response()->json([
            'data' => [
                'days' => $days,
                'stats' => $stats,
                'stats_prev' => $statsPrev,
                'top_pages' => $topPages,
                'trend' => $this->trendChartData($days, $start),
            ],
        ]);
    }

    protected function days(Request $request): int
    {
        $days = (int) $request->integer('days', self::DEFAULT_SPAN);

        return in_array($days, self::SPANS, true) ? $days : self::DEFAULT_SPAN;
    }

    /**
     * "Last N days" is today plus the N-1 days before it, on the Chicago
     * calendar, converted to UTC so it compares correctly against the
     * UTC-stored created_at values.
     */
    protected function windowStart(int $days): Carbon
    {
        return Carbon::now(self::TZ)->subDays($days - 1)->startOfDay()->utc();
    }

    protected function applyType(Builder $query, Request $request): Builder
    {
        $type = $request->string('type_filter')->toString();

        return $query->when($type && $type !== 'all', fn ($q) => $q->where('type', $type));
    }

    /**
     * Public + static so DashboardStatsController's "contacts" tile can
     * reuse this exact per-type breakdown rather than recounting
     * TrackedEvent independently.
     *
     * @return array<string,int>
     */
    public static function countsByType(Builder $query): array
    {
        $byType = $query->selectRaw('type, COUNT(*) as count')->groupBy('type')->pluck('count', 'type');

        $counts = [
            'phone' => (int) ($byType[TrackedEvent::TYPE_PHONE_CLICK] ?? 0),
            'email' => (int) ($byType[TrackedEvent::TYPE_EMAIL_CLICK] ?? 0),
            'form' => (int) ($byType[TrackedEvent::TYPE_FORM_SUBMIT] ?? 0),
            'cta' => (int) ($byType[TrackedEvent::TYPE_CTA_CLICK] ?? 0),
        ];
        $counts['total'] = array_sum($counts);

        return $counts;
    }

    /**
     * Daily per-type event counts for the window, every type in every row:
     * the screen draws only the selected type's line, so one series serves
     * every type filter. Grouped by the Chicago calendar day in PHP so DST
     * transitions stay correct (CONVERT_TZ would require the MySQL
     * named-timezone tables to be loaded).
     *
     * @return array<int,array<string,mixed>>
     */
    protected function trendChartData(int $days, Carbon $start): array
    {
        $byDay = TrackedEvent::query()
            ->where('created_at', '>=', $start)
            ->get(['created_at', 'type'])
            ->groupBy(fn ($e) => $e->created_at->timezone(self::TZ)->toDateString());

        return collect(range($days - 1, 0))->map(function ($ago) use ($byDay) {
            $date = Carbon::now(self::TZ)->subDays($ago)->toDateString();
            $events = $byDay[$date] ?? collect();
            $types = $events->countBy('type');

            return [
                'date' => Carbon::parse($date)->format('M j'),
                // ISO day for the chart's time axis; 'date' stays the tooltip label.
                'day' => $date,
                'phone' => (int) ($types[TrackedEvent::TYPE_PHONE_CLICK] ?? 0),
                'email' => (int) ($types[TrackedEvent::TYPE_EMAIL_CLICK] ?? 0),
                'form' => (int) ($types[TrackedEvent::TYPE_FORM_SUBMIT] ?? 0),
                'cta' => (int) ($types[TrackedEvent::TYPE_CTA_CLICK] ?? 0),
                'total' => $events->count(),
            ];
        })->values()->all();
    }
}
