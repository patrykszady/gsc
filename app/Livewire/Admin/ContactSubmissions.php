<?php

namespace App\Livewire\Admin;

use App\Models\ContactSubmission;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.admin')]
#[Title('Contact Submissions')]
class ContactSubmissions extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $dateFilter = 'all'; // all, today, week, month

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedDateFilter(): void
    {
        $this->resetPage();
    }

    public function delete(int $id): void
    {
        ContactSubmission::find($id)?->delete();
    }

    public function render()
    {
        $query = ContactSubmission::query()->latest();

        // Search filter
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                    ->orWhere('email', 'like', "%{$this->search}%")
                    ->orWhere('phone', 'like', "%{$this->search}%")
                    ->orWhere('city', 'like', "%{$this->search}%")
                    ->orWhere('address', 'like', "%{$this->search}%");
            });
        }

        // Date filter
        $query->when($this->dateFilter === 'today', fn ($q) => $q->whereDate('created_at', today()))
            ->when($this->dateFilter === 'week', fn ($q) => $q->where('created_at', '>=', now()->subWeek()))
            ->when($this->dateFilter === 'month', fn ($q) => $q->where('created_at', '>=', now()->subMonth()));

        // Stats
        $stats = [
            'total' => ContactSubmission::count(),
            'today' => ContactSubmission::whereDate('created_at', today())->count(),
            'week' => ContactSubmission::where('created_at', '>=', now()->subWeek())->count(),
            'month' => ContactSubmission::where('created_at', '>=', now()->subMonth())->count(),
        ];

        // Top cities
        $topCities = ContactSubmission::selectRaw('city, COUNT(*) as count')
            ->whereNotNull('city')
            ->groupBy('city')
            ->orderByDesc('count')
            ->limit(5)
            ->pluck('count', 'city');

        // UTM sources
        $utmSources = ContactSubmission::selectRaw('utm_source, COUNT(*) as count')
            ->whereNotNull('utm_source')
            ->groupBy('utm_source')
            ->orderByDesc('count')
            ->limit(5)
            ->pluck('count', 'utm_source');

        return view('livewire.admin.contact-submissions', [
            'submissions' => $query->paginate(15),
            'stats' => $stats,
            'topCities' => $topCities,
            'utmSources' => $utmSources,
        ]);
    }
}
