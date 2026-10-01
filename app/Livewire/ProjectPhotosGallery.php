<?php

namespace App\Livewire;

use App\Models\Project;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\WithPagination;

class ProjectPhotosGallery extends Component
{
    use WithPagination;

    public Project $project;

    /**
     * Scope this instance to one area's own photos instead of the project's
     * area-less ones — the project page renders one instance per area
     * (project-page.blade.php), each with this set, plus one unscoped
     * instance above them all for the area-less gallery.
     */
    public ?int $areaId = null;

    public int $perPage = 6;

    public int $desktopPerPage = 6;

    public int $mobilePerPage = 3;

    public function mount(Project $project, ?int $areaId = null): void
    {
        $this->project = $project->loadMissing(['images', 'areas.images']);
        $this->areaId = $areaId;
    }

    /**
     * A distinct page name per instance: the project page renders several
     * of these side by side (area-less, then one per area), and Livewire's
     * WithPagination keys its state by page name — sharing 'page' across
     * them would make paging one gallery silently page every other one too.
     */
    protected function pageName(): string
    {
        return $this->areaId ? "page-area-{$this->areaId}" : 'page';
    }

    public function setPerPage(int $count): void
    {
        $this->perPage = $count;
        $this->resetPage($this->pageName());
    }

    public function render()
    {
        if ($this->areaId !== null) {
            $area = $this->project->areas->firstWhere('id', $this->areaId);
            $images = $area?->images ?? new Collection;
            $coverId = $area?->cover()?->id;
        } else {
            // Area-less: belongs to the project as a whole, not one of its areas.
            $images = $this->project->images->filter(fn ($image) => $image->project_area_id === null)->values();
            $coverId = $images->firstWhere('is_cover', true)?->id;
        }

        // Cover first, then the rest shuffled (stable per request) — the
        // same shape this gallery has always used, now applied WITHIN each
        // group (area-less, or one area) rather than across the whole
        // project's photos at once.
        $allImages = $images
            ->filter(fn ($image) => filled($image->slug) || filled($image->id))
            ->sortBy(fn ($image) => $coverId !== null && $image->id === $coverId ? 0 : 1)
            ->groupBy(fn ($image) => $coverId !== null && $image->id === $coverId)
            ->flatMap(fn ($group, $isCover) => $isCover ? $group : $group->shuffle())
            ->values();

        $pageName = $this->pageName();
        $page = max(1, (int) Paginator::resolveCurrentPage($pageName));
        $items = $allImages->forPage($page, $this->perPage)->values();

        $paginator = new LengthAwarePaginator(
            $items,
            $allImages->count(),
            $this->perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => $pageName]
        );

        return view('livewire.project-photos-gallery', [
            'paginator' => $paginator,
            'allImages' => $allImages,
            // Area instances skip the "Project Photos" heading — the project
            // page already prints that area's own heading right above this
            // component (project-page.blade.php); printing it twice read as
            // a mistake, not emphasis.
            'heading' => $this->areaId === null ? 'Project Photos' : null,
        ]);
    }
}
