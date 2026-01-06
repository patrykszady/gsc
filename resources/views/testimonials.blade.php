<x-layouts.app
    :title="isset($area) ? 'Testimonials in ' . $area->city . ' | GS Construction' : 'GS Construction | Testimonials'"
    :metaDescription="isset($area) ? 'Read testimonials from our satisfied customers in ' . $area->city . '. See what homeowners say about GS Construction\'s kitchen, bathroom, and home remodeling services.' : 'Read testimonials from our satisfied customers. See what homeowners say about GS Construction\'s kitchen, bathroom, and home remodeling services in the Chicagoland area.'"
>
    <livewire:testimonials-grid :area="$area ?? null" />

    <livewire:map-section :area="$area ?? null" />

    <livewire:testimonials-section :area="$area ?? null" />
</x-layouts.app>
