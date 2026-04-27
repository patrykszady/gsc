<div class="bg-white dark:bg-zinc-900 overflow-x-hidden">
    {{-- Project Schema with ImageObject data --}}
    <x-project-schema :project="$project" />

    {{-- Breadcrumb Schema --}}
    @php
        $breadcrumbItems = [
            ['name' => 'Projects', 'url' => route('projects.index')],
        ];
        if ($project->project_type) {
            $breadcrumbItems[] = [
                'name' => $projectTypeLabel,
                'url' => route('projects.index', ['type' => $project->project_type]),
            ];
        }
        $breadcrumbItems[] = ['name' => $project->title];
    @endphp
    <x-breadcrumb-schema :items="$breadcrumbItems" />

    {{-- Visual Breadcrumb --}}
    <div class="mx-auto max-w-7xl px-4 py-4 sm:px-6 lg:px-8">
        <nav class="flex" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-2 text-sm">
                <li>
                    <a href="{{ route('home') }}" wire:navigate class="text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Home</a>
                </li>
                <li class="flex items-center">
                    <svg class="h-4 w-4 flex-shrink-0 text-gray-500" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                    </svg>
                    <a href="{{ route('projects.index') }}" wire:navigate class="ml-2 text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Projects</a>
                </li>
                @if($project->project_type)
                <li class="flex items-center">
                    <svg class="h-4 w-4 flex-shrink-0 text-gray-500" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                    </svg>
                    <a href="{{ route('projects.index', ['type' => $project->project_type]) }}" wire:navigate class="ml-2 text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">{{ $projectTypeLabel }}</a>
                </li>
                @endif
                <li class="flex items-center">
                    <svg class="h-4 w-4 flex-shrink-0 text-gray-500" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                    </svg>
                    <span class="ml-2 text-gray-700 dark:text-gray-300">{{ $project->title }}</span>
                </li>
            </ol>
        </nav>
    </div>

    {{-- Main Content --}}
    <div class="mx-auto max-w-7xl px-4 pt-8 pb-3 sm:px-6 lg:px-8 lg:pt-12 lg:pb-4">
        @php
            $images = $project->images->filter()->values();
            $visibleTimelapses = $project->timelapses->filter(fn($t) => $t->frames->isNotEmpty())->values();
        @endphp

        {{-- Project Header --}}
        <div class="mb-8">
            {{-- Project Slideshow --}}
            @if($images->isNotEmpty())
                @php
                    $projectHeroSlides = $images->map(function ($img) use ($project) {
                        $largeUrl = $img->getWebpThumbnailUrl('large') ?? $img->getThumbnailUrl('large') ?? $img->url;
                        $heroUrl = $img->getWebpThumbnailUrl('hero') ?? $img->getThumbnailUrl('hero');
                        $mediumUrl = $img->getWebpThumbnailUrl('medium') ?? $img->getThumbnailUrl('medium');
                        $smallUrl = $img->getWebpThumbnailUrl('small') ?? $img->getThumbnailUrl('small');
                        $thumbUrl = $img->getWebpThumbnailUrl('thumb') ?? $img->getThumbnailUrl('thumb');

                        return [
                            'image' => $heroUrl ?? $largeUrl,
                            'thumb' => $thumbUrl,
                            'srcset' => implode(', ', array_filter([
                                $smallUrl ? "{$smallUrl} 300w" : null,
                                $mediumUrl ? "{$mediumUrl} 600w" : null,
                                $heroUrl ? "{$heroUrl} 1200w" : null,
                                $largeUrl ? "{$largeUrl} 2400w" : null,
                            ])),
                            'imageAlt' => $img->seo_alt_text ?: $img->alt_text ?: $project->title,
                            'projectTitle' => null,
                            'projectUrl' => null,
                        ];
                    })->values()->all();
                @endphp
                <div class="mb-6 overflow-hidden rounded-2xl">
                    <livewire:main-project-hero-slider
                        :custom-slides="$projectHeroSlides"
                        :images-only="true"
                        height-classes="h-[375px] sm:h-[450px] lg:h-[525px]"
                    />
                </div>
            @endif

            <div class="flex flex-wrap items-center gap-3 mb-4">
                <span class="inline-flex items-center rounded-full bg-sky-100 px-3 py-1 text-sm font-medium text-sky-800 dark:bg-sky-900/30 dark:text-sky-300">
                    {{ $projectTypeLabel }}
                </span>
                @if($project->location)
                    <span class="inline-flex items-center text-sm text-gray-500 dark:text-gray-400">
                        <svg class="mr-1.5 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                        @if($locationArea)
                            <a href="{{ route('areas.show', $locationArea) }}" wire:navigate class="hover:text-sky-600 dark:hover:text-sky-400 transition-colors underline decoration-dotted underline-offset-2">
                                {{ $project->location }}
                            </a>
                        @else
                            {{ $project->location }}
                        @endif
                    </span>
                @endif
                @if($project->completed_at)
                    <span class="inline-flex items-center text-sm text-gray-500 dark:text-gray-400">
                        <svg class="mr-1.5 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                        {{ $project->completed_at->format('F Y') }}
                    </span>
                @endif
            </div>
            <h1 class="text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl dark:text-white">
                {{ $project->title }}
            </h1>
            @if($project->description)
                <p class="mt-4 text-lg text-gray-600 dark:text-gray-400">
                    {{ $project->description }}
                </p>
            @endif
            <div class="mt-6">
                <flux:button href="{{ route('projects.index') }}" variant="primary" size="sm">
                    Show More Projects
                </flux:button>
            </div>
        </div>

        @if(false) {{-- Timelapses moved below Project Photos --}}
            <div x-data="{ active: 0 }" x-cloak class="mb-8">
                @if($visibleTimelapses->count() > 1)
                    <div role="tablist" aria-label="Select timelapse" class="mb-4 flex flex-wrap gap-2">
                        @foreach($visibleTimelapses as $tIdx => $t)
                            <button
                                type="button"
                                role="tab"
                                @click="active = {{ $tIdx }}"
                                :aria-selected="active === {{ $tIdx }}"
                                :class="active === {{ $tIdx }}
                                    ? 'bg-sky-600 text-white border-sky-600 shadow-sm'
                                    : 'bg-white text-zinc-700 border-zinc-200 hover:border-zinc-300 hover:text-zinc-900 dark:bg-zinc-900 dark:text-zinc-300 dark:border-zinc-700 dark:hover:text-white'"
                                class="rounded-full border px-4 py-1.5 text-sm font-medium transition"
                            >{{ $t->title ?: 'Timelapse '.($tIdx + 1) }}</button>
                        @endforeach
                    </div>
                @endif

        @foreach($visibleTimelapses as $tIdx => $timelapse)
                @php
                    $frames = $timelapse->frames->sortBy('sort_order')->map(fn($f) => $f->url)->values()->all();
                    $frameCount = max(count($frames), 1);
                    $middleTick = (int) ceil($frameCount / 2);
                    $timelapseTitle = $timelapse->title ?: 'Project Timelapse';
                @endphp
                @php
                    $hasBeforeAfter = count($frames) >= 2;
                    $defaultView = in_array($timelapse->display_mode, ['accordion', 'slider'], true) ? $timelapse->display_mode : 'slider';
                @endphp
                <div
                    x-data="{ view: '{{ $defaultView }}' }"
                    x-show="active === {{ $tIdx }}"
                    x-cloak
                    role="tabpanel"
                    class="mb-8 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 sm:p-5"
                >
                    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">{{ $timelapseTitle }}</h2>

                        {{-- View toggle --}}
                        <div role="tablist" aria-label="Timelapse view mode" class="inline-flex self-start rounded-lg border border-zinc-200 bg-zinc-50 p-1 dark:border-zinc-700 dark:bg-zinc-800">
                            <button
                                type="button"
                                role="tab"
                                @click="view = 'slider'"
                                :aria-selected="view === 'slider'"
                                :class="view === 'slider'
                                    ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white'
                                    : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white'"
                                class="rounded-md px-3 py-1.5 text-sm font-medium transition"
                            >Slider</button>
                            <button
                                type="button"
                                role="tab"
                                @click="view = 'accordion'"
                                :aria-selected="view === 'accordion'"
                                :class="view === 'accordion'
                                    ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white'
                                    : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white'"
                                class="rounded-md px-3 py-1.5 text-sm font-medium transition"
                            >Accordion</button>
                            @if($hasBeforeAfter)
                                <button
                                    type="button"
                                    role="tab"
                                    @click="view = 'before-after'"
                                    :aria-selected="view === 'before-after'"
                                    :class="view === 'before-after'
                                        ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white'
                                        : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white'"
                                    class="rounded-md px-3 py-1.5 text-sm font-medium transition"
                                >Before &amp; After</button>
                            @endif
                        </div>
                    </div>

                    {{-- Slider Panel --}}
                    <div x-show="view === 'slider'" role="tabpanel">
                        <livewire:timelapse-section :timelapse-id="$timelapse->id" :key="'timelapse-'.$timelapse->id" />
                    </div>

                    {{-- Accordion Panel --}}
                    <div x-show="view === 'accordion'" x-cloak role="tabpanel">
                        <section
                            x-data="{ active: null }"
                            class="relative w-full overflow-hidden rounded-2xl bg-zinc-100 dark:bg-zinc-800"
                        >
                            <div class="relative h-[375px] sm:h-[450px] lg:h-[525px] flex">
                                @foreach($frames as $fIdx => $frameUrl)
                                    <div
                                        class="relative h-full overflow-hidden border-r border-white/20 last:border-r-0 transition-all duration-500 ease-in-out cursor-pointer"
                                        :class="active === {{ $fIdx }} ? 'flex-[8]' : (active === null ? 'flex-1' : 'flex-[0.3]')"
                                        @mouseenter="active = {{ $fIdx }}"
                                        @mouseleave="active = null"
                                    >
                                        <img
                                            src="{{ $frameUrl }}"
                                            alt="{{ $project->title }} — frame {{ $fIdx + 1 }}"
                                            class="absolute inset-0 h-full w-full object-cover"
                                            style="object-position: {{ count($frames) > 1 ? round($fIdx / (count($frames) - 1) * 100, 2) : 50 }}% center"
                                            loading="lazy"
                                        />
                                        <div class="absolute inset-0 transition-colors duration-300"
                                            :class="active === {{ $fIdx }} ? 'bg-black/10' : 'bg-black/30'"
                                        ></div>

                                        {{-- Frame label --}}
                                        <div class="absolute inset-x-0 bottom-0 z-10 p-3 text-center">
                                            <span
                                                class="inline-block rounded-full bg-black/60 px-3 py-1 text-xs font-medium text-white backdrop-blur-sm transition-opacity duration-300"
                                                :class="active !== null && active !== {{ $fIdx }} ? 'opacity-0' : 'opacity-100'"
                                            >
                                                @if($fIdx === 0)
                                                    Before
                                                @elseif($fIdx === count($frames) - 1)
                                                    After
                                                @else
                                                    {{ $fIdx + 1 }}
                                                @endif
                                            </span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </section>
                    </div>

                    {{-- Before / After Panel --}}
                    @if($hasBeforeAfter)
                        @php $firstFrame = $frames[0]; $lastFrame = $frames[count($frames) - 1]; @endphp
                        <div x-show="view === 'before-after'" x-cloak role="tabpanel">
                            <section
                                x-data="{
                                    position: 50,
                                    dragging: false,
                                    updatePosition(clientX) {
                                        const rect = this.$refs.tlBaContainer.getBoundingClientRect();
                                        const x = clientX - rect.left;
                                        this.position = Math.max(0, Math.min(100, (x / rect.width) * 100));
                                    },
                                    onPointerDown(e) {
                                        this.dragging = true;
                                        this.$refs.tlBaContainer.setPointerCapture(e.pointerId);
                                        this.updatePosition(e.clientX);
                                        e.preventDefault();
                                    },
                                    onPointerMove(e) { if (!this.dragging) return; this.updatePosition(e.clientX); },
                                    onPointerUp(e) {
                                        if (!this.dragging) return;
                                        this.dragging = false;
                                        this.$refs.tlBaContainer.releasePointerCapture(e.pointerId);
                                    },
                                }"
                                class="relative select-none"
                            >
                                <div
                                    x-ref="tlBaContainer"
                                    @pointerdown="onPointerDown($event)"
                                    @pointermove="onPointerMove($event)"
                                    @pointerup="onPointerUp($event)"
                                    @pointercancel="onPointerUp($event)"
                                    class="relative h-[375px] w-full overflow-hidden rounded-2xl bg-zinc-100 dark:bg-zinc-800 cursor-col-resize sm:h-[450px] lg:h-[525px]" style="touch-action: none;"
                                >
                                    <img src="{{ $lastFrame }}" alt="{{ $timelapseTitle }} — After" class="absolute inset-0 h-full w-full object-cover" loading="lazy" />

                                    <div class="absolute inset-0 overflow-hidden" :style="'clip-path: inset(0 ' + (100 - position) + '% 0 0)'">
                                        <img src="{{ $firstFrame }}" alt="{{ $timelapseTitle }} — Before" class="absolute inset-0 h-full w-full object-cover" loading="lazy" />
                                    </div>

                                    <div class="absolute inset-y-0 z-10 flex items-center" :style="'left: ' + position + '%'">
                                        <div class="relative -ml-px h-full w-0.5 bg-white shadow-md">
                                            <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 flex size-10 items-center justify-center rounded-full bg-white shadow-lg ring-2 ring-white/80">
                                                <svg class="size-5 text-zinc-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <polyline points="15 18 9 12 15 6"></polyline>
                                                </svg>
                                                <svg class="size-5 -ml-1 text-zinc-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                                    <polyline points="9 18 15 12 9 6"></polyline>
                                                </svg>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="pointer-events-none absolute inset-x-0 bottom-4 z-10 flex justify-between px-4">
                                        <span class="rounded-full bg-black/60 px-3 py-1 text-sm font-medium text-white backdrop-blur-sm" x-show="position > 10" x-transition>Before</span>
                                        <span class="rounded-full bg-black/60 px-3 py-1 text-sm font-medium text-white backdrop-blur-sm" x-show="position < 90" x-transition>After</span>
                                    </div>
                                </div>
                            </section>
                        </div>
                    @endif
                </div>
        @endforeach
            </div>
        @endif

        {{-- Before / After Comparisons --}}
        @foreach($project->beforeAfters as $ba)
            <div class="mb-8">
                @if($ba->title)
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">{{ $ba->title }}</h2>
                @else
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">Before &amp; After</h2>
                @endif

                <section
                    x-data="{
                        position: 50,
                        dragging: false,
                        containerWidth: 0,
                        beforeLoaded: false,
                        afterLoaded: false,

                        get ready() { return this.beforeLoaded && this.afterLoaded; },

                        updatePosition(clientX) {
                            const rect = this.$refs.container.getBoundingClientRect();
                            const x = clientX - rect.left;
                            this.position = Math.max(0, Math.min(100, (x / rect.width) * 100));
                        },

                        onPointerDown(e) {
                            this.dragging = true;
                            this.$refs.container.setPointerCapture(e.pointerId);
                            this.updatePosition(e.clientX);
                            e.preventDefault();
                        },

                        onPointerMove(e) {
                            if (!this.dragging) return;
                            this.updatePosition(e.clientX);
                        },

                        onPointerUp(e) {
                            if (!this.dragging) return;
                            this.dragging = false;
                            this.$refs.container.releasePointerCapture(e.pointerId);
                        },
                    }"
                    class="relative select-none"
                >
                    <div
                        x-ref="container"
                        @pointerdown="onPointerDown($event)"
                        @pointermove="onPointerMove($event)"
                        @pointerup="onPointerUp($event)"
                        @pointercancel="onPointerUp($event)"
                        class="relative h-[375px] w-full overflow-hidden rounded-2xl bg-zinc-100 dark:bg-zinc-800 cursor-col-resize sm:h-[450px] lg:h-[525px]" style="touch-action: none;"
                        :class="ready ? '' : 'animate-pulse'"
                    >
                        {{-- After Image (background, full width) --}}
                        <img
                            x-ref="afterImg"
                            src="{{ $ba->after_url }}"
                            alt="{{ $ba->title ? $ba->title . ' — After' : 'After' }}"
                            x-init="if ($refs.afterImg.complete && $refs.afterImg.naturalWidth) afterLoaded = true"
                            @load="afterLoaded = true"
                            class="absolute inset-0 h-full w-full object-cover"
                            :class="afterLoaded ? 'opacity-100' : 'opacity-0'"
                        />

                        {{-- Before Image (clipped overlay) --}}
                        <div
                            class="absolute inset-0 overflow-hidden"
                            :style="'clip-path: inset(0 ' + (100 - position) + '% 0 0)'"
                        >
                            <img
                                x-ref="beforeImg"
                                src="{{ $ba->before_url }}"
                                alt="{{ $ba->title ? $ba->title . ' — Before' : 'Before' }}"
                                x-init="if ($refs.beforeImg.complete && $refs.beforeImg.naturalWidth) beforeLoaded = true"
                                @load="beforeLoaded = true"
                                class="absolute inset-0 h-full w-full object-cover"
                            />
                        </div>

                        {{-- Divider Line --}}
                        <div
                            class="absolute inset-y-0 z-10 flex items-center"
                            :style="'left: ' + position + '%'"
                        >
                            <div class="relative -ml-px h-full w-0.5 bg-white shadow-md">
                                {{-- Handle --}}
                                <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 flex size-10 items-center justify-center rounded-full bg-white shadow-lg ring-2 ring-white/80">
                                    <svg class="size-5 text-zinc-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="15 18 9 12 15 6"></polyline>
                                    </svg>
                                    <svg class="size-5 -ml-1 text-zinc-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="9 18 15 12 9 6"></polyline>
                                    </svg>
                                </div>
                            </div>
                        </div>

                        {{-- Labels --}}
                        <div class="pointer-events-none absolute inset-x-0 bottom-4 z-10 flex justify-between px-4">
                            <span
                                class="rounded-full bg-black/60 px-3 py-1 text-sm font-medium text-white backdrop-blur-sm"
                                x-show="position > 10"
                                x-transition
                            >Before</span>
                            <span
                                class="rounded-full bg-black/60 px-3 py-1 text-sm font-medium text-white backdrop-blur-sm"
                                x-show="position < 90"
                                x-transition
                            >After</span>
                        </div>
                    </div>
                </section>
            </div>
        @endforeach

        {{-- Image Gallery with Lightbox --}}
        @php $perPage = 6; $totalPages = max(1, (int) ceil($images->count() / $perPage)); $imagePages = $images->chunk($perPage); @endphp
        @if($images->isNotEmpty())
            <div x-data="{
                page: 0,
                totalPages: {{ $totalPages }},
                changing: false,
                setPage(nextPage) {
                    if (nextPage < 0 || nextPage >= this.totalPages || nextPage === this.page) return;
                    this.changing = true;
                    setTimeout(() => {
                        this.page = nextPage;
                        requestAnimationFrame(() => {
                            this.changing = false;
                        });
                    }, 70);
                },
            }" x-cloak>
            {{-- Gallery header with link to full photos --}}
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-xl font-semibold text-gray-900 dark:text-white">
                    Project Photos
                    <span class="text-base font-normal text-gray-500 dark:text-gray-400">({{ $images->count() }})</span>
                </h2>
                @php
                    $firstImage = $images->first();
                    $firstImageKey = $firstImage?->id;
                @endphp
                @if($firstImageKey)
                    <a href="{{ route('projects.image', ['project' => $project, 'image' => $firstImageKey]) }}" 
                       wire:navigate
                       class="inline-flex items-center gap-2 text-sm font-medium text-sky-600 hover:text-sky-700 dark:text-sky-400 dark:hover:text-sky-300 transition-colors">
                        View full-size gallery
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                        </svg>
                    </a>
                @endif
            </div>
            <div x-data="{ 
                lightbox: false, 
                currentIndex: 0,
                images: {{ Js::from($images->map(function ($img) use ($project) {
                    $imageKey = $img->id;

                    return [
                        'id' => $img->id,
                        'url' => $img->getThumbnailUrl('large'),
                        'webpUrl' => $img->getWebpThumbnailUrl('large'),
                        'originalUrl' => $img->url,
                        'alt' => $img->alt_text ?: $img->seo_alt_text,
                        'caption' => $img->caption,
                        'pageUrl' => $imageKey ? route('projects.image', ['project' => $project, 'image' => $imageKey]) : null,
                    ];
                })) }},
                open(index) { 
                    this.currentIndex = index; 
                    this.lightbox = true; 
                    document.body.style.overflow = 'hidden';
                },
                close() { 
                    this.lightbox = false; 
                    document.body.style.overflow = '';
                },
                next() { this.currentIndex = (this.currentIndex + 1) % this.images.length; },
                prev() { this.currentIndex = (this.currentIndex - 1 + this.images.length) % this.images.length; },
                get current() { return this.images[this.currentIndex]; }
            }"
            @keydown.escape.window="close()"
            @keydown.arrow-right.window="if(lightbox) next()"
            @keydown.arrow-left.window="if(lightbox) prev()">
                
                {{-- Gallery Grid --}}
                <div @class([
                    'min-h-[18rem] sm:min-h-[30rem] lg:min-h-[38rem]' => $totalPages > 1,
                ])>
                    @foreach($imagePages as $pageIndex => $pageImages)
                        <div
                            x-show="page === {{ $pageIndex }}"
                            x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="opacity-0 translate-y-1"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            x-transition:leave="transition ease-in duration-120"
                            x-transition:leave-start="opacity-100 translate-y-0"
                            x-transition:leave-end="opacity-0 -translate-y-1"
                            class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
                            role="tabpanel"
                        >
                            @foreach($pageImages as $imageIndex => $image)
                                @php $globalIndex = ($pageIndex * $perPage) + $imageIndex; @endphp
                                <div
                                    x-data="{
                                        showCaption: false,
                                        lastInputWasTouch: false
                                    }"
                                    class="relative"
                                >
                                    <div
                                        @touchstart="lastInputWasTouch = true"
                                        @mouseenter="if (!lastInputWasTouch) showCaption = true"
                                        @mouseleave="showCaption = false; lastInputWasTouch = false"
                                        @click="
                                            if (lastInputWasTouch) {
                                                if (showCaption) {
                                                    open({{ $globalIndex }});
                                                    showCaption = false;
                                                } else {
                                                    showCaption = true;
                                                }
                                            } else {
                                                open({{ $globalIndex }});
                                            }
                                        "
                                        @click.outside="showCaption = false; lastInputWasTouch = false"
                                        class="group relative aspect-[4/3] overflow-hidden rounded-xl bg-gray-100 dark:bg-zinc-800 cursor-pointer"
                                    >
                                        <x-lqip-image
                                            :image="$image"
                                            size="large"
                                            aspectRatio="4/3"
                                            class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                                        />

                                        {{-- Caption overlay --}}
                                        <div
                                            x-show="showCaption"
                                            x-transition:enter="transition ease-out duration-200"
                                            x-transition:enter-start="opacity-0"
                                            x-transition:enter-end="opacity-100"
                                            x-transition:leave="transition ease-in duration-150"
                                            x-transition:leave-start="opacity-100"
                                            x-transition:leave-end="opacity-0"
                                            class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/20 to-transparent flex flex-col justify-end p-4"
                                        >
                                            {{-- Caption text --}}
                                            @if($image->caption)
                                                <p class="text-sm text-white leading-relaxed line-clamp-3">{{ $image->caption }}</p>
                                            @endif

                                            {{-- Centered zoom icon (outline only) --}}
                                            <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
                                                <svg class="h-10 w-10 text-white drop-shadow-lg" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607zM10.5 7.5v6m3-3h-6" />
                                                </svg>
                                            </div>
                                        </div>

                                        {{-- Featured badge --}}
                                        @if($image->is_cover)
                                            <span class="absolute top-3 left-3 inline-flex items-center rounded-full bg-sky-500 px-2.5 py-0.5 text-xs font-medium text-white shadow-sm z-10">
                                                Featured
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>

                {{-- Pager --}}
                @if($totalPages > 1)
                    <nav aria-label="Project photos pagination" class="mt-6 flex items-center justify-center gap-2">
                        <button
                            type="button"
                            @click.prevent="setPage(page - 1)"
                            :disabled="page === 0"
                            class="inline-flex items-center gap-1 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-sm font-medium text-zinc-700 shadow-sm transition hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800"
                        >
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                            Prev
                        </button>
                        <template x-for="i in totalPages" :key="i">
                            <button
                                type="button"
                                @click.prevent="setPage(i - 1)"
                                :class="page === (i - 1)
                                    ? 'bg-sky-600 text-white border-sky-600 shadow-sm'
                                    : 'bg-white text-zinc-700 border-zinc-200 hover:bg-zinc-50 dark:bg-zinc-900 dark:text-zinc-300 dark:border-zinc-700 dark:hover:bg-zinc-800'"
                                class="inline-flex h-8 min-w-[2rem] items-center justify-center rounded-lg border px-2.5 text-sm font-medium transition"
                                x-text="i"
                            ></button>
                        </template>
                        <button
                            type="button"
                            @click.prevent="setPage(page + 1)"
                            :disabled="page === totalPages - 1"
                            class="inline-flex items-center gap-1 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-sm font-medium text-zinc-700 shadow-sm transition hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-40 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800"
                        >
                            Next
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </button>
                    </nav>
                @endif

                {{-- Lightbox Component --}}
                <x-lightbox />
            </div>
            </div>
        @endif

        {{-- Project Timelapses (below Project Photos) --}}
        @if($visibleTimelapses->isNotEmpty())
            <div x-data="{ active: 0 }" x-cloak class="mt-8 mb-8">
                @foreach($visibleTimelapses as $tIdx => $timelapse)
                    @php
                        $frames = $timelapse->frames->sortBy('sort_order')->map(fn($f) => $f->url)->values()->all();
                        $timelapseTitle = $timelapse->title ?: 'Project Timelapse';
                        $hasBeforeAfter = count($frames) >= 2;
                        $defaultView = in_array($timelapse->display_mode, ['accordion', 'slider'], true) ? $timelapse->display_mode : 'slider';
                    @endphp
                    <div
                        x-data="{ view: '{{ $defaultView }}' }"
                        x-show="active === {{ $tIdx }}"
                        x-cloak
                        role="tabpanel"
                        class="mb-8 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 sm:p-5"
                    >
                        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            @if($visibleTimelapses->count() > 1)
                                <div role="tablist" aria-label="Select timelapse" class="flex flex-wrap gap-2">
                                    @foreach($visibleTimelapses as $tIdx2 => $t)
                                        <button
                                            type="button"
                                            role="tab"
                                            @click="active = {{ $tIdx2 }}"
                                            :aria-selected="active === {{ $tIdx2 }}"
                                            :class="active === {{ $tIdx2 }}
                                                ? 'bg-sky-600 text-white border-sky-600 shadow-sm'
                                                : 'bg-white text-zinc-700 border-zinc-200 hover:border-zinc-300 hover:text-zinc-900 dark:bg-zinc-900 dark:text-zinc-300 dark:border-zinc-700 dark:hover:text-white'"
                                            class="rounded-full border px-4 py-1.5 text-sm font-medium transition"
                                        >{{ $t->title ?: 'Timelapse '.($tIdx2 + 1) }}</button>
                                    @endforeach
                                </div>
                            @else
                                <h2 class="text-xl font-semibold text-gray-900 dark:text-white">{{ $timelapseTitle }}</h2>
                            @endif

                            <div role="tablist" aria-label="Timelapse view mode" class="inline-flex self-start rounded-lg border border-zinc-200 bg-zinc-50 p-1 dark:border-zinc-700 dark:bg-zinc-800">
                                <button type="button" role="tab" @click="view = 'slider'" :aria-selected="view === 'slider'"
                                    :class="view === 'slider' ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white'"
                                    class="rounded-md px-3 py-1.5 text-sm font-medium transition">Slider</button>
                                <button type="button" role="tab" @click="view = 'accordion'" :aria-selected="view === 'accordion'"
                                    :class="view === 'accordion' ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white'"
                                    class="rounded-md px-3 py-1.5 text-sm font-medium transition">Accordion</button>
                                @if($hasBeforeAfter)
                                    <button type="button" role="tab" @click="view = 'before-after'" :aria-selected="view === 'before-after'"
                                        :class="view === 'before-after' ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-white' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white'"
                                        class="rounded-md px-3 py-1.5 text-sm font-medium transition">Before &amp; After</button>
                                @endif
                            </div>
                        </div>

                        {{-- Slider Panel --}}
                        <div x-show="view === 'slider'" role="tabpanel">
                            <livewire:timelapse-section :timelapse-id="$timelapse->id" :key="'timelapse-'.$timelapse->id" />
                        </div>

                        {{-- Accordion Panel --}}
                        <div x-show="view === 'accordion'" x-cloak role="tabpanel">
                            <section x-data="{ active: null }" class="relative w-full overflow-hidden rounded-2xl bg-zinc-100 dark:bg-zinc-800">
                                <div class="relative h-[375px] sm:h-[450px] lg:h-[525px] flex">
                                    @foreach($frames as $fIdx => $frameUrl)
                                        <div
                                            class="relative h-full overflow-hidden border-r border-white/20 last:border-r-0 transition-all duration-500 ease-in-out cursor-pointer"
                                            :class="active === {{ $fIdx }} ? 'flex-[8]' : (active === null ? 'flex-1' : 'flex-[0.3]')"
                                            @mouseenter="active = {{ $fIdx }}"
                                            @mouseleave="active = null"
                                        >
                                            <img src="{{ $frameUrl }}" alt="{{ $project->title }} — frame {{ $fIdx + 1 }}"
                                                class="absolute inset-0 h-full w-full object-cover"
                                                style="object-position: {{ count($frames) > 1 ? round($fIdx / (count($frames) - 1) * 100, 2) : 50 }}% center"
                                                loading="lazy" />
                                            <div class="absolute inset-0 transition-colors duration-300" :class="active === {{ $fIdx }} ? 'bg-black/10' : 'bg-black/30'"></div>
                                            <div class="absolute inset-x-0 bottom-0 z-10 p-3 text-center">
                                                <span class="inline-block rounded-full bg-black/60 px-3 py-1 text-xs font-medium text-white backdrop-blur-sm transition-opacity duration-300"
                                                    :class="active !== null && active !== {{ $fIdx }} ? 'opacity-0' : 'opacity-100'">
                                                    @if($fIdx === 0) Before
                                                    @elseif($fIdx === count($frames) - 1) After
                                                    @else {{ $fIdx + 1 }}
                                                    @endif
                                                </span>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </section>
                        </div>

                        {{-- Before / After Panel --}}
                        @if($hasBeforeAfter)
                            @php $firstFrame = $frames[0]; $lastFrame = $frames[count($frames) - 1]; @endphp
                            <div x-show="view === 'before-after'" x-cloak role="tabpanel">
                                <section
                                    x-data="{
                                        position: 50,
                                        dragging: false,
                                        updatePosition(clientX) {
                                            const rect = this.$refs.tlBaContainer.getBoundingClientRect();
                                            const x = clientX - rect.left;
                                            this.position = Math.max(0, Math.min(100, (x / rect.width) * 100));
                                        },
                                        onPointerDown(e) {
                                            this.dragging = true;
                                            this.$refs.tlBaContainer.setPointerCapture(e.pointerId);
                                            this.updatePosition(e.clientX);
                                            e.preventDefault();
                                        },
                                        onPointerMove(e) { if (!this.dragging) return; this.updatePosition(e.clientX); },
                                        onPointerUp(e) {
                                            if (!this.dragging) return;
                                            this.dragging = false;
                                            this.$refs.tlBaContainer.releasePointerCapture(e.pointerId);
                                        },
                                    }"
                                    class="relative select-none"
                                >
                                    <div
                                        x-ref="tlBaContainer"
                                        @pointerdown="onPointerDown($event)"
                                        @pointermove="onPointerMove($event)"
                                        @pointerup="onPointerUp($event)"
                                        @pointercancel="onPointerUp($event)"
                                        class="relative h-[375px] w-full overflow-hidden rounded-2xl bg-zinc-100 dark:bg-zinc-800 cursor-col-resize sm:h-[450px] lg:h-[525px]" style="touch-action: none;"
                                    >
                                        <img src="{{ $lastFrame }}" alt="{{ $timelapseTitle }} — After" class="absolute inset-0 h-full w-full object-cover" loading="lazy" />
                                        <div class="absolute inset-0 overflow-hidden" :style="'clip-path: inset(0 ' + (100 - position) + '% 0 0)'">
                                            <img src="{{ $firstFrame }}" alt="{{ $timelapseTitle }} — Before" class="absolute inset-0 h-full w-full object-cover" loading="lazy" />
                                        </div>
                                        <div class="absolute inset-y-0 z-10 flex items-center" :style="'left: ' + position + '%'">
                                            <div class="relative -ml-px h-full w-0.5 bg-white shadow-md">
                                                <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 flex size-10 items-center justify-center rounded-full bg-white shadow-lg ring-2 ring-white/80">
                                                    <svg class="size-5 text-zinc-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"></polyline></svg>
                                                    <svg class="size-5 -ml-1 text-zinc-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"></polyline></svg>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="pointer-events-none absolute inset-x-0 bottom-4 z-10 flex justify-between px-4">
                                            <span class="rounded-full bg-black/60 px-3 py-1 text-sm font-medium text-white backdrop-blur-sm" x-show="position > 10" x-transition>Before</span>
                                            <span class="rounded-full bg-black/60 px-3 py-1 text-sm font-medium text-white backdrop-blur-sm" x-show="position < 90" x-transition>After</span>
                                        </div>
                                    </div>
                                </section>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Related Projects --}}
    @if($relatedProjects->isNotEmpty())
        <div class="border-t border-gray-200 dark:border-zinc-700">
            <div class="mx-auto max-w-7xl px-4 py-12 sm:px-6 lg:px-8 lg:py-16">
                <h2 class="text-2xl font-bold tracking-tight text-gray-900 dark:text-white mb-8">
                    @php
                        $sameTypeCount = $relatedProjects->where('project_type', $project->project_type)->count();
                    @endphp
                    {{ $sameTypeCount === $relatedProjects->count() ? 'More ' . $projectTypeLabel . ' Projects' : 'More Remodeling Projects' }}
                </h2>
                <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach($relatedProjects as $related)
                        <a href="{{ route('projects.show', $related) }}" wire:navigate class="group block">
                            <div class="relative aspect-[4/3] overflow-hidden rounded-xl bg-gray-100 dark:bg-zinc-800">
                                @if($related->images->first())
                                    <x-lqip-image 
                                        :image="$related->images->first()"
                                        size="medium"
                                        aspectRatio="4/3"
                                        class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-105"
                                    />
                                @endif
                            </div>
                            <h3 class="mt-3 text-lg font-semibold text-gray-900 group-hover:text-sky-600 dark:text-white dark:group-hover:text-sky-400">
                                {{ $related->title }}
                            </h3>
                            @if($related->location)
                                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $related->location }}</p>
                            @endif
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    {{-- CTA Section --}}
    <div class="mx-auto max-w-7xl px-4 pt-1 pb-2 sm:px-6 sm:pt-2 sm:pb-3 lg:px-8 lg:pt-3 lg:pb-4">
        <div class="overflow-hidden rounded-2xl shadow-sm">
            <x-cta-section
                variant="blue"
                heading="Ready to Start Your Project?"
                description="Get a free consultation and quote for your remodeling project. We're ready to bring your vision to life."
                primaryCtaText="Get a Free Quote"
                primaryCtaUrl="/contact"
                secondaryCtaText="View All Projects"
                secondaryCtaUrl="/projects"
            />
        </div>
    </div>

    {{-- FAQ Section --}}
    <x-faq-section :faqs="$faqs" :heading="$projectTypeLabel . ' FAQ'" sectionClasses="bg-white pt-1 pb-12 sm:pt-2 sm:pb-16 dark:bg-zinc-900" />
</div>
