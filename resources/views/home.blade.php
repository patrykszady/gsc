<x-layouts.app
    title="GS Construction | Remodeling Contractors | Family Business"
    metaDescription="Professional kitchen, bathroom, and home remodeling services. GS Construction is a family-owned business serving the Chicagoland area."
>
    {{-- Preload LCP image for faster paint --}}
    @push('head')
    <link rel="preload" as="image" href="{{ asset('images/greg-patryk.webp') }}" type="image/webp" fetchpriority="high">
    @endpush

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

    {{-- About Section --}}
    <livewire:about-section />

    {{-- Timelapse Section --}}
    <livewire:timelapse-section />

    {{-- Testimonials Section --}}
    <livewire:testimonials-section />
    
    {{-- Map Parallax Section --}}
    <livewire:map-section />

    {{-- Contact Section --}}
    <livewire:contact-section />

</x-layouts.app>
