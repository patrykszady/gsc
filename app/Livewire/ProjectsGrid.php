<?php

namespace App\Livewire;

use App\Models\AreaServed;
use App\Models\Project;
use App\Models\ProjectTimelapse;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ProjectsGrid extends Component
{
    use WithPagination;

    public ?AreaServed $area = null;

    #[Url]
    public string $type = '';

    public int $perPage = 6;

    public int $desktopPerPage = 6;

    public ?int $mobilePerPage = null;

    public bool $hideFilters = false;

    public bool $showPagination = true;

    public bool $responsivePerPage = false;

    public ?int $randomTimelapseId = null;

    public function mount(?string $projectType = null, ?int $limit = null, bool $hideFilters = false, bool $showPagination = true, ?int $mobilePerPage = null): void
    {
        if ($projectType) {
            $this->type = $projectType;
        }
        if ($limit) {
            $this->perPage = $limit;
            $this->desktopPerPage = $limit;
        }
        $this->hideFilters = $hideFilters;
        $this->showPagination = $showPagination;
        $this->mobilePerPage = $mobilePerPage;
        $this->responsivePerPage = ! $hideFilters && ! $limit && $mobilePerPage !== null;

        if (! $this->hideFilters) {
            $this->randomTimelapseId = ProjectTimelapse::query()
                ->whereHas('frames')
                ->inRandomOrder()
                ->value('id');
        }
    }

    public function setPerPage(int $count): void
    {
        $this->perPage = $count;
        $this->resetPage();
    }

    public function render()
    {
        $projects = Project::query()
            ->where('is_published', true)
            ->with(['images' => fn($q) => $q->orderBy('sort_order')->limit(1)])
            ->when($this->type, fn($q) => $q->where('project_type', $this->type))
            ->orderByDesc('is_featured')
            ->inRandomOrder()
            ->paginate($this->perPage);

        $projectTypes = Project::query()
            ->where('is_published', true)
            ->distinct()
            ->pluck('project_type')
            ->filter()
            ->sort()
            ->values();

        return view('livewire.projects-grid', [
            'projects' => $projects,
            'projectTypes' => $projectTypes,
        ]);
    }

    public function filterByType(string $type): void
    {
        $this->type = $this->type === $type ? '' : $type;
        $this->resetPage();
    }

    public function clearFilter(): void
    {
        $this->type = '';
        $this->resetPage();
    }
}
