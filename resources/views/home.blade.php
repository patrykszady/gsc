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

</x-layouts.app>
