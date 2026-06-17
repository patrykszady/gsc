<?php

namespace App\Livewire\Admin;

use App\Models\ClientError;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.admin')]
#[Title('JS Errors')]
class ClientErrors extends Component
{
    use WithPagination;

    #[Url]
    public string $statusFilter = 'open'; // open, resolved, all

    #[Url]
    public string $kindFilter = 'all'; // all, error, promise

    public ?int $expandedId = null;

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedKindFilter(): void
    {
        $this->resetPage();
    }

    public function toggleExpand(int $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    public function markResolved(int $id): void
    {
        ClientError::whereKey($id)->update(['resolved_at' => now()]);
    }

    public function markUnresolved(int $id): void
    {
        ClientError::whereKey($id)->update(['resolved_at' => null]);
    }

    public function delete(int $id): void
    {
        ClientError::whereKey($id)->delete();
    }

    public function resolveAll(): void
    {
        ClientError::whereNull('resolved_at')->update(['resolved_at' => now()]);
        $this->resetPage();
    }

    public function render()
    {
        $base = ClientError::query()
            ->when($this->statusFilter === 'open', fn ($q) => $q->whereNull('resolved_at'))
            ->when($this->statusFilter === 'resolved', fn ($q) => $q->whereNotNull('resolved_at'))
            ->when($this->kindFilter !== 'all', fn ($q) => $q->where('kind', $this->kindFilter));

        $errors = (clone $base)
            ->orderByDesc('last_seen_at')
            ->paginate(20);

        $stats = [
            'open' => ClientError::whereNull('resolved_at')->count(),
            'occurrences' => (int) ClientError::whereNull('resolved_at')->sum('occurrences'),
            'last_24h' => ClientError::where('last_seen_at', '>=', now()->subDay())->count(),
            'resolved' => ClientError::whereNotNull('resolved_at')->count(),
        ];

        return view('livewire.admin.client-errors', [
            'errors' => $errors,
            'stats' => $stats,
        ]);
    }
}
