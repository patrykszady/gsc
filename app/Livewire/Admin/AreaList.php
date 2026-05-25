<?php

namespace App\Livewire\Admin;

use App\Models\AreaServed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.admin')]
class AreaList extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function delete(int $id): void
    {
        AreaServed::whereKey($id)->delete();
        $this->dispatch('area-deleted');
    }

    public function render()
    {
        $areas = AreaServed::query()
            ->when($this->search !== '', function ($q) {
                $term = "%{$this->search}%";
                $q->where('city', 'like', $term)->orWhere('slug', 'like', $term);
            })
            ->orderBy('city')
            ->paginate(25);

        return view('livewire.admin.area-list', [
            'areas' => $areas,
        ]);
    }
}
