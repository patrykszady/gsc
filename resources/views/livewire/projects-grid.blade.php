@php
    // Service-aligned labels + matching service-page routes for filter buttons
    // and the empty state (so a type with no posted projects links to its service).
    $filterMeta = [
        'kitchen' => ['label' => 'Kitchen', 'route' => 'services.kitchen'],
        'bathroom' => ['label' => 'Bathroom', 'route' => 'services.bathroom'],
        'home-remodel' => ['label' => 'Home Remodeling', 'route' => 'services.home'],
        'basement' => ['label' => 'Basement', 'route' => 'services.basement'],
        'addition' => ['label' => 'Additions', 'route' => 'services.additions'],
        'mudroom' => ['label' => 'Mudroom & Laundry', 'route' => 'services.mudroom'],
    ];

    // When this is true we render an (otherwise empty) root element. The root
    // tag must NOT be wrapped in an @if/@else, because Livewire injects
    // <!--[if BLOCK]--> morph markers before the conditional body. In the
    // "hidden" branch that comment ends up on the same line as the <div>,
    // which breaks Livewire's root-tag detection regex and throws
    // RootTagMissingFromViewException. A single static root avoids that.
    $projectsGridHidden = $hideWhenEmpty && $type && $projects->isEmpty();
@endphp
<div>
@unless($projectsGridHidden)
<div
    class="relative isolate bg-white pt-10 pb-8 sm:pt-14 sm:pb-12 dark:bg-zinc-900"
    @if($responsivePerPage)
        x-data="{ isMobile: window.innerWidth < 640, resizeTimer: null, syncPerPage() { const nextPerPage = this.isMobile ? {{ $mobilePerPage }} : {{ $desktopPerPage }}; if ($wire.perPage !== nextPerPage) { $wire.setPerPage(nextPerPage); } } }"
        x-init="syncPerPage(); window.addEventListener('resize', () => { clearTimeout(resizeTimer); resizeTimer = setTimeout(() => { const nextIsMobile = window.innerWidth < 640; if (nextIsMobile !== isMobile) { isMobile = nextIsMobile; syncPerPage(); } }, 120); })"
    @endif
