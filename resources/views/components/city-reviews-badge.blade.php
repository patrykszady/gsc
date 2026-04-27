@props(['area'])

@php
    use App\Models\Testimonial;

    $cityName = $area->city ?? null;
    if (! $cityName) {
        return;
    }

    $cacheKey = 'city_reviews_'.md5($cityName);
    $data = \Illuminate\Support\Facades\Cache::remember($cacheKey, 3600, function () use ($cityName) {
        $q = Testimonial::query()
            ->visible()
            ->where('project_location', 'LIKE', '%'.$cityName.'%');

        $count = (clone $q)->count();
        $avg   = (float) (clone $q)->avg('star_rating') ?: 5.0;
        $items = (clone $q)
            ->orderByDesc('review_date')
            ->limit(5)
            ->get(['id', 'reviewer_name', 'star_rating', 'review_description', 'project_location', 'project_type', 'review_date']);

        return [
            'count' => $count,
            'avg'   => round($avg, 1),
            'items' => $items,
        ];
    });

    if (($data['count'] ?? 0) < 1) {
        return;
    }

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
@endphp

<div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
    <div class="mt-4 flex flex-wrap items-center justify-center gap-3 rounded-xl bg-sky-50 px-4 py-3 text-center dark:bg-sky-950/30">
        <div class="flex items-center gap-1 text-amber-500" aria-hidden="true">
            @for ($i = 0; $i < 5; $i++)
                <svg class="h-5 w-5 fill-current" viewBox="0 0 20 20"><path d="M9.05.927C9.349.011 10.651.011 10.95.927l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.921-.755 1.688-1.539 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.539-1.118l1.518-4.674a1 1 0 00-.363-1.118L2.074 8.1c-.783-.57-.38-1.81.588-1.81h4.915a1 1 0 00.95-.69L9.05.927z"/></svg>
            @endfor
        </div>
        <p class="text-sm font-medium text-sky-900 dark:text-sky-200">
            <span class="font-bold">{{ $data['avg'] }}/5</span>
            from
            <span class="font-bold">{{ $data['count'] }}</span>
            verified {{ \Illuminate\Support\Str::plural('review', $data['count']) }} from
            {{ $cityName }} homeowners
        </p>
        <a href="{{ $area->pageUrl('testimonials') }}"
           wire:navigate
           class="text-xs font-semibold text-sky-700 underline-offset-2 hover:underline dark:text-sky-300">
            Read all &rarr;
        </a>
    </div>
</div>

@push('head')
<script type="application/ld+json">
{!! json_encode([
    '@context'        => 'https://schema.org',
    '@type'           => 'LocalBusiness',
    '@id'             => url($area->url) . '#city-reviews',
    'name'            => 'GS Construction',
    'areaServed'      => ['@type' => 'City', 'name' => $cityName.', IL'],
    'aggregateRating' => $aggregate,
    'review'          => $reviews,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
</script>
@endpush
