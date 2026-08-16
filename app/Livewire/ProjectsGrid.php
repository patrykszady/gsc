<?php

namespace App\Livewire;

use App\Models\AreaServed;
use App\Models\Project;
use App\Models\ProjectTimelapse;
use Illuminate\Pagination\Paginator;
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

    public bool $hideWhenEmpty = false;

    public bool $showPagination = true;

    /**
     * Project type for the "More {Type} Projects" button, rendered in the
     * pagination footer row. Callers used to include the partial separately
     * below the component, which cost a whole extra row of vertical space.
     */
    public ?string $moreProjectsType = null;

    public bool $responsivePerPage = false;

    /**
     * Optional block rendered BETWEEN the hero slider and the gallery.
     *
     * The area projects page used to print its own heading above this
     * component, which put two <h1>s on the page and pushed the gallery's own
     * header ("GS Construction Projects near {city}") into second place. The
     * page still authors the copy; the grid only decides where it sits,
     * because the hero it has to sit under lives in here.
     */
    public ?string $introHeading = null;

    public ?string $introBody = null;

    /**
     * Render the area About block between the hero and the intro heading.
     *
     * Placement-only, like introHeading/introBody: the hero and the gallery
     * both live in this component, so anything that has to sit between them
     * has to be positioned from here. The page decides whether to ask for it.
     */
    public bool $showAbout = false;

    public ?int $randomTimelapseId = null;

    // Stable random seed so re-renders (e.g. pagination) keep the same order
    public int $randomSeed = 0;

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
        $this->randomSeed = rand(1, 999999);

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
        $projectsQuery = Project::query()
            ->where('is_published', true)
            // Cover first, then sort order — one image either way. reorder()
            // matters: the images() relation bakes in orderBy(sort_order), and
            // eager-constraint orders APPEND to it, so without the reset the
            // window function sorts by sort_order first and the admin-chosen
            // cover can never win.
            ->with(['images' => fn($q) => $q->reorder()->orderByDesc('is_cover')->orderBy('sort_order')->limit(1)])
            ->when($this->type, fn($q) => $q->where('project_type', $this->type))
            ->orderByDesc('is_featured')
            ->tap(fn ($q) => \App\Support\SeededRandom::order($q, $this->randomSeed));

        // Area pages lead with that town's OWN projects, then its neighbours.
        //
        // The heading says "Projects in {city}" but this query had no area
        // filter at all — it returned every published project in random order,
        // so a Palatine page opened with Inverness and Arlington Heights work.
        // Same local-then-nearby principle the reviews carousel uses.
        //
        // Ordered, not filtered: a town with three projects would otherwise
        // show three cards and an empty pager. Cards from other towns keep
        // their town chip (see <x-project-grid :towns>), so nothing claims to
        // be in this city when it is not.
        if ($this->area) {
            $localIds = $this->area->localProjects(60)->pluck('id')->all();
            $nearbyIds = $this->area->nearbyProjects(60)->pluck('id')
                ->reject(fn ($id) => in_array($id, $localIds, true))->values()->all();

            $ranked = array_merge($localIds, $nearbyIds);
            if ($ranked !== []) {
                $projectsQuery->reorder()
                    ->orderByRaw(
                        'CASE WHEN id IN (' . implode(',', array_fill(0, count($localIds) ?: 1, '?')) . ') THEN 0 '
                        . 'WHEN id IN (' . implode(',', array_fill(0, count($nearbyIds) ?: 1, '?')) . ') THEN 1 '
                        . 'ELSE 2 END',
                        array_merge($localIds ?: [0], $nearbyIds ?: [0])
                    )
                    ->orderByDesc('is_featured')
                    ->tap(fn ($q) => \App\Support\SeededRandom::order($q, $this->randomSeed));
            }
        }

        $pageName = 'page';
        $requestedPage = max(1, (int) Paginator::resolveCurrentPage($pageName));
        $lastPage = max(1, (int) ceil((clone $projectsQuery)->count() / max(1, $this->perPage)));

        if ($requestedPage > $lastPage) {
            $this->setPage(1, $pageName);
            $requestedPage = 1;
        }

        $projects = $projectsQuery->paginate(
            $this->perPage,
            ['*'],
            $pageName,
            $requestedPage
        );

        // Service-aligned filter list, in the order services are declared.
        // Comes from ServiceCatalog rather than a hand-kept array, so adding a
        // service adds its filter automatically.
        // Every category GS Construction offers a service page for is shown, even
        // when no projects of that type are posted yet (the empty state then links
        // to the matching service page instead of showing a dead end).
        $curatedOrder = \App\Support\ServiceCatalog::projectTypes()->all();
        $existingTypes = Project::query()
            ->where('is_published', true)
            ->distinct()
            ->pluck('project_type')
            ->filter()
            ->all();

        $projectTypes = collect($curatedOrder)
            ->merge($existingTypes)
            ->unique()
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
