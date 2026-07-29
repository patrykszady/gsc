@props([
    'heading' => 'Want a second opinion before you decide?',
    'primaryText' => 'Get a free estimate',
    'primaryHref' => '/contact',
    'secondaryText' => 'Call (224) 735-4200',
    'secondaryHref' => 'tel:2247354200',
])

{{-- Compact mid-page CTA band — shared so every page's "soft ask" looks the same. --}}
<div class="mt-12 rounded-2xl bg-sky-50 px-6 py-6 text-center ring-1 ring-sky-100 dark:bg-sky-950/40 dark:ring-sky-900 sm:flex sm:items-center sm:justify-between sm:text-left">
    <p class="font-heading text-lg font-semibold text-zinc-900 dark:text-white">
        {{ $heading }}
    </p>
    <div class="mt-4 flex flex-wrap items-center justify-center gap-3 sm:mt-0">
        <a href="{{ $primaryHref }}" wire:navigate class="rounded-md bg-sky-600 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-500">
            {{ $primaryText }}
        </a>
        <a href="{{ $secondaryHref }}" class="rounded-md px-4 py-2 text-sm font-semibold text-sky-700 ring-1 ring-sky-300 hover:bg-white dark:text-sky-300 dark:ring-sky-700">
            {{ $secondaryText }}
        </a>
    </div>
</div>
