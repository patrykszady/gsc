@props([
    // Ordered trail AFTER Home: [['label' => 'Compare', 'url' => '/compare'], ['label' => 'Airoom']]
    // The last item (no url, or url omitted) is the current page.
    'items' => [],

    // Container width. Defaults to the site's max-w-7xl content column; the
    // narrow article pages (/costs/*, /permits/*, /insurance-claims/*) run at
    // max-w-3xl and need the trail to line up with their own text column.
    'maxWidth' => 'max-w-7xl',

    // Vertical padding. Narrow pages that place the trail above their own
    // heading block want less room beneath it.
    'padding' => 'py-6',
])

{{--
    Visible breadcrumb nav + BreadcrumbList schema from ONE items array, so the
    two can never drift. Replaces the hand-rolled <nav aria-label="Breadcrumb">
    blocks that were copy-pasted (with the same chevron svg) across 20 views —
    each of which ALSO declared the trail a second time as <x-breadcrumb-schema>.
    Two declarations per page had already drifted on 7 of them: /trades said
    "Trade Partners" to the reader and "Our Trade Partners" to Google, and the
    /costs, /permits and /insurance-claims detail pages omitted the current page
    from the visible trail entirely.

    Accepts either 'label' or 'name' per item. 'name' is the shape the old
    schema arrays used, so pages that build their trail in a PHP block (project,
    area, ZIP, projects index) pass their existing array straight through.
    Do NOT write the php directive name in this comment — Blade compiles
    directives inside comments, which turns this file into a syntax error.
--}}
@php
    $crumbs = collect($items)
        ->map(fn ($i) => [
            'label' => $i['label'] ?? $i['name'] ?? '',
            'url' => $i['url'] ?? null,
        ])
        ->filter(fn ($i) => $i['label'] !== '')
        ->values();
@endphp

<x-breadcrumb-schema :items="$crumbs->map(fn ($i) => array_filter([
    'name' => $i['label'],
    'url' => $i['url'],
]))->all()" />

<div class="mx-auto {{ $maxWidth }} px-4 {{ $padding }} sm:px-6 lg:px-8">
    <nav class="flex" aria-label="Breadcrumb">
        <ol class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
            <li>
                <a href="/" wire:navigate class="text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Home</a>
            </li>
            @foreach($crumbs as $item)
                <li class="flex items-center">
                    <svg class="h-4 w-4 shrink-0 text-gray-500" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" /></svg>
                    @if(! empty($item['url']) && ! $loop->last)
                        <a href="{{ $item['url'] }}" wire:navigate class="ml-2 text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">{{ $item['label'] }}</a>
                    @else
                        <span class="ml-2 text-gray-700 dark:text-gray-300" @if($loop->last) aria-current="page" @endif>{{ $item['label'] }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>
</div>
