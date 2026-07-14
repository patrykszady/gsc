<?php

namespace App\Livewire\Admin;

use App\Models\TrackedEvent;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.admin')]
#[Title('Analytics')]
class SiteAnalytics extends Component
{
    use WithPagination;

    /** All analytics times are presented in Central Time (Chicago). */
    protected const TZ = 'America/Chicago';

    #[Url]
    public string $dateFilter = 'week'; // today, week, month, all

    #[Url]
    public string $typeFilter = 'all';

    #[Url(as: 'trend', keep: true)]
    public int $trendDays = 14;

    /** @var array<int,int> */
    public const TREND_SPANS = [7, 14, 28, 90];

    public function mount(): void
    {
        if (! in_array($this->trendDays, self::TREND_SPANS, true)) {
            $this->trendDays = 14;
        }
    }

    public function setTrendDays(int $days): void
    {
        if (! in_array($days, self::TREND_SPANS, true)) {
            return;
        }

        $this->trendDays = $days;
    }

    public function updatedDateFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    protected function dateBoundary(): ?Carbon
    {
        // Boundaries are computed against the Chicago wall clock, then converted
        // to UTC so they compare correctly against UTC-stored created_at values.
        return match ($this->dateFilter) {
            'today' => Carbon::now(self::TZ)->startOfDay()->utc(),
            'week' => Carbon::now(self::TZ)->subWeek()->utc(),
            'month' => Carbon::now(self::TZ)->subMonth()->utc(),
            default => null,
        };
    }

    protected function applyFilters($query)
    {
        $boundary = $this->dateBoundary();

        return $query
            ->when($boundary, fn ($q) => $q->where('created_at', '>=', $boundary))
            ->when($this->typeFilter !== 'all', fn ($q) => $q->where('type', $this->typeFilter));
    }

    public function render()
    {
        // Recent events table (respects both filters)
        $events = $this->applyFilters(TrackedEvent::query()->latest())->paginate(20);

        // Headline counts per event type for the selected period.
        $period = $this->applyFilters(TrackedEvent::query());
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

        // Top pages driving conversions in the selected period.
        $topPages = (clone $period)
            ->selectRaw('page_path, COUNT(*) as count')
            ->whereNotNull('page_path')
            ->groupBy('page_path')
            ->orderByDesc('count')
            ->limit(8)
            ->pluck('count', 'page_path');

        return view('livewire.admin.site-analytics', [
            'events' => $events,
            'stats' => $stats,
            'topPages' => $topPages,
            'trendChartData' => $this->trendChartData(),
        ]);
    }

    /**
     * Daily per-type event counts for the selected trend span (independent of
     * the table filters, for context). Grouped by the Chicago calendar day in
     * PHP so DST transitions stay correct (CONVERT_TZ would require the MySQL
     * named-timezone tables to be loaded).
     *
     * @return array<int,array<string,mixed>>
     */
    protected function trendChartData(): array
    {
        $span = $this->trendDays;

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
