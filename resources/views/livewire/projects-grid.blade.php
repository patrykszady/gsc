<div 
    class="relative isolate bg-white py-10 sm:py-16 dark:bg-zinc-900"
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
        <div class="mx-auto max-w-2xl text-center">
            <p class="text-sm font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400">Our Work</p>
            @php
                $typeLabels = [
                    'kitchen' => 'Kitchen',
                    'bathroom' => 'Bathroom',
                    'home-remodel' => 'Home Remodeling',
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
            <p class="mt-4 text-lg text-zinc-600 dark:text-zinc-300">
                @if($area && $typeLabel)
                    Browse our {{ strtolower($typeLabel) }} remodeling projects completed in {{ $area->city }}. See the quality craftsmanship our family brings to every {{ strtolower($typeLabel) }} project.
                @elseif($area)
                    Browse GS Construction's portfolio of completed home remodeling projects in {{ $area->city }} and surrounding areas. From kitchens to bathrooms, see the quality craftsmanship our family brings to every project.
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
                class="rounded-full px-4 py-2 text-sm font-medium transition {{ !$type ? 'bg-sky-500 text-white' : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700' }}"
            >
                All
            </button>
            @foreach($projectTypes as $projectType)
            <button
                wire:click="filterByType('{{ $projectType }}')"
                class="rounded-full px-4 py-2 text-sm font-medium transition {{ $type === $projectType ? 'bg-sky-500 text-white' : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700' }}"
            >
                {{ ucfirst($projectType) }}
            </button>
            @endforeach
        </div>
        @endif

        {{-- Projects Grid --}}
        <div class="mx-auto mt-10 grid max-w-2xl grid-cols-1 gap-6 sm:grid-cols-2 lg:mx-0 lg:max-w-none lg:grid-cols-3">
            @forelse($projects as $project)
            <a href="{{ route('projects.show', $project) }}" wire:navigate class="group relative flex flex-col overflow-hidden rounded-2xl bg-white shadow-lg ring-1 ring-zinc-900/5 transition hover:shadow-xl dark:bg-zinc-800/75 dark:ring-white/10{{ $responsivePerPage && $loop->index >= ($mobilePerPage ?? 999) ? ' hidden sm:flex' : '' }}">
                {{-- Project Image --}}
                <div class="relative aspect-[4/3] overflow-hidden">
                    @if($project->images->first())
                    <x-lqip-image 
                        :image="$project->images->first()"
                        size="medium"
                        width="600"
                        height="450"
                        class="h-full w-full transition duration-300 group-hover:scale-105"
                    />
                    @else
                    <div class="flex h-full w-full items-center justify-center bg-zinc-100 dark:bg-zinc-700">
                        <svg class="h-12 w-12 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" />
                        </svg>
                    </div>
                    @endif

                    {{-- Featured badge --}}
                    @if($project->is_featured)
                    <div class="absolute top-3 left-3">
                        <span class="inline-flex items-center rounded-full bg-sky-500 px-2.5 py-1 text-xs font-medium text-white">
                            Featured
                        </span>
                    </div>
                    @endif

                    {{-- Project type badge --}}
                    @if($project->project_type)
                    <div class="absolute top-3 right-3">
                        <span class="inline-flex items-center rounded-full bg-white/90 px-2.5 py-1 text-xs font-medium text-zinc-700 backdrop-blur dark:bg-zinc-900/90 dark:text-zinc-300">
                            {{ ucfirst($project->project_type) }}
                        </span>
                    </div>
                    @endif
                </div>

                {{-- Project Info --}}
                <div class="flex flex-1 flex-col p-5">
                    <h3 class="font-heading text-lg font-semibold text-zinc-900 dark:text-white">
                        {{ $project->title }}
                    </h3>

                    @if($project->location)
                    <span class="sr-only">Project location: {{ $project->location }}</span>
                    @endif

                    @if($project->description && $hideFilters)
                    <p class="mt-3 line-clamp-2 flex-1 text-sm text-zinc-600 dark:text-zinc-400">
                        {{ $project->description }}
                    </p>
                    @endif

                    @if($project->completed_at)
                    <span class="sr-only">Project completed {{ $project->completed_at->format('F Y') }}</span>
                    @endif

                    {{-- View Project Link --}}
                    <p class="mt-3 text-sm font-medium text-sky-600 group-hover:text-sky-500 dark:text-sky-400 dark:group-hover:text-sky-300">
                        View Project →
                    </p>
                </div>
            </a>
            @empty
            <div class="col-span-full py-12 text-center">
                <p class="text-lg text-zinc-500 dark:text-zinc-400">No projects found.</p>
            </div>
            @endforelse
        </div>

        {{-- Pagination --}}
        @if($projects->hasPages() && $showPagination)
        @php
            $startPage = max(1, $projects->currentPage() - 2);
            $endPage = min($projects->lastPage(), $projects->currentPage() + 2);
        @endphp
        <div class="mt-10 flex items-center justify-between gap-3 sm:justify-end">
            <flux:button
                type="button"
                size="sm"
                variant="ghost"
                wire:click="previousPage"
                :disabled="$projects->onFirstPage()"
            >
                &larr;
            </flux:button>

            <div class="hidden items-center gap-2 sm:flex">
                @if ($startPage > 1)
                    <flux:button type="button" size="sm" variant="ghost" wire:click="gotoPage(1)">1</flux:button>
                    @if ($startPage > 2)
                        <span class="px-2 text-sm text-zinc-400 dark:text-zinc-500">...</span>
                    @endif
                @endif

                @foreach (range($startPage, $endPage) as $page)
                    <flux:button
                        type="button"
                        size="sm"
                        variant="{{ $page === $projects->currentPage() ? 'primary' : 'ghost' }}"
                        wire:click="gotoPage({{ $page }})"
                    >
                        {{ $page }}
                    </flux:button>
                @endforeach

                @if ($endPage < $projects->lastPage())
                    @if ($endPage < $projects->lastPage() - 1)
                        <span class="px-2 text-sm text-zinc-400 dark:text-zinc-500">...</span>
                    @endif
                    <flux:button type="button" size="sm" variant="ghost" wire:click="gotoPage({{ $projects->lastPage() }})">{{ $projects->lastPage() }}</flux:button>
                @endif
            </div>

            <flux:button
                type="button"
                size="sm"
                variant="ghost"
                wire:click="nextPage"
                :disabled="! $projects->hasMorePages()"
            >
                &rarr;
            </flux:button>
        </div>
        @endif

        {{-- Timelapse Section (main projects page only) --}}
        @if(!$hideFilters)
            <div class="mt-10">
                <livewire:timelapse-section :timelapse-id="$randomTimelapseId" :key="'projects-timelapse-'.($randomTimelapseId ?? 'fallback')" />
            </div>
        @endif
    </div>
</div>
