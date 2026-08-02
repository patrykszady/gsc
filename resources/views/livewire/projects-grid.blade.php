@php
    // Service-aligned labels + the matching service page for filter buttons and
    // the empty state (so a type with no posted projects links to its service
    // rather than dead-ending). Derived from ServiceCatalog so this cannot
    // drift from the footer, the service chips or the service pages themselves.
    $filterMeta = \App\Support\ServiceCatalog::withProjects()
        ->mapWithKeys(fn (array $s): array => [
            $s['projectType'] => ['label' => $s['shortLabel'], 'url' => $s['url'], 'serviceLabel' => $s['label']],
        ])
        ->all();

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
    <x-decor-blobs />

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
                    {{ $typeLabel }} Projects in {{ $area->city }}
                @elseif($area)
                    GS Construction Projects in {{ $area->city }}
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
                    height-classes="gallery-viewport"
                    :images-only="true"
                />
            </div>
        @endif

        {{-- Who we are, above the gallery heading. --}}
        @if($showAbout && $area)
            <div class="mt-10">
                <livewire:about-section :area="$area" :key="'grid-about-'.$area->id" />
            </div>
        @endif

        {{-- Page-authored intro, placed under the hero it refers to. --}}
        @if($introHeading || $introBody)
            <div class="mx-auto mt-10 max-w-3xl text-center">
                @if($introHeading)
                    <h2 class="font-heading text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl dark:text-white">
                        {{ $introHeading }}
                    </h2>
                @endif
                @if($introBody)
                    <p @class(['text-lg text-zinc-600 dark:text-zinc-300', 'mt-4' => (bool) $introHeading])>
                        {{ $introBody }}
                    </p>
                @endif
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
                    @if($emptyService)
                        <p class="text-lg text-zinc-500 dark:text-zinc-400">
                            We haven't posted {{ strtolower($emptyService['label']) }} projects online yet — but it's one of our services.
                        </p>
                        <a
                            href="{{ $emptyService['url'] }}"
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
                {{-- :towns when an area is in scope — the heading now says "in
                     {city}", so any card from elsewhere must show its own town. --}}
                <x-project-grid
                    :projects="$projects"
                    :towns="(bool) $area"
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

        {{-- Reviews, between the grid and the timelapse (main projects page
             only). Gated on !$hideFilters like the hero and timelapse: the
             service and area pages embed this grid and already render their own
             testimonials section right after it, so an ungated one would show
             the block twice on those pages.

             Scoped to the active filter, so filtering to Kitchen shows kitchen
             reviews. The component falls back to any recent review when a type
             has none, so an empty filter never yields an empty section. The key
             includes $type so Livewire swaps the component when the filter
             changes rather than reusing the previous type's reviews. --}}
        @if(!$hideFilters)
            {{-- No bg-*: this page has a tinted/gradient backdrop and the
                 default bg-white punched a white band through it.

                 max-w-none + empty padding because this sits INSIDE the page's
                 own max-w-7xl px-6 lg:px-8 container — with the section's own
                 padding on top the card came out 1152px under a 1216px grid.

                 No overflow-hidden either: with zero padding the card is flush
                 with the section edges, and the card's ring-1/shadow-lg draw
                 OUTSIDE its border box, so hidden clipped them away on the left
                 and right (top and bottom survived on the py-12). Elsewhere the
                 section is full-bleed with a 112px gutter, so it never showed. --}}
            {{-- :area so the heading names the town ("Your Neighbours in
                 Palatine Love Us") and the carousel leads with that town's own
                 reviews before widening outward. --}}
            <livewire:testimonials-section
                :area="$area"
                :project-type="$type"
                section-classes="relative isolate py-12 sm:py-16"
                max-width-class="max-w-none"
                padding-class=""
                :key="'projects-testimonials-'.($area?->id ?? 'all').'-'.($type ?? 'all')" />
        @endif

        {{-- Timelapse Section (main projects page only).
             `! $area` as well as `! $hideFilters`: the area projects page also
             leaves filters visible, so this fired there on top of the one the
             area page renders itself — two timelapses on one page. --}}
        @if(! $hideFilters && ! $area)
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
