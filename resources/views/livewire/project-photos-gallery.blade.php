<div
    x-data="{
        isMobile: window.innerWidth < 640,
        resizeTimer: null,
        syncPerPage() { const next = this.isMobile ? {{ $mobilePerPage }} : {{ $desktopPerPage }}; if ($wire.perPage !== next) { $wire.setPerPage(next); } },
        lightbox: false,
        currentIndex: 0,
        images: {{ Js::from($allImages->map(function ($img) use ($project) {
            return [
                'id' => $img->id,
                'url' => $img->getThumbnailUrl('large'),
                'webpUrl' => $img->getWebpThumbnailUrl('large'),
                'originalUrl' => $img->url,
                'alt' => $img->alt_text ?: $img->seo_alt_text,
                'caption' => $img->caption,
                'pageUrl' => route('projects.image', ['project' => $project, 'image' => $img->id]),
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
    x-init="syncPerPage(); $nextTick(() => { resizeTimer = null; })"
    @resize.window.debounce.300ms="isMobile = window.innerWidth < 640; syncPerPage()"
    @keydown.escape.window="close()"
    @keydown.arrow-right.window="if(lightbox) next()"
    @keydown.arrow-left.window="if(lightbox) prev()"
>
    @if($allImages->isNotEmpty())
        {{-- Gallery header with link to full photos --}}
        <div id="gallery-photos-top" class="flex items-center justify-between mb-4">
            <h2 class="text-xl font-semibold text-gray-900 dark:text-white">
                Project Photos
                <span class="text-base font-normal text-gray-500 dark:text-gray-400">({{ $allImages->count() }})</span>
            </h2>
            @php $firstImageKey = $allImages->first()?->id; @endphp
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

        {{-- Gallery Grid --}}
            <div
                class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 transition-opacity duration-150"
                wire:loading.delay.class="opacity-60"
                wire:target="previousPage,nextPage,gotoPage,setPage"
            >
                @foreach($paginator as $imageIndex => $image)
                    @php
                        $globalIndex = ($paginator->firstItem() - 1) + $imageIndex;
                    @endphp
                    <div
                        wire:key="photo-{{ $image->id }}"
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
                                eager
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
                                @if($image->caption)
                                    <p class="text-sm text-white leading-relaxed line-clamp-3">{{ $image->caption }}</p>
                                @endif

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

            {{-- Flux pagination (no scrollTo = stays in place) --}}
            @if($paginator->hasPages())
                <div class="mt-6">
                    <flux:pagination :paginator="$paginator" />
                </div>
            @endif

            {{-- Lightbox Component --}}
            <x-lightbox />
    @endif
</div>

@script
<script>
    Livewire.hook('commit', ({ component, succeed }) => {
        if (component.name !== 'project-photos-gallery') return;
        succeed(() => {
            if (window.innerWidth >= 640) return;
            const target = document.getElementById('gallery-photos-top');
            if (!target) return;
            const start = window.scrollY;
            const end = target.getBoundingClientRect().top + start - 16;
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
</script>
@endscript
