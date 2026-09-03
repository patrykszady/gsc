{{-- The mid-post gallery: one page of 3 columns × 2 rows when the project has
     six or more photos, a single row of three when it has fewer. Anything
     beyond a page is reached with the arrows; every tile opens the lightbox. --}}
@php
    $images = ($images ?? $project->images)->values();
    $per = $images->count() >= 6 ? 6 : 3;
    $chunks = $images->chunk($per)->values();
    $paged = $chunks->count() > 1;
@endphp
@if ($images->isNotEmpty())
    <div class="not-prose clear-both my-8" x-data="{ page: 0, pages: {{ $chunks->count() }} }" @if ($paged) @keydown.arrow-right="if (!lightbox) page = (page + 1) % pages" @keydown.arrow-left="if (!lightbox) page = (page - 1 + pages) % pages" tabindex="0" aria-roledescription="carousel" @endif>
        <div class="relative">
            @foreach ($chunks as $ci => $chunk)
                <div class="grid grid-cols-3 gap-3" x-show="page === {{ $ci }}" @if ($ci > 0) x-cloak @endif x-transition.opacity.duration.200ms>
                    @foreach ($chunk as $img)
                        <button type="button" @click="open({{ \App\Support\Blog\BlogRenderer::lightboxIndex($project, $img) }})" class="group block overflow-hidden rounded-xl text-left" aria-label="Open photo">
                            <x-lqip-image :image="$img" size="medium" width="600" height="450" aspectRatio="4/3" rounded="xl" class="w-full transition duration-300 group-hover:scale-105" />
                        </button>
                    @endforeach
                </div>
            @endforeach

            @if ($paged)
                <button type="button" @click="page = (page - 1 + pages) % pages" class="absolute top-1/2 -left-3 z-10 flex size-10 -translate-y-1/2 items-center justify-center rounded-full bg-white/95 text-zinc-800 shadow-lg ring-1 ring-zinc-900/10 transition hover:bg-white sm:-left-5 dark:bg-zinc-800 dark:text-white dark:ring-white/10" aria-label="Previous photos">
                    <flux:icon.chevron-left class="size-5" />
                </button>
                <button type="button" @click="page = (page + 1) % pages" class="absolute top-1/2 -right-3 z-10 flex size-10 -translate-y-1/2 items-center justify-center rounded-full bg-white/95 text-zinc-800 shadow-lg ring-1 ring-zinc-900/10 transition hover:bg-white sm:-right-5 dark:bg-zinc-800 dark:text-white dark:ring-white/10" aria-label="Next photos">
                    <flux:icon.chevron-right class="size-5" />
                </button>
            @endif
        </div>

        @if ($paged)
            <div class="mt-3 flex items-center justify-center gap-2" aria-hidden="true">
                @foreach ($chunks as $ci => $chunk)
                    <button type="button" @click="page = {{ $ci }}" class="h-2 rounded-full transition-all" :class="page === {{ $ci }} ? 'w-6 bg-sky-600' : 'w-2 bg-zinc-300 hover:bg-zinc-400 dark:bg-zinc-600'"></button>
                @endforeach
            </div>
        @endif
    </div>
@endif
