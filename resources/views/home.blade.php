<x-layouts.app
    :title="isset($area) ? 'Home Remodeling in ' . $area->city . ' | GS Construction | Family Business' : 'GS Construction | Home Remodeling | Family Business'"
    :metaDescription="isset($area) ? 'Professional kitchen, bathroom, and home remodeling services in ' . $area->city . '. GS Construction is a family-owned business serving ' . $area->city . ' and surrounding areas.' : 'Professional kitchen, bathroom, and home remodeling services. GS Construction is a family-owned business serving the Chicagoland area.'"
>
    {{-- Main Project Hero Slider --}}
    <livewire:main-project-hero-slider :area="$area ?? null" />

    {{-- About Section --}}
    <livewire:about-section :area="$area ?? null" />

    {{-- Timelapse Section --}}
    <livewire:timelapse-section />

    {{-- Testimonials Section --}}
    <livewire:testimonials-section :area="$area ?? null" />
    
    {{-- Map Parallax Section --}}
    <livewire:map-section :area="$area ?? null" />

    {{-- Contact Section --}}
    <livewire:contact-section :area="$area ?? null" />

</x-layouts.app>
