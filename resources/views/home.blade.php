<x-layouts.app
    title="Remodeling Contractors"
    metaDescription="Professional kitchen, bathroom, and home remodeling services. GS Construction is a family-owned business serving the Chicagoland area."
>
    {{-- Note: LCP preload is handled by the hero slider component itself --}}

    {{-- Voice / AI: 'About this business' speakable summary (visible to humans, --}}
    {{-- emphasized for voice assistants, AI Overviews, and ChatGPT browse). --}}
    <section class="speakable mx-auto max-w-7xl px-6 pt-6 lg:px-8" aria-label="About GS Construction">
        <p class="sr-only" data-speakable="business-summary">
            GS Construction is a family-owned kitchen, bathroom, and home remodeling contractor
            based in Arlington Heights, Illinois, founded in 2015 by Gregory and Patryk.
            We serve more than 89 cities across the Chicago suburbs, hold a 5-star rating
            across {{ \App\Models\Testimonial::count() }} verified customer reviews on Google, Houzz, Yelp, and Angi,
            and offer free in-home estimates. Call (224) 735-4200 or email crew@gs.construction.
            We work in English and Polish. Hours: Monday through Saturday, 8 AM to 6 PM Central.
        </p>
    </section>

    {{-- Main Project Hero Slider --}}
    @php
        $kitchenVariations = [
            ['button' => 'Kitchen Remodeling', 'secondaryButton' => 'Schedule Free Consult'],
            ['button' => 'Dream Kitchens', 'secondaryButton' => 'Start Your Project'],
            ['button' => 'Kitchen Renovations', 'secondaryButton' => 'Get A Quote'],
            ['button' => 'Custom Kitchens', 'secondaryButton' => 'Contact Us Today'],
        ];
        
        $bathroomVariations = [
            ['button' => 'Bathroom Remodeling', 'secondaryButton' => 'Get a Free Estimate'],
            ['button' => 'Bathroom Contractors', 'secondaryButton' => 'Contact Us Today'],
            ['button' => 'Luxury Bathrooms', 'secondaryButton' => 'Start Renovating'],
            ['button' => 'Bath Renovations', 'secondaryButton' => 'Request Consult'],
        ];
        
        $homeVariations = [
            ['button' => 'Home Remodeling', 'secondaryButton' => 'Transform Your Home'],
            ['button' => 'Home Renovations', 'secondaryButton' => 'Get a Free Estimate'],
            ['button' => 'Whole Home Remodel', 'secondaryButton' => 'Discuss Your Project'],
            ['button' => 'House Renovations', 'secondaryButton' => 'Schedule Visit'],
        ];
        
        $kOpt = $kitchenVariations[array_rand($kitchenVariations)];
        $bOpt = $bathroomVariations[array_rand($bathroomVariations)];
        $hOpt = $homeVariations[array_rand($homeVariations)];
        
        $homeSlides = [
            [
                'title' => 'Kitchens',
                'button' => $kOpt['button'],
                'secondaryButton' => $kOpt['secondaryButton'],
                'secondaryLink' => route('contact'),
                'link' => route('services.kitchen'),
                'projectType' => 'kitchen',
                'alt' => 'Kitchen remodeling services in Chicagoland',
            ],
            [
                'title' => 'Bathrooms',
                'button' => $bOpt['button'],
                'secondaryButton' => $bOpt['secondaryButton'],
                'secondaryLink' => route('contact'),
                'link' => route('services.bathroom'),
                'projectType' => 'bathroom',
                'alt' => 'Bathroom remodeling services in Chicagoland',
            ],
            [
                'title' => 'Home Remodels',
                'button' => $hOpt['button'],
                'secondaryButton' => $hOpt['secondaryButton'],
                'secondaryLink' => route('contact'),
                'link' => route('services.home'),
                'projectType' => 'home-remodel',
                'alt' => 'Whole home remodeling services in Chicagoland',
            ],
        ];
    @endphp
    <div class="mx-auto max-w-7xl px-6 lg:px-8">
        <livewire:main-project-hero-slider 
            :slides="$homeSlides"
        />
    </div>

    {{-- About Section (lazy loaded - below fold) --}}
    <livewire:about-section lazy />

    {{-- Timelapse Section (lazy loaded - below fold) --}}
    <div class="mx-auto max-w-7xl px-6 lg:px-8">
        <livewire:timelapse-section lazy />
    </div>

    {{-- Testimonials Section (lazy loaded - below fold) --}}
    <livewire:testimonials-section lazy />

    {{-- Static Map Section (parallax with bg-fixed) - hidden for now --}}
    {{-- <livewire:static-map-section lazy /> --}}
    
    {{-- Dynamic Map Section --}}
    <livewire:map-section />

    {{-- Contact Section (lazy loaded - below fold) --}}
    <livewire:contact-section lazy />

    {{-- FAQ Section — dynamically loaded from config/faq.php (targets top search queries) --}}
    <x-faq-section 
        :faqs="collect(config('faq.faqs', []))->take(config('faq.display.homepage_limit', 5))->toArray()" 
        heading="Frequently Asked Questions"
        collapsed
    />

    {{-- Explore (server-rendered internal links). The sections above are lazy
         Livewire components that don't appear in the initial HTML, so this gives
         crawlers a set of contextual links on first load. --}}
    <section aria-label="Explore GS Construction" class="border-t border-gray-100 bg-gray-50 dark:border-white/10 dark:bg-slate-900/50">
        <div class="mx-auto max-w-7xl px-6 py-12 lg:px-8">
            <h2 class="text-center font-heading text-2xl font-bold tracking-tight text-gray-900 dark:text-white">
                Explore Our Remodeling Services
            </h2>
            <div class="mx-auto mt-8 grid max-w-4xl grid-cols-2 gap-3 sm:grid-cols-3">
                <a href="/services/kitchen-remodeling" wire:navigate.hover class="rounded-xl border border-gray-200 bg-white px-4 py-3 text-center text-sm font-semibold text-gray-900 shadow-sm transition hover:shadow-md dark:border-white/10 dark:bg-white/5 dark:text-white">Kitchen Remodeling</a>
                <a href="/services/bathroom-remodeling" wire:navigate.hover class="rounded-xl border border-gray-200 bg-white px-4 py-3 text-center text-sm font-semibold text-gray-900 shadow-sm transition hover:shadow-md dark:border-white/10 dark:bg-white/5 dark:text-white">Bathroom Remodeling</a>
                <a href="/services/basement-remodeling" wire:navigate.hover class="rounded-xl border border-gray-200 bg-white px-4 py-3 text-center text-sm font-semibold text-gray-900 shadow-sm transition hover:shadow-md dark:border-white/10 dark:bg-white/5 dark:text-white">Basement Remodeling</a>
                <a href="/services/home-additions" wire:navigate.hover class="rounded-xl border border-gray-200 bg-white px-4 py-3 text-center text-sm font-semibold text-gray-900 shadow-sm transition hover:shadow-md dark:border-white/10 dark:bg-white/5 dark:text-white">Home Additions</a>
                <a href="/services/home-remodeling" wire:navigate.hover class="rounded-xl border border-gray-200 bg-white px-4 py-3 text-center text-sm font-semibold text-gray-900 shadow-sm transition hover:shadow-md dark:border-white/10 dark:bg-white/5 dark:text-white">Whole-Home Remodeling</a>
                <a href="/services" wire:navigate.hover class="rounded-xl border border-gray-200 bg-white px-4 py-3 text-center text-sm font-semibold text-gray-900 shadow-sm transition hover:shadow-md dark:border-white/10 dark:bg-white/5 dark:text-white">All Services</a>
            </div>
            <div class="mx-auto mt-4 flex max-w-4xl flex-wrap justify-center gap-x-6 gap-y-2 text-sm">
                <a href="/projects" wire:navigate.hover class="font-medium text-sky-600 hover:text-sky-500 dark:text-sky-400">Recent Projects</a>
                <a href="/about" wire:navigate.hover class="font-medium text-sky-600 hover:text-sky-500 dark:text-sky-400">About Us</a>
                <a href="/reviews" wire:navigate.hover class="font-medium text-sky-600 hover:text-sky-500 dark:text-sky-400">Reviews</a>
                <a href="/contact" wire:navigate.hover class="font-medium text-sky-600 hover:text-sky-500 dark:text-sky-400">Free Estimate</a>
            </div>

            {{-- Featured service areas: direct homepage links to priority city
                 hubs (high impressions, currently ranking page 2). Passes
                 homepage authority to help them climb into the top 10. --}}
            @php
                $featuredAreas = [
                    'arlington-heights' => 'Arlington Heights',
                    'schaumburg' => 'Schaumburg',
                    'mount-prospect' => 'Mount Prospect',
                    'evanston' => 'Evanston',
                    'glenview' => 'Glenview',
                    'winnetka' => 'Winnetka',
                    'wilmette' => 'Wilmette',
                    'glencoe' => 'Glencoe',
                    'kenilworth' => 'Kenilworth',
                    'northbrook' => 'Northbrook',
                    'hoffman-estates' => 'Hoffman Estates',
                    'orland-park' => 'Orland Park',
                    'palos-park' => 'Palos Park',
                    'hawthorn-woods' => 'Hawthorn Woods',
                ];
            @endphp
            <h2 class="mt-12 text-center font-heading text-2xl font-bold tracking-tight text-gray-900 dark:text-white">
                Remodeling Across Chicagoland
            </h2>
            <p class="mx-auto mt-2 max-w-2xl text-center text-sm text-gray-600 dark:text-gray-400">
                Local kitchen, bathroom &amp; home remodeling in the communities we serve most.
            </p>
            <div class="mx-auto mt-6 flex max-w-4xl flex-wrap justify-center gap-2">
                @foreach ($featuredAreas as $slug => $city)
                    <a href="/areas-served/{{ $slug }}" wire:navigate.hover class="rounded-full border border-gray-200 bg-white px-4 py-1.5 text-sm font-medium text-gray-700 transition hover:border-sky-300 hover:text-sky-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-200 dark:hover:text-sky-400">{{ $city }}</a>
                @endforeach
                <a href="/areas-served" wire:navigate.hover class="rounded-full border border-sky-200 bg-sky-50 px-4 py-1.5 text-sm font-semibold text-sky-700 transition hover:bg-sky-100 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300">All 89+ Areas →</a>
            </div>
        </div>
    </section>

</x-layouts.app>
