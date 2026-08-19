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
 * Management API for gsc's Livewire\Admin\SiteAnalytics screen. Split into
 * two calls rather than one: the filtered, paginated event rows (events())
 * and a compact aggregate (summary()) carrying the headline stats, top-pages
 * breakdown and the trend-chart series — none of which need row-level data
 * to cross the wire.
 */
class AnalyticsController extends Controller
{
    use BuildsApiResponses;

    /** All analytics times are presented in Central Time (Chicago). */
    protected const TZ = 'America/Chicago';

    /** @var array<int,int> */
    protected const TREND_SPANS = [7, 14, 28, 90];

    public function events(Request $request): JsonResponse
    {
        $query = $this->applyFilters(TrackedEvent::query()->latest(), $request);

        $paginator = $query->paginate($this->perPage($request));

        return $this->paginatedResponse($paginator, fn (TrackedEvent $event) => $event->toApiArray());
    }

    public function summary(Request $request): JsonResponse
    {
        $period = $this->applyFilters(TrackedEvent::query(), $request);

        $byType = (clone $period)
            ->selectRaw('type, COUNT(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type');

        $stats = [
            'phone' => (int) ($byType[TrackedEvent::TYPE_PHONE_CLICK] ?? 0),
            'email' => (int) ($byType[TrackedEvent::TYPE_EMAIL_CLICK] ?? 0),
            'form' => (int) ($byType[TrackedEvent::TYPE_FORM_SUBMIT] ?? 0),
            'cta' => (int) ($byType[TrackedEvent::TYPE_CTA_CLICK] ?? 0),
        ];
        $stats['total'] = array_sum($stats);

        $topPages = (clone $period)
            ->selectRaw('page_path, COUNT(*) as count')
            ->whereNotNull('page_path')
            ->groupBy('page_path')
            ->orderByDesc('count')
            ->limit(8)
            ->pluck('count', 'page_path');

        return response()->json([
            'data' => [
                'stats' => $stats,
                'top_pages' => $topPages,
                'trend' => $this->trendChartData($this->trendDays($request)),
            ],
        ]);
    }

    protected function trendDays(Request $request): int
    {
        $days = (int) $request->integer('trend_days', 14);

        return in_array($days, self::TREND_SPANS, true) ? $days : 14;
    }

    protected function dateBoundary(?string $dateFilter): ?Carbon
    {
        // Boundaries are computed against the Chicago wall clock, then
        // converted to UTC so they compare correctly against UTC-stored
        // created_at values.
        return match ($dateFilter) {
            'today' => Carbon::now(self::TZ)->startOfDay()->utc(),
            'week' => Carbon::now(self::TZ)->subWeek()->utc(),
            'month' => Carbon::now(self::TZ)->subMonth()->utc(),
            default => null,
        };
    }

    protected function applyFilters(Builder $query, Request $request): Builder
    {
        $boundary = $this->dateBoundary($request->string('date_filter')->toString() ?: null);
        $type = $request->string('type_filter')->toString();

        return $query
            ->when($boundary, fn ($q) => $q->where('created_at', '>=', $boundary))
            ->when($type && $type !== 'all', fn ($q) => $q->where('type', $type));
    }

    /**
     * Daily per-type event counts for the selected trend span (independent
     * of the table filters, for context). Grouped by the Chicago calendar
     * day in PHP so DST transitions stay correct (CONVERT_TZ would require
     * the MySQL named-timezone tables to be loaded).
     *
     * @return array<int,array<string,mixed>>
     */
    protected function trendChartData(int $span): array
    {
        $byDay = TrackedEvent::query()
            ->where('created_at', '>=', Carbon::now(self::TZ)->subDays($span - 1)->startOfDay()->utc())
            ->get(['created_at', 'type'])
            ->groupBy(fn ($e) => $e->created_at->timezone(self::TZ)->toDateString());

        return collect(range($span - 1, 0))->map(function ($ago) use ($byDay) {
            $date = Carbon::now(self::TZ)->subDays($ago)->toDateString();
            $events = $byDay[$date] ?? collect();
            $types = $events->countBy('type');

            return [
                'date' => Carbon::parse($date)->format('M j'),
                'phone' => (int) ($types[TrackedEvent::TYPE_PHONE_CLICK] ?? 0),
                'email' => (int) ($types[TrackedEvent::TYPE_EMAIL_CLICK] ?? 0),
                'form' => (int) ($types[TrackedEvent::TYPE_FORM_SUBMIT] ?? 0),
                'cta' => (int) ($types[TrackedEvent::TYPE_CTA_CLICK] ?? 0),
                'total' => $events->count(),
            ];
        })->values()->all();
    }
}
