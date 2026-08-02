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
    <img src="{{ asset('images/5-stars.svg') }}" alt="" width="140" height="20" class="{{ $size }} w-auto dark:hidden" />
    <img src="{{ asset('images/5-stars-dark.svg') }}" alt="" width="140" height="20" class="hidden {{ $size }} w-auto dark:block" />
</span>
