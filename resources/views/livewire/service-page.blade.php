<div>
    {{-- Breadcrumb Schema --}}
    <x-breadcrumbs :items="[
        ['label' => 'Services', 'url' => route('services.index')],
        ['label' => $data['title']],
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
        {{-- max-w-7xl to line up with every other section on this page; it was
             the lone max-w-3xl and sat visibly inset. The paragraph keeps its
             own cap, since body copy running the full 7xl is a hard read. --}}
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <h2 class="font-heading text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">
                Expert {{ $data['title'] }} Services
            </h2>
            <p class="mt-4 max-w-4xl text-base leading-7 text-zinc-600 dark:text-zinc-300">
                {{ $data['description'] }}
            </p>
        </div>
    </section>

    {{-- At a glance — <x-facts-grid>, the same component the area pages use
         for their cost guide. Every figure is also published on /costs and
         /process; the config carries no independent numbers, so the pages
         cannot contradict each other. --}}
    <x-facts-grid
        :items="$data['facts'] ?? []"
        class="border-y border-zinc-200 bg-white py-8 dark:border-zinc-700 dark:bg-zinc-900">
        Ranges are typical for our Chicagoland projects — see
        <a href="/costs" class="underline underline-offset-2 hover:text-sky-700 dark:hover:text-sky-400">full cost breakdowns</a>.
        Your itemized estimate is free.
    </x-facts-grid>

    {{-- Features --}}
    @if(!empty($data['features']))
        <section class="bg-zinc-50 py-12 sm:py-16 dark:bg-zinc-800/50">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <h2 class="font-heading text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">
                    What We Offer
                </h2>
                <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach($data['features'] as $feature)
                        <div class="group rounded-2xl bg-white p-6 shadow-md ring-1 ring-zinc-900/5 transition hover:shadow-xl dark:bg-zinc-800/75 dark:ring-white/10">
                            <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $feature['title'] }}</h3>
                            <p class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-400">{{ $feature['description'] }}</p>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- Projects Section — directly under "What We Offer", so the claim about
         what we do is followed immediately by the work itself rather than
         sitting three sections away, after the process and pricing blocks. --}}
    @if($projects->isNotEmpty())
        <livewire:projects-grid
            :projectType="$data['projectType']"
            :limit="3"
            :hideFilters="true"
            :showPagination="true"
            :moreProjectsType="$data['projectType']" />
    @endif

    {{-- Process --}}
    <x-process-steps :steps="$data['process'] ?? []" heading="Our Process" />

    {{-- What the price covers. Stating the exclusions plainly is the point:
         it is the question every estimate conversation starts with. --}}
    @if(!empty($data['included']))
        <section class="bg-zinc-50 py-12 sm:py-16 dark:bg-zinc-800/50">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <h2 class="font-heading text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">
                    What&rsquo;s Included
                </h2>
                <div class="mt-8 grid gap-8 lg:grid-cols-2">
                    <div class="group rounded-2xl bg-white p-6 shadow-md ring-1 ring-zinc-900/5 transition hover:shadow-xl dark:bg-zinc-800/75 dark:ring-white/10">
                        <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">Labor &amp; installation we include</h3>
                        <ul class="mt-4 space-y-2">
                            @foreach($data['included'] as $item)
                                <li class="flex gap-3 text-sm leading-6 text-zinc-600 dark:text-zinc-300">
                                    <svg class="mt-1 size-4 shrink-0 text-emerald-600 dark:text-emerald-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0L3.3 9.7a1 1 0 1 1 1.4-1.4l3.8 3.8 6.8-6.8a1 1 0 0 1 1.4 0Z" clip-rule="evenodd" />
                                    </svg>
                                    <span>{{ $item }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    @if(!empty($data['notIncluded']))
                        <div class="group rounded-2xl bg-white p-6 shadow-md ring-1 ring-zinc-900/5 transition hover:shadow-xl dark:bg-zinc-800/75 dark:ring-white/10">
                            <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">You supply / quoted separately</h3>
                            <ul class="mt-4 space-y-2">
                                @foreach($data['notIncluded'] as $item)
                                    <li class="flex gap-3 text-sm leading-6 text-zinc-600 dark:text-zinc-300">
                                        <svg class="mt-1 size-4 shrink-0 text-zinc-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                            <path fill-rule="evenodd" d="M4 10a1 1 0 0 1 1-1h10a1 1 0 1 1 0 2H5a1 1 0 0 1-1-1Z" clip-rule="evenodd" />
                                        </svg>
                                        <span>{{ $item }}</span>
                                    </li>
                                @endforeach
                            </ul>
                            <p class="mt-4 text-xs leading-5 text-zinc-500 dark:text-zinc-400">
                                You choose and purchase the finish materials; we specify what is needed,
                                schedule it against the build, and install it. Listed openly so nothing
                                on your final invoice is a surprise.
                            </p>
                        </div>
                    @endif
                </div>
            </div>
        </section>
    @endif

    {{-- Testimonials Section --}}
    <livewire:testimonials-section :project-type="$data['projectType']" :key="'testimonials-'.$data['projectType']" />

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

        // Towns with the most completed work first, then alphabetically — see
        // AreaServed::orderedByLocalProjects(). Replaces a hardcoded list of 14
        // "priority" slugs that needed hand-editing whenever the area list
        // changed, and had already gone stale.
        $allAreas = $serviceSlug ? \App\Models\AreaServed::orderedByLocalProjects() : collect();
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


    {{-- FAQ Section (visible + schema for rich results) --}}
    <x-faq-section :faqs="$data['faqs'] ?? []" :heading="$data['title'] . ' FAQ'" />

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

    {{-- Other services — the site-wide counterpart to the area pages' "More
         Remodeling Services in {city}" block, sharing the same
         <x-service-chips> component. Passing no area switches it to the
         /services/* links.

         Last block before the footer, where the cross-links belong: the FAQ is
         still part of this service's content, so the reader is only offered a
         different service once they are done with this one.

         brand.name rather than a literal: this view is shared across tenants,
         so a hardcoded "GS Construction" would render on every site. --}}
    <section class="bg-zinc-50 py-12 dark:bg-zinc-800/50">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <h2 class="mb-6 text-lg font-semibold text-zinc-900 dark:text-white">
                Other {{ config('brand.name') }} Services
            </h2>
            <x-service-chips :exclude="$service" />
        </div>
    </section>
</div>
