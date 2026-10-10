{{--
    The five-star rating image, light/dark pair.

    Was hand-rolled at six call sites with drifted sizes, alt text and (in two
    copies) missing width/height — which costs CLS, since the SVG pair loads
    after layout. One component owns the asset pair and the a11y treatment.

    HONESTY RULE (site-wide, set earlier in the project): this asset asserts
    five stars, so it may only render where the reviews behind it really are
    5/5. Callers showing a possibly-lower rating must branch before calling.

    Props
      size   Tailwind height class for the image.
      label  Accessible name. Empty string = decorative (aria-hidden), for
             call sites where adjacent text already states the rating.
--}}

@props([
    'size' => 'h-6',
    'label' => 'Rated 5 out of 5',
])

<span {{ $attributes->class('inline-flex') }}
    @if($label !== '') role="img" aria-label="{{ $label }}" @else aria-hidden="true" @endif>
    {{-- The accessible name (when there is one) lives on the wrapping span
         above, via role="img"/aria-label — these two images are always
         presentational duplicates (light/dark pair) and never need their
         own. aria-hidden="true" is what a plain "does this image have alt
         text" scan (ours and most third-party ones) treats as "correctly
         decorative, don't count it" — alt="" alone, with the accessible
         name one level up on the span, read as an image missing alt text. --}}
    <img src="{{ asset('images/5-stars.svg') }}" alt="" aria-hidden="true" width="140" height="20" class="{{ $size }} w-auto dark:hidden" />
    <img src="{{ asset('images/5-stars-dark.svg') }}" alt="" aria-hidden="true" width="140" height="20" class="hidden {{ $size }} w-auto dark:block" />
</span>
