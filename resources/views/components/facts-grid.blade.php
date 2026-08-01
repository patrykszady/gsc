{{--
    A labelled four-up grid of facts with an optional heading and footnote.

    Merges two blocks that had been written separately but are the same thing:

      · the service page's "at a glance" strip
        (Typical range / Typical build / Permits / Warranty)
      · <x-area-pricing-guide>'s "What remodeling costs in {city}"
        (Kitchen / Bathroom / Basement / Addition ranges)

    Both are "four labelled figures plus a caveat line", and they had drifted
    into different type scales, spacing and card treatments.

    Props
      items     array — each ['label' =>, 'value' =>, 'note' => null].
                        Also accepts a plain ['Label' => 'Value'] map, which is
                        the shape the service config already uses.
      heading   string|null — omit for a bare strip (the service pages).
      subheading, linkText, linkHref — optional header furniture.
      boxed     bool — true renders each fact on its own tinted tile (the area
                       pricing guide's look); false is the flat strip.
      card      bool — wrap the whole thing in a bordered card.

    Footnote goes in the default slot so it can carry links.
--}}

@props([
    'items' => [],
    'heading' => null,
    'subheading' => null,
    'linkText' => null,
    'linkHref' => null,
    'boxed' => false,
    'card' => false,
])

@php
    // Normalise both accepted shapes to one list.
    $factItems = collect($items)->map(function ($item, $key) {
        if (is_array($item)) {
            return [
                'label' => $item['label'] ?? (is_string($key) ? $key : ''),
                'value' => $item['value'] ?? $item['range'] ?? '',
                'note' => $item['note'] ?? null,
                // A fact that has a page explaining it links to it — the warranty
                // fact is the reason this exists, so "our commitment never
                // expires" is one tap from /warranty rather than a dead claim.
                'href' => $item['href'] ?? null,
            ];
        }

        return ['label' => is_string($key) ? $key : '', 'value' => $item, 'note' => null, 'href' => null];
    })->filter(fn ($i) => $i['label'] !== '' || $i['value'] !== '')->values();

    $inner = $card
        ? 'group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-zinc-300 hover:shadow-lg sm:p-8 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-600'
        : '';
@endphp

@if($factItems->isNotEmpty())
    {{-- The <section> stays FULL WIDTH so any border/background passed in
         spans the viewport; the max-w-7xl lives on an inner wrapper. Putting
         both on one element clipped the border-y to the content column. --}}
    <section {{ $attributes }}>
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div @class([$inner => $card])>
            @if($heading)
                <div class="flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <h2 class="font-heading text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl dark:text-white">{{ $heading }}</h2>
                        @if($subheading)
                            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ $subheading }}</p>
                        @endif
                    </div>
                    @if($linkText && $linkHref)
                        <a href="{{ $linkHref }}" wire:navigate class="text-sm font-medium text-sky-700 hover:underline dark:text-sky-400">{{ $linkText }}</a>
                    @endif
                </div>
            @endif

            <dl @class([
                'grid gap-4 sm:grid-cols-2 lg:grid-cols-4',
                'mt-6' => (bool) $heading,
                'grid-cols-2 gap-6' => ! $boxed,
            ])>
                @foreach($factItems as $fact)
                    <div @class(['rounded-xl bg-zinc-50 p-4 dark:bg-zinc-800/50' => $boxed])>
                        <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ $fact['label'] }}</dt>
                        <dd @class([
                            'mt-1 text-zinc-900 dark:text-white',
                            'font-heading text-2xl font-bold tabular-nums tracking-tight' => $boxed,
                            'text-base font-semibold' => ! $boxed,
                        ])>{{ $fact['value'] }}</dd>
                        @if($fact['note'])
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                @if($fact['href'])
                                    <a href="{{ $fact['href'] }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">{{ $fact['note'] }}</a>
                                @else
                                    {{ $fact['note'] }}
                                @endif
                            </p>
                        @endif
                    </div>
                @endforeach
            </dl>

            @if(trim($slot) !== '')
                <p class="mt-4 text-xs text-zinc-500 dark:text-zinc-400">{{ $slot }}</p>
            @endif
        </div>
        </div>
    </section>
@endif
