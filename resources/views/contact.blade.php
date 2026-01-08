<x-layouts.app
    title="Contact Us | GS Construction | Family-Owned Home Remodeling"
    metaDescription="Get in touch with GS Construction for your home remodeling project. Free consultations for kitchen, bathroom, and whole-home renovations in Chicagoland."
>
    <main>
        {{-- Hero Section --}}
        <x-cta-section 
            heading="Let's Start Your Project"
            description="Ready to transform your home? Schedule a free consultation with Greg & Patryk."
            primaryText="About GS Construction"
            primaryHref="/about"
            secondaryText="View Our Work"
            secondaryHref="/projects"
        />

        {{-- Contact Section --}}
        <livewire:contact-section />

        {{-- Map Section --}}
        <livewire:map-section />

        {{-- Testimonials Section --}}
        <livewire:testimonials-section />
    </main>
</x-layouts.app>
