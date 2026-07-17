<div>
    {{-- Breadcrumb Schema --}}
    <x-breadcrumb-schema :items="[
        ['name' => 'Services', 'url' => route('services.index')],
        ['name' => $data['title']],
    ]" />

    {{-- Service Schema --}}
    <x-service-schema :service="$data" />

    {{-- Product Schema — only schema type that triggers review-star rich results today.
         Self-serving LocalBusiness review snippets were deprecated by Google in 2019. --}}
    <x-product-service-schema :service-slug="$service" />

    {{-- HowTo Schema (process steps as a HowTo rich result) --}}
    <x-service-howto-schema :service="$data" :service-slug="$service" />

    {{-- Hero Section --}}
    <section class="mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-8">
        <livewire:main-project-hero-slider 
            :project-type="$data['projectType']"
            :slides="[
                [
                    'heading' => $data['heroTitle'],
                    'subheading' => $data['heroSubtitle'],
                    'type' => $data['projectType'],
                ],
                [
                    'heading' => $data['heroTitle'],
                    'subheading' => $data['heroSubtitle'],
                    'type' => $data['projectType'],
                ],
                [
                    'heading' => $data['heroTitle'],
                    'subheading' => $data['heroSubtitle'],
                    'type' => $data['projectType'],
                ],
            ]"
            :slide-count="3"
            primary-cta-text="Get a Free Quote"
            primary-cta-url="/contact"
            secondary-cta-text="View Our Work"
            secondary-cta-url="/projects"
        />
    </section>

    {{-- About: the authored per-service intro (was commented out for months —
         it and the sections below are the page's only substantive unique copy,
         so they must render, not just feed schema). --}}
    <section class="py-12 sm:py-16">
        <div class="mx-auto max-w-3xl px-4 sm:px-6 lg:px-8">
            <h2 class="font-heading text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">
                Expert {{ $data['title'] }} Services
            </h2>
            <p class="mt-4 text-base leading-7 text-zinc-600 dark:text-zinc-300">
                {{ $data['description'] }}
            </p>
        </div>
    </section>

    {{-- Features --}}
    @if(!empty($data['features']))
        <section class="bg-zinc-50 py-12 sm:py-16 dark:bg-zinc-800/50">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <h2 class="font-heading text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">
                    What We Offer
                </h2>
                <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach($data['features'] as $feature)
                        <div class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                            <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $feature['title'] }}</h3>
                            <p class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-400">{{ $feature['description'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- Process --}}
    @if(!empty($data['process']))
        <section class="py-12 sm:py-16">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <h2 class="font-heading text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">
                    Our Process
                </h2>
                <p class="mt-2 text-base text-zinc-600 dark:text-zinc-400">
                    From initial consultation to final walkthrough, here's what to expect.
                </p>
                <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach($data['process'] as $step)
                        <div class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900">
                            <div class="flex size-10 items-center justify-center rounded-full bg-sky-50 text-lg font-bold text-sky-700 dark:bg-sky-500/10 dark:text-sky-400">
                                {{ $step['step'] }}
                            </div>
                            <h3 class="mt-4 text-lg font-semibold text-zinc-900 dark:text-white">{{ $step['title'] }}</h3>
                            <p class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-400">{{ $step['description'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- Projects Section --}}
    @if($projects->isNotEmpty())
        <livewire:projects-grid :projectType="$data['projectType']" :limit="3" :hideFilters="true" :showPagination="true" />
        @php
            $moreProjects = [
                'kitchen' => ['label' => 'More Kitchen Projects', 'url' => '/projects/kitchens', 'variant' => 'secondary'],
                'bathroom' => ['label' => 'More Bathroom Projects', 'url' => '/projects/bathrooms', 'variant' => 'secondary'],
                'home-remodel' => ['label' => 'More Home Remodeling Projects', 'url' => '/projects/home-remodeling', 'variant' => 'secondary'],
            ];
            $moreProjectsLink = $moreProjects[$data['projectType']] ?? null;
        @endphp
        @if($moreProjectsLink)
            <div class="relative z-10 -mt-4 text-center">
                <x-buttons.cta href="{{ $moreProjectsLink['url'] }}" variant="{{ $moreProjectsLink['variant'] ?? 'primary' }}" size="lg" class="pointer-events-auto">
                    {{ $moreProjectsLink['label'] }}
                </x-buttons.cta>
            </div>
        @endif
    @endif

    {{-- Testimonials Section --}}
    <livewire:testimonials-section :project-type="$data['projectType']" :key="'testimonials-'.$data['projectType']" />

    {{-- Internal Links Section --}}
    <x-internal-links :projects="$projects" :current-service="$service" />

    {{-- Service by City — hub-to-spoke internal linking for local SEO --}}
    @php
        // Canonical spoke slugs (the legacy 'kitchens'/'bathrooms' aliases 301
        // to these — linking canonically saves the redirect hop).
        $serviceSlugMap = [
            'kitchen-remodeling' => 'kitchen-remodeling',
            'bathroom-remodeling' => 'bathroom-remodeling',
            'home-remodeling' => 'home-remodeling',
            'home-additions' => 'home-additions',
            'basement-remodeling' => 'basement-remodeling',
        ];
        $serviceSlug = $serviceSlugMap[$service] ?? null;

        // Lead with our highest GSC search-demand suburbs (striking distance for
        // "{service} {city}" queries, e.g. Schaumburg / Deer Park kitchen), then
        // the rest alphabetically. Surfacing them first gives those spokes the
        // most prominent internal link on every service hub page.
        $priorityCitySlugs = [
            'schaumburg', 'deer-park', 'barrington', 'glenview', 'northbrook',
            'arlington-heights', 'winnetka', 'wilmette', 'kenilworth', 'glencoe',
            'evanston', 'mount-prospect', 'orland-park', 'lake-bluff',
        ];
        $allAreas = collect();
        if ($serviceSlug) {
            $areas = \App\Models\AreaServed::orderBy('city')->get();
            $priorityAreas = $areas
                ->filter(fn ($a) => in_array($a->slug, $priorityCitySlugs, true))
                ->sortBy(fn ($a) => array_search($a->slug, $priorityCitySlugs))
                ->values();
            $allAreas = $priorityAreas->concat(
                $areas->reject(fn ($a) => in_array($a->slug, $priorityCitySlugs, true))
            )->values();
        }
    @endphp
    @if($allAreas->isNotEmpty())
    <section class="bg-zinc-50 py-12 dark:bg-zinc-800/50">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <h2 class="font-heading text-2xl font-bold text-zinc-900 dark:text-white">
                {{ $data['title'] }} by City
            </h2>
            <p class="mt-2 text-zinc-600 dark:text-zinc-400">
                Find local {{ strtolower($data['title']) }} contractors in your Chicago suburb.
            </p>
            <div class="mt-6 flex flex-wrap gap-2">
                @foreach($allAreas as $areaItem)
                <a href="{{ $areaItem->serviceUrl($serviceSlug) }}" wire:navigate class="rounded-lg bg-white px-3 py-1.5 text-sm text-zinc-700 shadow-sm hover:bg-sky-50 hover:text-sky-700 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700">
                    {{ $areaItem->city }}
                </a>
                @endforeach
            </div>
        </div>
    </section>
    @endif

    {{-- CTA Section --}}
    <x-cta-section 
        variant="blue"
        :heading="$data['ctaHeading']"
        description="Get a free consultation and quote for your remodeling project. GS Construction is ready to bring your vision to life."
        primaryText="Get Free Quote"
        :primaryHref="route('contact')"
        secondaryText="View Our Work"
        :secondaryHref="route('projects.index')"
    />

    {{-- FAQ Section (visible + schema for rich results — just above footer) --}}
    <x-faq-section :faqs="$data['faqs'] ?? []" :heading="$data['title'] . ' FAQ'" />
</div>
