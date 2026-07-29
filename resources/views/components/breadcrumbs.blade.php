@props([
    // Ordered trail AFTER Home: [['label' => 'Compare', 'url' => '/compare'], ['label' => 'Airoom']]
    // The last item (no url, or url omitted) is the current page.
    'items' => [],
])

{{--
    Visible breadcrumb nav + BreadcrumbList schema from ONE items array, so the
    two can never drift. Replaces the hand-rolled <nav aria-label="Breadcrumb">
    blocks that were copy-pasted (with the same chevron svg) across 10+ views.
--}}
<x-breadcrumb-schema :items="collect($items)->map(fn ($i) => array_filter([
    'name' => $i['label'] ?? '',
    'url' => $i['url'] ?? null,
]))->all()" />

<div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
    <nav class="flex" aria-label="Breadcrumb">
        <ol class="flex flex-wrap items-center gap-y-1 space-x-2 text-sm">
            <li>
                <a href="/" wire:navigate class="text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Home</a>
            </li>
            @foreach($items as $item)
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
