@props([
    'heading' => 'Want a second opinion before you decide?',
    'primaryText' => 'Get a free estimate',
    'primaryHref' => '/contact',
    'secondaryText' => 'Call ' . config('brand.phone'),
    'secondaryHref' => 'tel:' . config('brand.phone_href'),
])

{{-- Compact mid-page CTA band — shared so every page's "soft ask" looks the same. --}}
<div class="mt-12 rounded-2xl bg-sky-50 px-6 py-6 text-center ring-1 ring-sky-100 dark:bg-sky-950/40 dark:ring-sky-900 sm:flex sm:items-center sm:justify-between sm:text-left">
    <p class="font-heading text-lg font-semibold text-zinc-900 dark:text-white">
        {{ $heading }}
    </p>
    {{-- <x-buttons.cta>, not hand-rolled anchors: these hovered sky-500
         while every other primary on the site hovers sky-700 — the drift the
         shared component exists to prevent. --}}
    <div class="mt-4 flex flex-wrap items-center justify-center gap-3 sm:mt-0">
        <x-buttons.cta :href="$primaryHref" size="sm">
            {{ $primaryText }}
        </x-buttons.cta>
        <x-buttons.cta :href="$secondaryHref" variant="outline" size="sm">
            {{ $secondaryText }}
        </x-buttons.cta>
    </div>
</div>
