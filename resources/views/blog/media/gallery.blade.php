{{-- The mid-post gallery: the Flux carousel, one 1×3 page per slide, so an
     arrow press is exactly one page — native smooth snap scrolling, arrows
     and indicators from Flux. The track is one fixed-height row, so paging
     never moves the page. Pages beyond the first load eagerly (at normal
     priority) so a page you scroll to is already painted. Every tile opens
     the lightbox. --}}
@php
    $images = ($images ?? $project->images)->values();
    $pages = $images->chunk(3)->values();
@endphp
@if ($images->isNotEmpty())
    <div class="not-prose clear-both my-8">
        <flux:carousel
            snap="mandatory"
            wrap="rewind"
            :indicators="$pages->count() > 1"
            :arrows="$pages->count() > 1"
            arrows:position="inside"
            aria-label="Project photos"
            track:class="gap-3 rounded-xl"
        >
            @foreach ($pages as $pi => $page)
                <flux:carousel.slide class="w-full">
                    <div class="grid grid-cols-3 gap-3">
                        @foreach ($page as $img)
                            <button type="button" @click="open({{ \App\Support\Blog\BlogRenderer::lightboxIndex($project, $img) }})" class="group block w-full overflow-hidden rounded-xl text-left" aria-label="Open photo">
                                <x-lqip-image :image="$img" size="medium" width="600" height="450" aspectRatio="4/3" rounded="xl" :loading="$pi > 0 ? 'eager' : null" class="w-full transition duration-300 group-hover:scale-105" />
                            </button>
                        @endforeach
                    </div>
                </flux:carousel.slide>
            @endforeach
        </flux:carousel>
    </div>
@endif
