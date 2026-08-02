<div>
    {{-- Breadcrumb Schema --}}

    {{-- Product Schema per service — emits review-star / offer rich-result markup
         for every service we provide on the /services index, not just the detail
         pages. Each node's @id points at its canonical /services/{slug}#product. --}}
    @foreach ($this->services as $service)
        <x-product-service-schema :service-slug="$service['slug']" />
    @endforeach

    {{-- ItemList (summary-page carousel pattern) — groups the service Products so
         Google can treat /services as a carousel. Each ListItem points at the
         /services/{slug} detail page where the full Product node lives. --}}
    @php
        $serviceListItems = [];
        foreach ($this->services as $i => $service) {
            $serviceListItems[] = [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'url'      => url("/services/{$service['slug']}"),
                'name'     => $service['title'],
            ];
        }
        $serviceItemList = [
            '@context'        => 'https://schema.org',
            '@type'           => 'ItemList',
            '@id'             => url('/services') . '#service-list',
            'name'            => 'Remodeling Services — GS Construction',
            'itemListOrder'   => 'https://schema.org/ItemListOrderAscending',
            'numberOfItems'   => count($serviceListItems),
            'itemListElement' => $serviceListItems,
        ];
    @endphp
    <script type="application/ld+json">{!! json_encode($serviceItemList, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>

    <x-breadcrumbs :items="[
        ['name' => 'Services'],
    ]" padding="py-4" />

    {{-- Hero Section with Image Slider --}}
    @php
        $serviceSlides = [
            [
                'heading' => 'Kitchen Remodeling Contractors',
                'subheading' => 'Transform your kitchen with custom cabinets, countertops, and complete renovations',
                'type' => 'kitchen',
            ],
            [
                'heading' => 'Bathroom Remodeling Company',
                'subheading' => 'Create your personal spa retreat with luxury showers, tubs, and tile work',
                'type' => 'bathroom',
            ],
            [
                'heading' => 'Home Remodeling Contractors',
                'subheading' => 'Complete home renovations, room additions, and open floor plans',
                'type' => 'home-remodel',
            ],
            [
                'heading' => 'Residential Remodeling Company',
                'subheading' => 'Upgrade your home with a clear plan, quality craftsmanship, and honest guidance',
                'type' => 'home-remodel',
            ],
            [
                'heading' => 'Kitchen Remodeling Company',
                'subheading' => 'Design-forward kitchens built for everyday living and long-term value',
                'type' => 'kitchen',
            ],
            [
                'heading' => 'Bathroom Remodeling Contractors',
                'subheading' => 'Modernize your bathroom with custom tile, lighting, and fixture upgrades',
                'type' => 'bathroom',
            ],
            [
                'heading' => 'Home Renovation Company',
                'subheading' => 'Thoughtful renovations that improve function, comfort, and resale value',
                'type' => 'home-remodel',
            ],
            [
                'heading' => 'Kitchen Renovation Contractors',
                'subheading' => 'From layout planning to finish selections, we handle the full build',
                'type' => 'kitchen',
            ],
            [
                'heading' => 'Bathroom Renovation Company',
                'subheading' => 'Clean, modern bathrooms with durable materials and expert installation',
                'type' => 'bathroom',
            ],
            [
                'heading' => 'Home Improvement Contractors',
                'subheading' => 'A dependable remodeling team for upgrades, updates, and full renovations',
                'type' => 'home-remodel',
            ],
            [
                'heading' => 'Kitchen & Bath Remodeling',
                'subheading' => 'Premium finishes, precise craftsmanship, and a process you can trust',
                'type' => 'kitchen',
            ],
            [
                'heading' => 'Full Service Remodeling Company',
                'subheading' => 'Plan, design, and build with one team from start to finish',
                'type' => 'home-remodel',
            ],
            [
                'heading' => 'Residential Remodeling Contractors',
                'subheading' => 'Reliable timelines, transparent pricing, and quality work you’ll notice',
                'type' => 'home-remodel',
            ],
            [
                'heading' => 'Bathroom Remodel Contractors',
                'subheading' => 'Upgrade fixtures, tile, and layout for a better daily routine',
                'type' => 'bathroom',
            ],
            [
                'heading' => 'Kitchen Remodel Contractors',
                'subheading' => 'Smart storage, durable surfaces, and a kitchen built to last',
                'type' => 'kitchen',
            ],
        ];

        shuffle($serviceSlides);
    @endphp

    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <livewire:main-project-hero-slider
            project-type="mixed"
            :slides="$serviceSlides"
            primary-cta-text="Get a Free Quote"
            primary-cta-url="/contact"
            secondary-cta-text="View Our Work"
            secondary-cta-url="/projects"
        />
    </div>

    {{-- Services Grid — the six cards come from partials/services-grid, the
         SAME partial /contact and the area pages render, with the data in
         config/sites/gsc/services-content.php 'grid'. This page carried its own
         near-verbatim copy of the cards AND its own fork of the data array in
         ServicesPage::getServicesProperty(); the two had already drifted
         (hand-rolled blur-up <img> vs <x-lqip-image>). The page keeps only its
         unique SEO intro below, then includes the shared grid. --}}
    <section class="pt-16 sm:pt-24">
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            <div class="mx-auto max-w-3xl text-center">
                <h2 class="text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl dark:text-white">
                    Services provided by GS Construction &amp; Remodeling
                </h2>
                <p class="mt-4 text-base leading-7 text-zinc-600 sm:text-lg sm:leading-8 dark:text-zinc-400">
                    Services provided by GS Construction &amp; Remodeling encompass a wide range of home improvement solutions tailored to elevate your living space in the Chicago suburbs. We specialize in kitchen remodeling, bathroom remodeling, whole-home renovations, basement finishing, and seamless home additions. As a family-owned and fully licensed general contractor with over 40 years of combined experience, we are committed to delivering exceptional craftsmanship, transparent pricing, and personalized service. Let us bring your vision to life with a free in-home consultation throughout Arlington Heights, Palatine, Schaumburg, and the wider Chicagoland area.
                </p>
            </div>
        </div>
    </section>

    @include('partials.services-grid', ['showCta' => false])

    {{-- FAQ Section --}}
    {{-- sectionClasses trims the FAQ's default pt-8: the services grid above
         already ends on pb-16 sm:pb-24, so the two stacked into a gap roughly
         twice any other section break on the page. --}}
    <x-faq-section :faqs="$faqs" heading="Our Services FAQ"
        sectionClasses="bg-white pt-0 pb-6 sm:pb-8 dark:bg-zinc-900" />

    {{-- CTA Section --}}
    <x-cta-section 
        variant="blue"
        heading="Ready to Start Your Project?"
        description="Get a free consultation and quote for your remodeling project. GS Construction is ready to bring your vision to life."
        primaryText="Get Free Quote"
        primaryHref="{{ route('contact') }}"
        secondaryText="View Our Work"
        secondaryHref="{{ route('projects.index') }}"
    />
</div>
