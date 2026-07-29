@props([
    // H1 text (or pass a heading slot for custom markup inside the overlay).
    'title' => '',
    // Optional smaller line under the H1.
    'subtitle' => null,
    // Slides for the image slider: [['projectType' => 'kitchen'], ...]
    'slides' => [['projectType' => 'kitchen'], ['projectType' => 'bathroom'], ['projectType' => 'home-remodel']],
    // Unique wire:key suffix — REQUIRED to be unique per page.
    'keySuffix' => 'page',
    'heightClasses' => 'h-[340px] sm:h-[420px] lg:h-[500px]',
])

{{--
    Shared page hero: project-photo slider with a bottom gradient overlay and
    the page H1. One implementation for /compare, /permits, /trades, ZIP pages,
    guides — identical markup used to be hand-rolled per page.
--}}
<div class="mx-auto mt-4 w-full max-w-7xl px-4 sm:px-6 lg:px-8">
    <div class="relative overflow-hidden rounded-2xl">
        <livewire:main-project-hero-slider
            :images-only="true"
            :height-classes="$heightClasses"
            :slides="$slides"
            :key="'hero-' . $keySuffix"
        />
        <div class="pointer-events-none absolute inset-0 z-10 flex items-end bg-linear-to-t from-black/80 via-black/40 to-transparent pb-12 sm:pb-16 lg:pb-20">
            <div class="mx-auto w-full max-w-7xl px-6 lg:px-8">
                @if($slot->isNotEmpty())
                    {{ $slot }}
                @else
                    <h1 class="font-heading text-4xl font-bold text-white text-shadow-lg sm:text-5xl lg:text-6xl">
                        {{ $title }}
                    </h1>
                    @if($subtitle)
                        <p class="mt-3 max-w-2xl text-lg text-white/90 text-shadow sm:text-xl">{{ $subtitle }}</p>
                    @endif
                @endif
            </div>
        </div>
    </div>
</div>
