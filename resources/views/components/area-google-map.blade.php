@props([
    'query' => 'Chicago, IL',
    'title' => 'Service area map',
    'heading' => null,
    'height' => 'h-80 sm:h-96',
])
@php
    $src = 'https://www.google.com/maps?q=' . urlencode((string) $query) . '&output=embed';
    $linkUrl = 'https://www.google.com/maps/search/?api=1&query=' . urlencode((string) $query);
@endphp
<section class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
    @if ($heading)
        <h2 class="mb-4 text-2xl font-bold text-gray-900 dark:text-white">{{ $heading }}</h2>
    @endif
    <div class="overflow-hidden rounded-lg ring-1 ring-gray-200 dark:ring-gray-700 {{ $height }}">
        <iframe
            src="{{ $src }}"
            title="{{ $title }}"
            loading="lazy"
            referrerpolicy="no-referrer-when-downgrade"
            class="h-full w-full border-0"
            allowfullscreen></iframe>
    </div>
    <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
        <a href="{{ $linkUrl }}" target="_blank" rel="noopener" class="text-amber-700 hover:underline dark:text-amber-400">
            Open {{ $query }} in Google Maps &rarr;
        </a>
    </p>
</section>
