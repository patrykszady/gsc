<div class="bg-white dark:bg-zinc-900 min-h-screen"
     x-data="{
         touchStartX: 0,
         touchStartY: 0,
         handleTouchStart(e) {
             this.touchStartX = e.touches[0].clientX;
             this.touchStartY = e.touches[0].clientY;
         },
         handleTouchEnd(e) {
             const touchEndX = e.changedTouches[0].clientX;
             const touchEndY = e.changedTouches[0].clientY;
             const diffX = this.touchStartX - touchEndX;
             const diffY = Math.abs(this.touchStartY - touchEndY);
             
             if (Math.abs(diffX) > 50 && Math.abs(diffX) > diffY) {
                 if (diffX > 0) {
                     $wire.nextImage();
                 } else {
                     $wire.previousImage();
                 }
             }
         },
         init() {
             // Listen for URL changes to update browser history
             Livewire.on('urlChanged', ({ url }) => {
                 window.history.replaceState({}, '', url);
             });
         }
     }"
     @keydown.left.window="$wire.previousImage()"
     @keydown.right.window="$wire.nextImage()"
     @keydown.escape.window="Livewire.navigate('{{ route('projects.show', $project) }}')"
     wire:key="photo-v2-{{ $image->id }}">

    {{-- Breadcrumb Schema --}}
    @php
        $breadcrumbItems = [
            ['name' => 'Projects', 'url' => route('projects.index')],
            ['name' => $project->title, 'url' => route('projects.show', $project)],
            ['name' => "Photo {$currentPosition}"],
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
                    <span class="ml-2 text-gray-700 dark:text-gray-300">Photo {{ $currentPosition }}</span>
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

        {{-- Image Container --}}
        <div class="relative"
             @touchstart="handleTouchStart($event)"
             @touchend="handleTouchEnd($event)">
            <figure class="rounded-2xl overflow-hidden">
                {{-- Main Image with overlays inside --}}
                <div class="relative flex items-center justify-center">
                    @php
                        $webpUrl = $image->getWebpThumbnailUrl('large');
                        $jpgUrl = $image->getThumbnailUrl('large');
                        $blurWebp = $image->getWebpThumbnailUrl('small');
                        $blurJpg = $image->getThumbnailUrl('small');
                        $originalUrl = $image->url;
                    @endphp
                    
                    {{-- Blur-up image loading with progressive blur --}}
                    <div class="relative w-full h-full flex items-center justify-center" 
                         x-data="{ loaded: false, blurAmount: 24 }"
                         x-init="
                             // Check if image is already cached/complete
                             $nextTick(() => {
                                 const img = $el.querySelector('picture:last-of-type img');
                                 if (img && img.complete && img.naturalWidth > 0) {
                                     loaded = true;
                                     blurAmount = 0;
                                 }
                             });
                             $watch('loaded', value => {
                                 if (value && blurAmount > 0) {
                                     // Progressive blur reduction
                                     const steps = [16, 8, 4, 2, 0];
                                     steps.forEach((blur, i) => {
                                         setTimeout(() => { blurAmount = blur; }, i * 50);
                                     });
                                 }
                             });
                         "
                         wire:key="image-blur-{{ $image->id }}">
                        {{-- Blurred small placeholder (scaled to full size) --}}
                        <picture class="absolute inset-0 flex items-center justify-center transition-all duration-300 ease-out"
                                 :style="`filter: blur(${blurAmount}px); opacity: ${loaded ? 0 : 1}`">
                            @if($blurWebp)
                                <source srcset="{{ $blurWebp }}" type="image/webp">
                            @endif
                            <img 
                                src="{{ $blurJpg }}" 
                                alt=""
                                class="w-full h-full object-cover scale-110"
                                aria-hidden="true"
                            >
                        </picture>
                        
                        {{-- Full resolution image --}}
                        <picture class="relative z-[1]">
                            @if($webpUrl)
                                <source srcset="{{ $webpUrl }}" type="image/webp">
                            @endif
                            <img 
                                src="{{ $jpgUrl }}" 
                                alt="{{ $image->alt_text ?: $image->seo_alt_text }}"
                                class="w-full h-auto rounded-2xl transition-opacity duration-500 ease-out"
                                :class="loaded ? 'opacity-100' : 'opacity-0'"
                                loading="eager"
                                @load="loaded = true"
                            >
                        </picture>
                    </div>

                    {{-- Caption overlay (bottom of image) --}}
                    @if($image->caption)
                        <div class="absolute bottom-0 inset-x-0 bg-linear-to-t from-black/80 via-black/50 to-transparent p-6 pt-12">
                            <p class="text-white text-lg text-center">{{ $image->caption }}</p>
                        </div>
                    @endif

                    {{-- Dot indicators (bottom center, over image) --}}
                    @if($totalImages > 1)
                        <div class="absolute bottom-4 inset-x-0 flex justify-center gap-2 z-10">
                            @foreach($project->images()->orderBy('sort_order')->get() as $thumb)
                                <button type="button"
                                   wire:click="goToImage({{ $thumb->id }})"
                                   class="{{ $thumb->id === $image->id ? 'bg-white w-8' : 'bg-white/50 w-3 hover:bg-white/70' }} h-3 rounded-full transition-all duration-300 shadow-lg cursor-pointer"
                                   title="Photo {{ $loop->iteration }}">
                                </button>
                            @endforeach
                        </div>
                    @endif

                    {{-- Info overlay (top left of image) --}}
                    <div class="absolute top-4 left-4 flex flex-wrap items-center gap-2 text-sm z-10">
                        @if($image->is_cover)
                            <span class="inline-flex items-center rounded-full bg-sky-500 px-2.5 py-1 text-xs font-medium text-white shadow-lg">
                                Featured Photo
                            </span>
                        @endif
                        <a href="{{ $originalUrl }}" 
                           target="_blank"
                           class="text-white/80 hover:text-white inline-flex items-center gap-1 transition-colors bg-black/50 px-3 py-1.5 rounded-full"
                           title="View original full-size image">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                            </svg>
                            Full Size
                        </a>
                    </div>

                    {{-- Project info overlay (top right of image) --}}
                    <div class="absolute top-4 right-4 text-sm z-10">
                        <div class="text-white/80 bg-black/50 px-3 py-1.5 rounded-full">
                            {{ $currentPosition }} / {{ $totalImages }}
                        </div>
                    </div>

                    {{-- Click zones for navigation (desktop) --}}
                    @if($totalImages > 1)
                        {{-- Previous zone (left third) --}}
                        <button type="button"
                           wire:click="previousImage"
                           class="absolute left-0 top-0 h-full w-1/3 cursor-w-resize z-20 hidden sm:block"
                           title="Previous photo">
                        </button>
                        {{-- Next zone (right third) --}}
                        <button type="button"
                           wire:click="nextImage"
                           class="absolute right-0 top-0 h-full w-1/3 cursor-e-resize z-20 hidden sm:block"
                           title="Next photo">
                        </button>
                    @endif
                </div>
            </figure>
        </div>

        {{-- Thumbnail Strip --}}
        @if($totalImages > 1)
            <div class="mt-6">
                <div class="flex gap-3 overflow-x-auto py-2 px-1 scrollbar-thin scrollbar-thumb-gray-400 scrollbar-track-gray-200 dark:scrollbar-thumb-gray-600 dark:scrollbar-track-gray-800"
                     x-data
                     x-init="$nextTick(() => {
                         const active = $el.querySelector('[data-active=true]');
                         if (active) {
                             active.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
                         }
                     })"
                     wire:key="thumbnails-{{ $image->id }}">
                    @foreach($project->images()->orderBy('sort_order')->get() as $thumb)
                        <button type="button"
                           wire:click="goToImage({{ $thumb->id }})"
                           data-active="{{ $thumb->id === $image->id ? 'true' : 'false' }}"
                           class="shrink-0 relative group {{ $thumb->id === $image->id ? 'ring-2 ring-sky-500 ring-offset-2 dark:ring-offset-zinc-900' : 'opacity-60 hover:opacity-100' }} rounded-lg overflow-hidden transition-all duration-200 cursor-pointer"
                           title="Photo {{ $loop->iteration }}">
                            @php
                                $thumbWebp = $thumb->getWebpThumbnailUrl('thumbnail');
                                $thumbJpg = $thumb->getThumbnailUrl('thumbnail');
                            @endphp
                            <picture>
                                @if($thumbWebp)
                                    <source srcset="{{ $thumbWebp }}" type="image/webp">
                                @endif
                                <img 
                                    src="{{ $thumbJpg }}" 
                                    alt="Photo {{ $loop->iteration }}"
                                    class="w-16 h-16 sm:w-20 sm:h-20 object-cover"
                                    loading="lazy"
                                >
                            </picture>
                            @if($thumb->id === $image->id)
                                <div class="absolute inset-0 bg-sky-500/20"></div>
                            @endif
                        </button>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Project metadata (below thumbnails) --}}
        <div class="mt-6 flex flex-wrap items-center justify-between gap-4 text-sm text-gray-600 dark:text-gray-400">
            <p>
                From <a href="{{ route('projects.show', $project) }}" wire:navigate class="text-sky-600 hover:text-sky-700 dark:text-sky-400 dark:hover:text-sky-300 font-medium">{{ $project->title }}</a>
                @if($project->location)
                    <span class="mx-1">•</span>
                    {{ $project->location }}
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
