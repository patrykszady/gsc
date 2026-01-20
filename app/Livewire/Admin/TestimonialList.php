<?php

namespace App\Livewire\Admin;

use App\Models\Testimonial;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.admin')]
#[Title('Reviews')]
class TestimonialList extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $type = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingType(): void
    {
        $this->resetPage();
    }

    public function delete(Testimonial $testimonial): void
    {
        $testimonial->delete();

        session()->flash('success', 'Review deleted successfully.');
    }

    public function render()
    {
        $query = Testimonial::query();

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('reviewer_name', 'like', "%{$this->search}%")
                    ->orWhere('review_description', 'like', "%{$this->search}%")
                    ->orWhere('project_location', 'like', "%{$this->search}%");
            });
        }

        if ($this->type) {
            $query->where('project_type', $this->type);
        }

        // Get unique project types for filter
        $projectTypes = Testimonial::whereNotNull('project_type')
            ->distinct()
            ->pluck('project_type')
            ->filter()
            ->sort()
            ->values();

        return view('livewire.admin.testimonial-list', [
            'testimonials' => $query->orderByDesc('review_date')->orderByDesc('created_at')->paginate(12),
            'projectTypes' => $projectTypes,
        ]);
    }
}