>
    {{-- Gradient blur background --}}
    <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 top-1/2 -z-10 -translate-y-1/2 transform-gpu overflow-hidden opacity-30 blur-3xl">
        <div style="clip-path: polygon(74.1% 44.1%, 100% 61.6%, 97.5% 26.9%, 85.5% 0.1%, 80.7% 2%, 72.5% 32.5%, 60.2% 62.4%, 52.4% 68.1%, 47.5% 58.3%, 45.2% 34.5%, 27.5% 76.7%, 0.1% 64.9%, 17.9% 100%, 27.6% 76.8%, 76.1% 97.7%, 74.1% 44.1%)" class="ml-[max(50%,38rem)] aspect-[1313/771] w-[82.0625rem] bg-linear-to-tr from-sky-300 to-sky-600"></div>
    </div>
    <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 top-0 -z-10 flex transform-gpu overflow-hidden pt-32 opacity-25 blur-3xl sm:pt-40 xl:justify-end">
        <div style="clip-path: polygon(74.1% 44.1%, 100% 61.6%, 97.5% 26.9%, 85.5% 0.1%, 80.7% 2%, 72.5% 32.5%, 60.2% 62.4%, 52.4% 68.1%, 47.5% 58.3%, 45.2% 34.5%, 27.5% 76.7%, 0.1% 64.9%, 17.9% 100%, 27.6% 76.8%, 76.1% 97.7%, 74.1% 44.1%)" class="ml-[-22rem] aspect-[1313/771] w-[82.0625rem] flex-none origin-top-right rotate-[30deg] bg-linear-to-tr from-sky-300 to-sky-600 xl:mr-[calc(50%-12rem)] xl:ml-0"></div>
    </div>

    <div class="mx-auto max-w-7xl px-6 lg:px-8">
        {{-- Header --}}
        <div class="mx-auto max-w-4xl text-center">
            <p class="text-sm font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400">Our Work</p>
            @php
                $typeLabels = [
                    'kitchen' => 'Kitchen',
                    'bathroom' => 'Bathroom',
                    'home-remodel' => 'Home Remodeling',
                    'basement' => 'Basement',
                    'addition' => 'Additions',
                    'mudroom' => 'Mudroom & Laundry',
                ];
                $typeLabel = $type ? ($typeLabels[$type] ?? ucfirst($type)) : null;
            @endphp
            @if($hideFilters)
            {{-- Use H2 when embedded in another page (service pages have H1 in hero) --}}
            <h2 class="mt-2 font-heading text-4xl font-bold tracking-tight text-zinc-900 sm:text-5xl dark:text-white">
            @else
            {{-- Use H1 when this is the main projects page --}}
            <h1 class="mt-2 font-heading text-4xl font-bold tracking-tight text-zinc-900 sm:text-5xl dark:text-white">
            @endif
                @if($area && $typeLabel)
                    {{ $typeLabel }} Projects near {{ $area->city }}
                @elseif($area)
                    GS Construction Projects near {{ $area->city }}
                @elseif($typeLabel)
                    {{ $typeLabel }} Projects
                @else
                    Our Projects
                @endif
            @if($hideFilters)
            </h2>
            @else
            </h1>
            @endif
            <p class="mx-auto mt-4 max-w-4xl text-base sm:text-lg text-zinc-600 dark:text-zinc-300">
                {{-- "near", not "in". ProjectsGrid filters on is_published and
                     project_type only — never on $area — so this grid shows work
                     from across Chicagoland. Saying "completed in {city}" over a
                     Chicago or Western Springs job is a claim the page cannot
                     support. Each card carries its own town where relevant. --}}
                @if($area && $typeLabel)
                    Browse our {{ strtolower($typeLabel) }} remodeling projects completed near {{ $area->city }}. See the quality craftsmanship our family brings to every {{ strtolower($typeLabel) }} project.
                @elseif($area)
                    Browse GS Construction's portfolio of completed home remodeling projects near {{ $area->city }} and the surrounding suburbs. From kitchens to bathrooms, see the quality craftsmanship our family brings to every project.
                @else
                    Browse GS Construction's portfolio of completed home remodeling projects throughout Chicagoland. From kitchens to bathrooms, basements to whole-home renovations, see the quality craftsmanship our family brings to every project.
                @endif
            </p>
        </div>

        {{-- Hero Image Slider (main projects page only) --}}
        @if(!$hideFilters)
            <div class="mt-10 overflow-hidden rounded-2xl">
                <livewire:main-project-hero-slider
                    :slides="[
                        ['projectType' => 'kitchen', 'alt' => 'Kitchen remodeling'],
                        ['projectType' => 'bathroom', 'alt' => 'Bathroom remodeling'],
                        ['projectType' => 'home-remodel', 'alt' => 'Home remodeling'],
                    ]"
                    height-classes="h-[375px] sm:h-[450px] lg:h-[525px]"
                    :images-only="true"
                />
            </div>
        @endif

        {{-- Filter buttons --}}
        @if($projectTypes->count() > 1 && !$hideFilters)
        <div id="projects-grid" class="mt-8 flex flex-wrap justify-center gap-2">
            <button
                wire:click="clearFilter"
                class="rounded-full px-4 py-2 text-sm font-medium transition {{ !$type ? 'bg-sky-700 text-white' : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700' }}"
            >
                All
            </button>
            @foreach($projectTypes as $projectType)
            <button
                wire:click="filterByType('{{ $projectType }}')"
                class="rounded-full px-4 py-2 text-sm font-medium transition {{ $type === $projectType ? 'bg-sky-700 text-white' : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700' }}"
            >
                {{ $filterMeta[$projectType]['label'] ?? ucfirst($projectType) }}
            </button>
            @endforeach
        </div>
        @endif

        {{-- Projects Grid (Livewire pagination) --}}
        <div
            id="projects-grid-cards-top"
            wire:key="projects-grid-{{ $type }}"
            class="relative"
        >
            @if($projects->isEmpty())
                @php $emptyService = $type ? ($filterMeta[$type] ?? null) : null; @endphp
                <div class="mt-10 py-12 text-center">
                    @if($emptyService && \Illuminate\Support\Facades\Route::has($emptyService['route']))
                        <p class="text-lg text-zinc-500 dark:text-zinc-400">
                            We haven't posted {{ strtolower($emptyService['label']) }} projects online yet — but it's one of our services.
                        </p>
                        <a
                            href="{{ route($emptyService['route']) }}"
                            wire:navigate
                            class="mt-4 inline-flex items-center gap-2 rounded-lg bg-sky-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-800"
                        >
                            Explore our {{ $emptyService['label'] }} services
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
                        </a>
                    @else
                        <p class="text-lg text-zinc-500 dark:text-zinc-400">No projects found.</p>
                    @endif
                </div>
            @else
                <x-project-grid
                    :projects="$projects"
                    class="mx-auto mt-10 max-w-2xl lg:mx-0 lg:max-w-none transition-opacity duration-150"
                    wire:loading.delay.class="opacity-60"
                    wire:target="previousPage,nextPage,gotoPage,setPage" />

                {{-- Footer row: pagination and the "More {Type} Projects"
                     button share one line. The button used to render below the
                     whole component, costing an extra row of vertical space
                     under a grid that already has a results count and pager. --}}
                @php $showsPager = $showPagination && $projects->hasPages(); @endphp
                @if($showsPager)
                    <div id="projects-grid-pagination" class="mt-10">
                        <flux:pagination :paginator="$projects" />
                    </div>
                @endif

                {{-- Directly under the pager, centred. Kept inside the component
                     (rather than included by the caller after it) so it stays
                     tight to the pagination instead of a full section apart. --}}
                @if($moreProjectsType)
                    <div class="mt-5 text-center">
                        @include('partials.more-projects-link', ['projectType' => $moreProjectsType, 'inline' => true])
                    </div>
                @endif
            @endif
        </div>

        {{-- Timelapse Section (main projects page only) --}}
        @if(!$hideFilters)
            <div class="mt-10">
                <livewire:timelapse-section :timelapse-id="$randomTimelapseId" :key="'projects-timelapse-'.($randomTimelapseId ?? 'fallback')" />
            </div>
        @endif
    </div>
