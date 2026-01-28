@php
    // Get all images for the slider
    $allImages = $project->images()->orderBy('sort_order')->get();
    $imagesData = $allImages->map(fn($img) => [
        'id' => $img->id,
        'url' => $img->getWebpThumbnailUrl('large') ?: $img->getThumbnailUrl('large'),
        'thumbUrl' => $img->getWebpThumbnailUrl('thumbnail') ?: $img->getThumbnailUrl('thumbnail'),
        'originalUrl' => $img->url,
        'alt' => $this->localizeText($img->alt_text ?: $img->seo_alt_text),
        'caption' => $this->localizeText($img->caption),
        'isCover' => $img->is_cover,
        'pageUrl' => route('projects.image', [$project, $img]),
    ])->values();
    
    $initialIndex = $allImages->search(fn($img) => $img->id === $image->id) ?: 0;
    
    // Localized location
    $displayLocation = $project->location;
@endphp

<div class="bg-white dark:bg-zinc-900 min-h-screen"
     x-cloak
     x-data="{
         images: {{ Js::from($imagesData) }},
         currentIndex: {{ $initialIndex }},
         isZoomed: false,
         
         get current() { return this.images[this.currentIndex]; },
         get total() { return this.images.length; },
         get position() { return this.currentIndex + 1; },
         
         prev() {
             if (this.isZoomed) return;
             this.currentIndex = (this.currentIndex - 1 + this.total) % this.total;
             this.updateUrl();
         },
         next() {
             if (this.isZoomed) return;
             this.currentIndex = (this.currentIndex + 1) % this.total;
             this.updateUrl();
         },
         goTo(index) {
             this.currentIndex = index;
             this.updateUrl();
         },
         updateUrl() {
             window.history.replaceState({}, '', this.current.pageUrl);
             this.$nextTick(() => {
                 const thumb = this.$refs.thumbnails?.querySelector(`[data-index='${this.currentIndex}']`);
                 thumb?.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
             });
         },
         
         init() {
             // Preload all images
             this.images.forEach(img => {
                 new Image().src = img.url;
             });
         }
     }"
     @zoom-changed.window="isZoomed = $event.detail.isZoomed"
     @keydown.left.window="prev()"
     @keydown.right.window="next()"
     @keydown.escape.window="!isZoomed && $refs.backLink?.click()"
