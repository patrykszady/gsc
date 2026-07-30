@props([
    // 'check' = blue circle with a checkmark (benefits/proof lists)
    // 'circle' = blue ring, no checkmark (neutral steps/checklists)
    'marker' => 'check',
    // Semantic list tag: 'ul', or 'ol' to keep step order for screen readers
    // while showing circles instead of numbers.
    'tag' => 'ul',
    'items' => [],
    'itemClasses' => '',
])

{{--
    Shared marker list. Consolidates the flex + inline-SVG bullet markup that
    was hand-rolled per page, so every list on the site uses one idiom.
    Pass plain-text items, or omit `items` and supply <li class="flex ..."> rows
    via the slot when an item needs inline links/markup.
--}}
<{{ $tag }} {{ $attributes->merge(['class' => 'mt-4 space-y-3 text-zinc-700 dark:text-zinc-300']) }}>
    @foreach($items as $item)
        <li class="flex items-start gap-2.5 {{ $itemClasses }}">
            @if($marker === 'check')
                <svg class="mt-0.5 size-5 shrink-0 text-sky-600 dark:text-sky-400" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                </svg>
            @else
                <span class="mt-1 size-4 shrink-0 rounded-full border-2 border-sky-600 dark:border-sky-400" aria-hidden="true"></span>
            @endif
            <span>{!! $item !!}</span>
        </li>
    @endforeach
    {{ $slot }}
</{{ $tag }}>
