<x-layouts.app
    title="Customer Reviews & Testimonials"
    metaDescription="Read testimonials from our satisfied customers. See what homeowners say about GS Construction's kitchen, bathroom, and home remodeling services in the Chicagoland area."
>
    {{-- Breadcrumb Schema --}}
    <x-breadcrumb-schema :items="[
        ['name' => 'Testimonials'],
    ]" />

    {{-- Visual Breadcrumb --}}
    <div class="mx-auto max-w-7xl px-4 py-4 sm:px-6 lg:px-8">
        <nav class="flex" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-2 text-sm">
                <li>
                    <a href="/" wire:navigate class="text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Home</a>
                </li>
                <li class="flex items-center">
                    <svg class="h-4 w-4 flex-shrink-0 text-gray-500" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                    </svg>
                    <span class="ml-2 text-gray-700 dark:text-gray-300">Testimonials</span>
                </li>
            </ol>
        </nav>
    </div>

    <livewire:testimonials-grid />

    {{-- Rich citation block: ItemList of recent reviews + per-platform sources --}}
    <x-review-citations />

    {{-- FAQ Section --}}
    @php
        $faqs = [
            ['question' => 'Are your customer reviews real?', 'answer' => 'Yes, all reviews featured on our site are from verified customers. Most come directly from our Google Business Profile and can be independently verified there.'],
            ['question' => 'How many reviews does GS Construction have?', 'answer' => 'We have over 53 five-star reviews on Google from homeowners across the Chicagoland area. Our consistent 5-star rating reflects our commitment to quality and customer satisfaction.'],
            ['question' => 'Can I speak with a past client before hiring you?', 'answer' => 'Absolutely! We are happy to connect you with previous clients who can share their experience working with GS Construction. Just ask during your consultation.'],
            ['question' => 'What areas do your reviewers come from?', 'answer' => 'Our clients come from across Chicagoland including Arlington Heights, Palatine, Mount Prospect, Schaumburg, Buffalo Grove, and 80+ other communities in the Northwest Suburbs and North Shore.'],
        ];
    @endphp
    <x-faq-section :faqs="$faqs" heading="Customer Reviews FAQ" />

    <livewire:map-section />

    <livewire:testimonials-section :show-header="false" />
</x-layouts.app>
