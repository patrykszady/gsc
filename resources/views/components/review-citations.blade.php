@blaze(memo: true)
@php
use App\Models\Testimonial;

$recent = Testimonial::visible()
    ->whereNotNull('review_date')
    ->with('reviewUrls')
    ->latest('review_date')
    ->limit(20)
    ->get();

$total = Testimonial::count();

$itemList = [
    '@context' => 'https://schema.org',
    '@type'    => 'ItemList',
    'name'     => 'Recent verified customer reviews of GS Construction',
    'numberOfItems' => $recent->count(),
    'itemListOrder' => 'https://schema.org/ItemListOrderDescending',
    'isPartOf' => ['@id' => 'https://gs.construction/#website'],
    'about'    => ['@id' => 'https://gs.construction/#business'],
    'itemListElement' => $recent->values()->map(function (Testimonial $t, int $i) {
        $first = $t->reviewUrls->first();
        $publisher = $first ? match (strtolower($first->platform)) {
            'google'   => 'Google Reviews',
            'houzz'    => 'Houzz',
            'yelp'     => 'Yelp',
            'angi'     => 'Angi',
            'facebook' => 'Facebook',
            default    => ucfirst($first->platform),
        } : null;

        return [
            '@type'    => 'ListItem',
            'position' => $i + 1,
            'item'     => array_filter([
                '@type'         => 'Review',
                'name'          => trim($t->display_name . ' — ' . ($t->project_type ? ucfirst($t->project_type) . ' remodel' : 'Remodeling') . ' review'),
                'reviewBody'    => $t->review_description,
                'datePublished' => ($t->review_date ?? $t->created_at)->toIso8601String(),
                'url'           => $first?->url,
                'reviewRating'  => [
                    '@type' => 'Rating',
                    'ratingValue' => (string) ($t->star_rating ?: 5),
                    'bestRating'  => '5',
                ],
                'author' => [
                    '@type' => 'Person',
                    'name'  => $t->display_name,
                ],
                'publisher' => $publisher ? [
                    '@type' => 'Organization',
                    'name'  => $publisher,
                ] : null,
                'itemReviewed' => ['@id' => 'https://gs.construction/#business'],
                'locationCreated' => $t->project_location ? [
                    '@type' => 'Place',
                    'address' => [
                        '@type' => 'PostalAddress',
                        'addressLocality' => preg_replace('/,\s*[A-Z]{2}$/', '', $t->project_location),
                        'addressRegion' => 'IL',
                        'addressCountry' => 'US',
                    ],
                ] : null,
            ]),
        ];
    })->all(),
];
@endphp

<script type="application/ld+json">
{!! json_encode($itemList, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
</script>

{{-- Visible citation summary block (helps AI Overviews quote with attribution) --}}
<section class="bg-white py-10 dark:bg-zinc-900" aria-label="Review sources">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="rounded-2xl bg-zinc-50 p-6 ring-1 ring-zinc-200 sm:p-8 dark:bg-zinc-800/50 dark:ring-zinc-700">
            <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                Verified across {{ $total }}+ reviews
            </h2>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
                GS Construction maintains a 5-star rating across multiple independent review platforms.
                Every review on this page is a real customer review you can verify directly at the source:
            </p>
            <ul class="mt-4 grid gap-2 text-sm sm:grid-cols-2">
                <li><a href="{{ config('socials.google.url') }}" rel="noopener external" class="text-sky-700 hover:underline dark:text-sky-400">Google Reviews →</a></li>
                <li><a href="{{ config('socials.houzz.url') }}" rel="noopener external" class="text-sky-700 hover:underline dark:text-sky-400">Houzz →</a></li>
                <li><a href="{{ config('socials.yelp.url') }}" rel="noopener external" class="text-sky-700 hover:underline dark:text-sky-400">Yelp →</a></li>
                <li><a href="{{ config('socials.angi.url') }}" rel="noopener external" class="text-sky-700 hover:underline dark:text-sky-400">Angi →</a></li>
            </ul>
        </div>
    </div>
</section>