>
    {{-- Hidden back link for keyboard access --}}
    <a href="{{ route('projects.show', $project) }}" wire:navigate x-ref="backLink" class="sr-only">Back</a>

    {{-- Breadcrumb Schema --}}
    @php
        $breadcrumbItems = [
            ['name' => 'Projects', 'url' => route('projects.index')],
            ['name' => $project->title, 'url' => route('projects.show', $project)],
            ['name' => 'Photos'],
        ];
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
                    <svg class="h-4 w-4 shrink-0 text-gray-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                    </svg>
                    <a href="{{ route('projects.index') }}" wire:navigate class="ml-2 text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Projects</a>
                </li>
                <li class="flex items-center">
                    <svg class="h-4 w-4 shrink-0 text-gray-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                    </svg>
                    <a href="{{ route('projects.show', $project) }}" wire:navigate class="ml-2 text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">{{ $project->title }}</a>
                </li>
                <li class="flex items-center">
                    <svg class="h-4 w-4 shrink-0 text-gray-500" fill="currentColor" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                    </svg>
                    <span class="ml-2 text-gray-700 dark:text-gray-300">Photos</span>
                </li>
            </ol>
        </nav>
    </div>

    {{-- Main Content --}}
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        {{-- Header with navigation --}}
        <div class="flex items-center justify-between mb-6">
            <div>
                <a href="{{ route('projects.show', $project) }}" wire:navigate class="inline-flex items-center gap-2 text-sky-600 hover:text-sky-700 dark:text-sky-400 dark:hover:text-sky-300 transition-colors">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                    </svg>
                    Back to {{ $project->title }}
                </a>
            </div>
        </div>

        {{-- Image Container using zoomable-image component --}}
        <div class="relative">
            <figure class="rounded-2xl overflow-hidden">
                <x-zoomable-image 
                    container-class="relative" 
                    image-class="w-full"
                    rounded="rounded-2xl"
                >
                    <x-slot:overlays>
                        {{-- Dot indicators (bottom center, over image) --}}
                        <div class="absolute bottom-4 inset-x-0 flex justify-center gap-2 z-10" x-show="total > 1">
                            <template x-for="(img, idx) in images" :key="img.id">
                                <button
                                    @click.stop="goTo(idx)"
                                    :class="currentIndex === idx ? 'bg-white w-8' : 'bg-white/50 w-3 hover:bg-white/70'"
                                    class="h-3 rounded-full transition-all duration-300 shadow-lg cursor-pointer"
                                    :title="`Photo ${idx + 1}`">
                                </button>
                            </template>
                        </div>

                        {{-- Info overlay (top left of image) --}}
                        <div class="absolute top-4 left-4 flex flex-wrap items-center gap-2 text-sm z-10">
                            <span x-show="current.isCover" class="inline-flex items-center rounded-full bg-sky-500 px-2.5 py-1 text-xs font-medium text-white shadow-lg">
                                Featured Photo
                            </span>
                            {{-- Hidden but accessible Full Size link --}}
                            <a :href="current.originalUrl" 
                               target="_blank"
                               class="sr-only"
                               @click.stop>
                                Full Size
                            </a>
                        </div>

                        {{-- Counter (top right of image) --}}
                        <div class="absolute top-4 right-4 text-sm z-10" x-show="total > 1">
                            <div class="text-white/80 bg-black/50 px-3 py-1.5 rounded-full">
                                <span x-text="position"></span> / <span x-text="total"></span>
                            </div>
                        </div>
                    </x-slot:overlays>
                </x-zoomable-image>
            </figure>

            {{-- Caption below image --}}
            <figcaption x-show="current.caption" class="mt-4 text-center text-gray-700 dark:text-gray-300 text-lg" x-text="current.caption">
            </figcaption>
        </div>

        {{-- Thumbnail Strip --}}
        <div class="mt-6" x-show="total > 1">
            <div class="flex gap-3 overflow-x-auto py-2 px-1 scrollbar-thin scrollbar-thumb-gray-400 scrollbar-track-gray-200 dark:scrollbar-thumb-gray-600 dark:scrollbar-track-gray-800"
                 x-ref="thumbnails">
                <template x-for="(img, idx) in images" :key="'thumb-' + img.id">
                    <button
                        @click="goTo(idx)"
                        :data-index="idx"
                        :class="currentIndex === idx 
                            ? 'ring-2 ring-sky-500 ring-offset-2 dark:ring-offset-zinc-900' 
                            : 'opacity-60 hover:opacity-100'"
                        class="shrink-0 relative rounded-lg overflow-hidden transition-all duration-200 cursor-pointer"
                        :title="`Photo ${idx + 1}`">
                        <img 
                            :src="img.thumbUrl" 
                            :alt="`Photo ${idx + 1}`"
                            class="w-16 h-16 sm:w-20 sm:h-20 object-cover"
                            loading="lazy"
                        >
                        <div x-show="currentIndex === idx" class="absolute inset-0 bg-sky-500/20"></div>
                    </button>
                </template>
            </div>
        </div>

        {{-- Project metadata (below thumbnails) --}}
        <div class="mt-6 flex flex-wrap items-center justify-between gap-4 text-sm text-gray-600 dark:text-gray-400">
            <p>
                From <a href="{{ route('projects.show', $project) }}" wire:navigate class="text-sky-600 hover:text-sky-700 dark:text-sky-400 dark:hover:text-sky-300 font-medium">{{ $project->title }}</a>
                @if($displayLocation)
                    <span class="mx-1">•</span>
                    {{ $displayLocation }}
                @endif
                @if($project->completed_at)
                    <span class="mx-1">•</span>
                    {{ $project->completed_at->format('F Y') }}
                @endif
            </p>
        </div>
    </div>

    {{-- CTA Section --}}
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
