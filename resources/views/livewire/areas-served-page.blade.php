<div>
    @php
        $pageLabelMap = [
            'areas-served' => 'Areas Served',
            'areas' => 'Areas',
            'locations' => 'Locations',
        ];
        $pageLabel = $pageLabelMap[$currentRoute] ?? 'Areas Served';
    @endphp
    {{-- Breadcrumb Schema --}}
    <x-breadcrumb-schema :items="[
        ['name' => $pageLabel],
    ]" />

    {{-- Visual Breadcrumb Navigation --}}
    <div class="mx-auto max-w-7xl px-4 py-4 sm:px-6 lg:px-8">
        <nav class="flex" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-2 text-sm">
                <li>
                    <a href="/" wire:navigate class="text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">Home</a>
                </li>
                <li class="flex items-center">
                    <svg class="h-4 w-4 flex-shrink-0 text-gray-500" fill="currentColor" aria-hidden="true" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                    </svg>
                    <span class="ml-2 text-gray-700 dark:text-gray-300">{{ $pageLabel }}</span>
                </li>
            </ol>
        </nav>
    </div>

    {{-- Hero Section --}}
    <section class="relative overflow-hidden bg-zinc-900 min-h-[420px] sm:min-h-[520px] lg:min-h-[640px]">
        <div class="absolute inset-0">
            <livewire:map-section height-classes="h-[420px] sm:h-[520px] lg:h-[640px]" />
        </div>
        <div class="absolute inset-0 bg-gradient-to-t from-zinc-900 via-zinc-900/60 to-zinc-900/40"></div>
        <div class="relative mx-auto max-w-7xl px-4 py-24 sm:px-6 sm:py-32 lg:px-8">
            <div class="text-center">
                {{-- /areas-served variant --}}
                <div @class(['hidden' => $currentRoute !== 'areas-served'])>
                    <h1 class="text-4xl font-bold tracking-tight text-white sm:text-5xl lg:text-6xl">Areas We Serve</h1>
                    <p class="mx-auto mt-6 max-w-2xl text-lg leading-8 text-zinc-300">Serving homeowners throughout Chicagoland, Northwest Suburbs, and the North Shore with professional kitchen remodels, bathroom renovations, and home remodeling services.</p>
                </div>
                {{-- /areas variant --}}
                <div @class(['hidden' => $currentRoute !== 'areas'])>
                    <h1 class="text-4xl font-bold tracking-tight text-white sm:text-5xl lg:text-6xl">Areas We Service</h1>
                    <p class="mx-auto mt-6 max-w-2xl text-lg leading-8 text-zinc-300">Providing expert kitchen remodels, bathroom renovations, and home remodeling services across Chicagoland, Northwest Suburbs, and the North Shore.</p>
                </div>
                {{-- /locations variant --}}
                <div @class(['hidden' => $currentRoute !== 'locations'])>
                    <h1 class="text-4xl font-bold tracking-tight text-white sm:text-5xl lg:text-6xl">Locations We Serve</h1>
                    <p class="mx-auto mt-6 max-w-2xl text-lg leading-8 text-zinc-300">Serving homeowners throughout Chicagoland, Northwest Suburbs, and the North Shore with professional kitchen remodels, bathroom renovations, and home remodeling services.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- Areas Grid --}}
    <section class="py-16 sm:py-24">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="grid gap-8 sm:gap-12">
                @foreach ($groupedAreas as $letter => $areas)
                    <div>
                        <h2 class="mb-4 text-2xl font-bold text-zinc-900 dark:text-white">
                            {{ $letter }}
                        </h2>
                        <div class="grid gap-3 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4">
                            @foreach ($areas as $area)
                                <a 
                                    href="{{ $area->url }}" 
                                    wire:navigate
                                    class="group flex items-center gap-2 rounded-lg border border-zinc-200 bg-white p-4 transition hover:border-sky-300 hover:bg-sky-50 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:border-sky-600 dark:hover:bg-zinc-700"
                                >
                                    <svg class="h-5 w-5 text-sky-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    </svg>
                                    <span class="font-medium text-zinc-900 group-hover:text-sky-700 dark:text-white dark:group-hover:text-sky-400">
                                        {{ $area->city }}
                                    </span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- CTA Section --}}
    <x-cta-section 
        variant="blue"
        heading="Don't See Your Area?"
        description="We serve Chicagoland, Northwest Suburbs, and the North Shore. Contact us to discuss your project."
        primary-cta-text="Contact Us"
        primary-cta-url="/contact"
    />
</div>