</div>
@endunless
</div>

@script
<script>
    if (!window.__projectsGridReloadTopHandled) {
        window.__projectsGridReloadTopHandled = true;
        const navigationEntry = performance.getEntriesByType('navigation')[0];
        const isReload = (navigationEntry && navigationEntry.type === 'reload')
            || performance.navigation?.type === 1;

        if (isReload) {
            if ('scrollRestoration' in history) {
                history.scrollRestoration = 'manual';
            }

            const forceTop = () => window.scrollTo({ top: 0, left: 0, behavior: 'auto' });

            forceTop();
            window.addEventListener('load', forceTop, { once: true });
            window.addEventListener('pageshow', forceTop, { once: true });
            requestAnimationFrame(forceTop);
        }
    }

    if (!window.__projectsGridPaginationHookRegistered) {
        window.__projectsGridPaginationHookRegistered = true;
        Livewire.hook('commit', ({ component, commit, succeed }) => {
            if (component.name !== 'projects-grid') return;

            const paginationMethods = ['previousPage', 'nextPage', 'gotoPage', 'setPage'];
            const isPaginationCommit = (commit?.calls ?? []).some(call => paginationMethods.includes(call.method));

            if (!isPaginationCommit) return;

            succeed(() => {
                const target = document.getElementById('projects-grid-cards-top');
                if (!target) return;
                const start = window.scrollY;
                const end = target.getBoundingClientRect().top + start - 16;
                if (Math.abs(end - start) < 10) return;
                const duration = 600;
                const startTime = performance.now();
                function easeInOutQuad(t) { return t < 0.5 ? 2*t*t : -1+(4-2*t)*t; }
                function step(now) {
                    const elapsed = now - startTime;
                    const progress = Math.min(elapsed / duration, 1);
                    window.scrollTo(0, start + (end - start) * easeInOutQuad(progress));
                    if (progress < 1) requestAnimationFrame(step);
                }
                requestAnimationFrame(step);
            });
        });
    }
</script>
@endscript
