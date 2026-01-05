<?php

namespace App\Livewire\Admin;

use App\Models\Project;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.admin')]
#[Title('Projects')]
class ProjectList extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $type = '';

    #[Url]
    public string $status = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingType(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function delete(Project $project): void
    {
        // Delete all images first (this triggers file deletion)
        $project->images->each->delete();
        $project->delete();

        session()->flash('success', 'Project deleted successfully.');
    }

    public function togglePublished(Project $project): void
    {
        $project->update(['is_published' => !$project->is_published]);
    }

    public function toggleFeatured(Project $project): void
    {
        $project->update(['is_featured' => !$project->is_featured]);
    }

    public function render()
    {
        $query = Project::with('coverImage')
            ->withCount('images');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('title', 'like', "%{$this->search}%")
                    ->orWhere('description', 'like', "%{$this->search}%")
                    ->orWhere('location', 'like', "%{$this->search}%");
            });
        }

        if ($this->type) {
            $query->where('project_type', $this->type);
        }

        if ($this->status === 'published') {
            $query->where('is_published', true);
        } elseif ($this->status === 'draft') {
            $query->where('is_published', false);
        } elseif ($this->status === 'featured') {
            $query->where('is_featured', true);
        }

        return view('livewire.admin.project-list', [
            'projects' => $query->latest()->paginate(12),
            'projectTypes' => Project::projectTypes(),
        ]);
    }
}
