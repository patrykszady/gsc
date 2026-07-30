@props([
    // ['url' => ..., 'label' => ..., 'quote' => ...]
    'source' => [],
    'verified' => null,
])

{{--
    Footnote-style citation mark for a factual claim about a third party. Renders
    a small "*" that links to the page the claim came from and, on hover/focus,
    shows the verbatim quote published there.

    Used on the competitor comparison table so every statement about another
    company carries its source inline — the same claim-by-claim mapping kept in
    docs/legal/. Renders nothing when no source is supplied, so unverified rows
    stay uncited rather than implying a source that was never checked.
--}}

@php
    $url = $source['url'] ?? null;
    $label = $source['label'] ?? null;
    $quote = $source['quote'] ?? null;
    $host = $url ? preg_replace('#^www\.#', '', (string) parse_url($url, PHP_URL_HOST)) : null;
    $stamp = $verified;
    if (! $stamp && config('competitors.last_verified')) {
        $stamp = \Illuminate\Support\Carbon::parse(config('competitors.last_verified'))->format('F Y');
    }
@endphp

@if($url)
    <span class="inline-block"
          x-data="{
            open: false, above: false, x: 0, y: 0, h: 0,
            place(r) {
                // Flip above the mark when the bubble would fall off the bottom.
                this.above = (r.bottom + 8 + (this.h || 130)) > window.innerHeight;
                this.y = this.above ? r.top - 8 : r.bottom + 8;
            },
            show() {
                const r = this.$refs.mark.getBoundingClientRect();
                // keep the bubble inside the viewport on either edge
                this.x = Math.min(Math.max(r.left + r.width / 2, 172), window.innerWidth - 172);
                this.place(r);
                this.open = true;
                // Correct with the real height once rendered, then cache it so
                // subsequent opens are placed exactly on the first frame.
                this.$nextTick(() => {
                    if (! this.$refs.tip) return;
                    this.h = this.$refs.tip.offsetHeight;
                    this.place(r);
                });
            }
          }">
        <a x-ref="mark"
           href="{{ $url }}" target="_blank" rel="noopener nofollow"
           @mouseenter="show()" @mouseleave="open = false"
           @focus="show()" @blur="open = false"
           class="ml-0.5 align-super text-xs font-bold text-sky-600 no-underline hover:text-sky-500 dark:text-sky-400"
           aria-label="Source for this claim: {{ $host }}{{ $label ? ' — ' . $label : '' }}">*</a>

        {{-- Teleported to <body>: the table scroller is overflow-x-auto, and a
             non-visible overflow on one axis clips the other too, so a tooltip
             positioned in place would be cut off at the table edge. --}}
        <template x-teleport="body">
            <span x-ref="tip" x-show="open" x-cloak x-transition.opacity.duration.150ms
                  :style="{ left: x + 'px', top: y + 'px' }"
                  :class="above ? '-translate-y-full' : ''"
                  role="tooltip"
                  class="pointer-events-none fixed z-50 w-80 -translate-x-1/2 rounded-lg bg-zinc-900 px-3 py-2 text-left text-xs font-normal normal-case leading-snug tracking-normal text-white shadow-xl ring-1 ring-white/10">
                @if($quote)
                    <span class="block italic text-zinc-100">&ldquo;{{ $quote }}&rdquo;</span>
                @endif
                <span class="{{ $quote ? 'mt-1.5 ' : '' }}block text-zinc-400">
                    {{ $host }}@if($label) &middot; {{ $label }}@endif@if($stamp) &middot; verified {{ $stamp }}@endif
                </span>
            </span>
        </template>
    </span>
@endif
