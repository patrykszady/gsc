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

    #[Url]
    public string $dateFilter = 'week'; // today, week, month, all

    #[Url]
    public string $typeFilter = 'all';

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
        return match ($this->dateFilter) {
            'today' => today(),
            'week' => now()->subWeek(),
            'month' => now()->subMonth(),
            default => null,
        };
    }

    protected function applyFilters($query)
    {
        $boundary = $this->dateBoundary();

        return $query
            ->when($this->dateFilter === 'today', fn ($q) => $q->whereDate('created_at', today()))
            ->when($boundary && $this->dateFilter !== 'today', fn ($q) => $q->where('created_at', '>=', $boundary))
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

        // Daily trend for the last 14 days (independent of filters for context).
        $trend = TrackedEvent::query()
            ->where('created_at', '>=', now()->subDays(13)->startOfDay())
            ->selectRaw('DATE(created_at) as day, COUNT(*) as count')
            ->groupBy('day')
            ->orderBy('day')
            ->pluck('count', 'day');

        $days = collect(range(0, 13))->map(function ($i) use ($trend) {
            $date = now()->subDays(13 - $i)->toDateString();
            return [
                'label' => Carbon::parse($date)->format('M j'),
                'count' => (int) ($trend[$date] ?? 0),
            ];
        });
        $trendMax = max(1, $days->max('count'));

        return view('livewire.admin.site-analytics', [
            'events' => $events,
            'stats' => $stats,
            'topPages' => $topPages,
            'days' => $days,
            'trendMax' => $trendMax,
        ]);
    }
}
