@props(['area'])

@php
    use App\Models\Testimonial;

    $cityName = $area->city ?? null;
    $data = null;
    $reviews = [];
    $aggregate = null;

    if ($cityName) {
        // Same set the quote cards below render — a strict
        // project_location LIKE '%City%' gave Barrington "1 verified review"
        // sitting directly above three cards, and beside the words "and
        // surrounding homeowners". Topped up from the nearest towns.
        $cacheKey = \App\Support\Tenancy::cacheKey('city_reviews_'.md5($cityName));
        $data = \Illuminate\Support\Facades\Cache::remember($cacheKey, 3600, function () use ($area) {
            // 3, matching the number of quote cards rendered below — the badge
            // must never claim a count the page does not show.
            $items = $area->testimonialsWithNeighbours(3);

            return [
                'count' => $items->count(),
                'avg'   => round((float) ($items->avg('star_rating') ?: 5.0), 1),
                'items' => $items,
            ];
        });

        if (($data['count'] ?? 0) >= 1) {
            $reviews = collect($data['items'])->map(function ($t) {
                return [
                    '@type'         => 'Review',
                    'reviewRating'  => [
                        '@type'       => 'Rating',
                        'ratingValue' => (string) ($t->star_rating ?? 5),
                        'bestRating'  => '5',
                    ],
                    'author'        => ['@type' => 'Person', 'name' => $t->display_name],
                    'datePublished' => optional($t->review_date)->toDateString(),
                    'reviewBody'    => \Illuminate\Support\Str::limit((string) $t->review_description, 500),
                ];
            })->filter(fn ($r) => ! empty($r['reviewBody']))->values()->all();

            $aggregate = [
                '@type'       => 'AggregateRating',
                'ratingValue' => (string) $data['avg'],
                'reviewCount' => (string) $data['count'],
                'bestRating'  => '5',
                'worstRating' => '1',
            ];
        }
    }

    // Built inside @php on purpose: Laravel 12 ships a real @context Blade
    // directive, so a literal '@context' key in template text gets compiled
    // into PHP and corrupts the emitted JSON-LD.
    $cityReviewsSchemaJson = ($cityName && $aggregate) ? json_encode([
        '@context'        => 'https://schema.org',
        '@type'           => 'LocalBusiness',
        '@id'             => url($area->url) . '#city-reviews',
        'name'            => 'GS Construction',
        // Service-area business: single real HQ address. The city this node is
        // about is conveyed via areaServed below, not a fabricated local address.
        'address'         => [
            '@type'           => 'PostalAddress',
            'addressLocality' => 'Prospect Heights',
            'addressRegion'   => 'IL',
            'postalCode'      => '60070',
            'addressCountry'  => 'US',
        ],
        'areaServed'      => ['@type' => 'City', 'name' => $cityName.', IL'],
        // No aggregateRating here: self-serving LocalBusiness ratings are ignored
        // for stars (2019 policy) and would compete with the page's rated Product
        // nodes — the only entities eligible to render stars. Reviews stay for
        // E-E-A-T / AI-answer surfaces; the visible badge still shows the average.
        'review'          => $reviews,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
@endphp

@if($cityName && $aggregate)
<div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <div class="mt-4 flex flex-wrap items-center justify-center gap-3 rounded-xl bg-sky-50 px-4 py-3 text-center dark:bg-sky-950/30">
        <div aria-hidden="true" class="shrink-0">
            <img src="{{ asset('images/5-stars.svg') }}" alt="" width="140" height="20" class="h-5 w-auto dark:hidden" />
            <img src="{{ asset('images/5-stars-dark.svg') }}" alt="" width="140" height="20" class="hidden h-5 w-auto dark:block" />
        </div>
        <p class="text-sm font-medium text-sky-900 dark:text-sky-200">
            <span class="font-bold">{{ $data['avg'] }}/5</span>
            from
            <span class="font-bold">{{ $data['count'] }}</span>
            verified {{ \Illuminate\Support\Str::plural('review', $data['count']) }} from
            {{ $cityName }} and surrounding homeowners
        </p>
        <a href="{{ $area->pageUrl('testimonials') }}"
           wire:navigate
           class="text-xs font-semibold text-sky-700 underline-offset-2 hover:underline dark:text-sky-300">
            Read all &rarr;
        </a>
    </div>
</div>

@push('head')
<script type="application/ld+json">{!! $cityReviewsSchemaJson !!}</script>
@endpush
@endif
