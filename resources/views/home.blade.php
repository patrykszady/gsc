<x-layouts.app
    title="GS Construction | Remodeling Contractors | Family Business"
    metaDescription="Professional kitchen, bathroom, and home remodeling services. GS Construction is a family-owned business serving the Chicagoland area."
>
    {{-- Note: LCP preload is handled by the hero slider component itself --}}

    {{-- Main Project Hero Slider --}}
    @php
        $homeSlides = [
            [
                'title' => 'Kitchens',
                'button' => 'Kitchen Remodeling',
                'link' => route('services.kitchen'),
                'projectType' => 'kitchen',
                'alt' => 'Kitchen remodeling services in Chicagoland',
            ],
            [
                'title' => 'Bathrooms',
                'button' => 'Bathroom Remodeling',
                'link' => route('services.bathroom'),
                'projectType' => 'bathroom',
                'alt' => 'Bathroom remodeling services in Chicagoland',
            ],
            [
                'title' => 'Home Remodels',
                'button' => 'Home Remodeling',
                'link' => route('services.home'),
                'projectType' => 'home-remodel',
                'alt' => 'Whole home remodeling services in Chicagoland',
            ],
        ];
    @endphp
    <livewire:main-project-hero-slider 
        :slides="$homeSlides"
        secondary-cta-text="Schedule Free Consult"
        :secondary-cta-url="route('contact')"
    />

    {{-- About Section (lazy loaded - below fold) --}}
    <livewire:about-section lazy />

    {{-- Timelapse Section (lazy loaded - below fold) --}}
    <livewire:timelapse-section lazy />

    {{-- Testimonials Section (lazy loaded - below fold) --}}
    <livewire:testimonials-section lazy />
    
    {{-- Map Parallax Section (lazy loaded - below fold) --}}
    <livewire:map-section lazy />

    {{-- Contact Section (lazy loaded - below fold) --}}
    <livewire:contact-section lazy />

</x-layouts.app>
