<x-layouts.app
    title="Remodeling Contractors"
    metaDescription="Professional kitchen, bathroom, and home remodeling services. GS Construction is a family-owned business serving the Chicagoland area."
>
    {{-- Note: LCP preload is handled by the hero slider component itself --}}

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
    <livewire:main-project-hero-slider 
        :slides="$homeSlides"
    />

    {{-- About Section (lazy loaded - below fold) --}}
    <livewire:about-section lazy />

    {{-- Timelapse Section (lazy loaded - below fold) --}}
    <livewire:timelapse-section lazy />

    {{-- Testimonials Section (lazy loaded - below fold) --}}
    <livewire:testimonials-section lazy />
    
    {{-- Static Map Section (parallax with bg-fixed) - hidden for now --}}
    {{-- <livewire:static-map-section lazy /> --}}
    
    {{-- Dynamic Map Section --}}
    <livewire:map-section />

    {{-- Contact Section (lazy loaded - below fold) --}}
    <livewire:contact-section lazy />

</x-layouts.app>
