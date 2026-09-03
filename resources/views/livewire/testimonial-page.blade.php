{{-- Same decorated canvas as /compare and /compare/*, via the shared
     component rather than another hand-rolled root. --}}
<x-page-canvas>
    {{-- Breadcrumb Schema --}}

    {{-- Review Schema for rich results --}}
    <x-review-schema :testimonial="$testimonial" />

    <x-breadcrumbs :items="[
        ['name' => 'Reviews', 'url' => route('reviews.index')],
        ['name' => $testimonial->display_name],
    ]" maxWidth="max-w-5xl" padding="pt-8" />

    {{-- Main Content --}}
    <div class="mx-auto max-w-5xl px-6 py-6 lg:px-8 lg:py-8">
        {{-- Project Thumbnail --}}
        {{-- 21/9 rather than 16/9: at this column width 16/9 stood 576px tall
             and pushed the review itself below the fold on a laptop. --}}
        @if($thumbnailUrl)
            <div class="mb-6 overflow-hidden rounded-2xl shadow-sm ring-1 ring-zinc-900/5">
                <x-lqip-image
                    :src="$thumbnailUrl"
                    :thumb="$thumbnailThumbUrl ?? $thumbnailUrl"
                    alt="{{ ucfirst($testimonial->project_type ?? 'Project') }} remodeling in {{ $testimonial->project_location }}"
                    aspectRatio="21/9"
                    class="w-full"
                />
            </div>
        @endif

        <x-review-card
            :testimonial="$testimonial"
            :paragraphs="$reviewParagraphs"
            :image-url="$imageUrl"
            :area-slug="$areaSlug"
            :title="$testimonial->display_name . '\'s ' . ucfirst($testimonial->project_type ?? 'Home') . ' Remodeling Review in ' . $testimonial->project_location"
        />

        {{-- Back link --}}
        <div class="relative z-10 mt-6 border-t border-gray-200 pt-4 dark:border-gray-700">
            <a
                href="/reviews"
                class="inline-flex items-center gap-2 text-sm font-medium text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-300"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                </svg>
                Back to all reviews
            </a>
        </div>

    </div>

    {{-- More Testimonials Section --}}
    {{-- No bg-white here (or on the FAQ below): these sections used to paint an
         opaque panel over the page canvas, so the drafting grid stopped dead
         behind them while continuing above and below. Transparent lets the
         canvas run the full height, which is how /compare/* does it. --}}
    <livewire:testimonials-section :show-header="false" max-width-class="max-w-5xl" section-classes="relative isolate overflow-hidden bg-transparent pt-0 pb-4 sm:pb-6" />

    {{-- FAQ Section --}}
    <x-faq-section :faqs="$faqs" heading="FAQ About This Review" sectionClasses="bg-transparent pt-0 pb-10 sm:pb-14" content-max-width="max-w-[60rem]" />
</x-page-canvas>
