<?php

namespace App\Livewire;

use App\Models\Project;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Livewire\Component;
use Livewire\WithPagination;

class ProjectPhotosGallery extends Component
{
    use WithPagination;

    public Project $project;

    public int $perPage = 6;

    public int $desktopPerPage = 6;

    public int $mobilePerPage = 3;

    public function mount(Project $project): void
    {
        $this->project = $project->loadMissing('images');
    }

    public function setPerPage(int $count): void
    {
        $this->perPage = $count;
        $this->resetPage();
    }

    public function render()
    {
        // Sort: featured first, then shuffled (stable per request)
        $allImages = $this->project->images
            ->filter(fn($image) => filled($image->slug) || filled($image->id))
            ->sortByDesc('is_cover')
            ->groupBy('is_cover')
            ->flatMap(fn($group, $key) => $key ? $group : $group->shuffle())
            ->values();

        $page = max(1, (int) Paginator::resolveCurrentPage('page'));
        $items = $allImages->forPage($page, $this->perPage)->values();

        $paginator = new LengthAwarePaginator(
            $items,
            $allImages->count(),
            $this->perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()]
        );

        return view('livewire.project-photos-gallery', [
            'paginator' => $paginator,
            'allImages' => $allImages,
        ]);
    }
}
