@props([
    // H1 text (or pass a slot for custom markup inside the overlay).
    'title' => '',
    // Optional smaller line under the H1.
    'subtitle' => null,
    // Slides: [['url' => '…', 'alt' => '…'], …]. Rendered in order.
    'slides' => [],
    'heightClasses' => 'h-[340px] sm:h-[420px] lg:h-[500px]',
    // Replaceable rather than merged: a theme with its own measure (jpeterson is
    // max-w-6xl/px-6) would otherwise emit both max-w-7xl and max-w-6xl and get
    // whichever Tailwind happens to order last in the stylesheet.
    'containerClasses' => 'mx-auto mt-4 w-full max-w-7xl px-4 sm:px-6 lg:px-8',
    'roundedClasses' => 'rounded-2xl',
    'autoplay' => true,
    'interval' => 5000,
    // Set on pages whose hero is the LCP element (i.e. it leads the page).
    'eager' => false,
])

{{--
    Shared hero built on flux:carousel (Flux Pro ships carousel + carousel.slide;
    both layouts already emit @fluxAppearance/@fluxScripts, which the component
    needs to be interactive).

    This is a SECOND hero implementation alongside <x-page-hero>, which wraps the
    Livewire <main-project-hero-slider> and is deliberately left in place on the
    18 pages already using it — swapping working pages onto a different slider
    buys nothing and risks all of them. Use this one for pages gaining a hero.

    Style-neutral on purpose: it takes ready-made slide URLs rather than querying
    projects, so a tenant with no project photos (jpeterson) can pass its own
    placeholders and a tenant with 238 of them (gsc) can pass real covers. Nothing
    here is brand-specific; resources/views is shared by every tenant.
--}}
@if (filled($slides))
    <div {{ $attributes->merge(['class' => $containerClasses]) }}>
        <div class="relative overflow-hidden {{ $roundedClasses }}">
            <flux:carousel
                :autoplay="$autoplay"
                autoplay:interval="{{ $interval }}"
                snap="mandatory"
                fade
                indicators
                class="{{ $heightClasses }} w-full"
            >
                @foreach ($slides as $index => $slide)
                    <flux:carousel.slide class="w-full">
                        <img
                            src="{{ $slide['url'] }}"
                            @if (! empty($slide['srcset']))
                                srcset="{{ $slide['srcset'] }}"
                                sizes="(min-width: 80rem) 76rem, 100vw"
                            @endif
                            alt="{{ $slide['alt'] ?? '' }}"
                            class="{{ $heightClasses }} w-full object-cover"
                            {{-- Only the first slide is on screen at paint; the rest
                                 would otherwise cost bandwidth before anyone advances. --}}
                            loading="{{ $eager && $index === 0 ? 'eager' : 'lazy' }}"
                            fetchpriority="{{ $eager && $index === 0 ? 'high' : 'auto' }}"
                            decoding="async"
                        >
                    </flux:carousel.slide>
                @endforeach
            </flux:carousel>

            {{-- No text => no overlay at all. Most pages gaining a hero already
                 own a single carefully-written H1 (often followed by a speakable
                 answer paragraph), so they use this purely as an image band; an
                 overlay here would emit a second, empty H1 on every one of them.

                 pointer-events-none so the carousel's own arrows and indicators
                 stay clickable underneath the gradient. --}}
            @if ($slot->isNotEmpty() || filled($title) || filled($subtitle))
            <div class="pointer-events-none absolute inset-0 z-10 flex items-end bg-linear-to-t from-black/80 via-black/40 to-transparent pb-12 sm:pb-16 lg:pb-20">
                <div class="mx-auto w-full max-w-7xl px-6 lg:px-8">
                    @if ($slot->isNotEmpty())
                        {{ $slot }}
                    @else
                        <h1 class="font-heading text-4xl font-bold text-white text-shadow-lg sm:text-5xl lg:text-6xl">
                            {{ $title }}
                        </h1>
                        @if ($subtitle)
                            <p class="mt-3 max-w-2xl text-lg text-white/90 text-shadow sm:text-xl">{{ $subtitle }}</p>
                        @endif
                    @endif
                </div>
            </div>
            @endif
        </div>
    </div>
@endif
