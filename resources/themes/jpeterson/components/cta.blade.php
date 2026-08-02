{{--
    J. Peterson Design — closing call to action.

    The full-bleed dark teal band that ends a page. Theme-scoped, like
    <x-button> and <x-layouts.app> beside it.

    Ground is --color-brand-900 (#274a4d), the one place this site goes dark.
    White heading on it measures 9.66:1 and the stone-300 body copy 7.4:1, so
    the band stays AA even though it inverts the page. The button uses the
    `inverse` variant — a canvas-coloured pill, since a teal button on a teal
    ground would disappear.

    Usage — bare tag uses the defaults; the slot is the optional support line:
        <x-cta />
        <x-cta heading="Ready to start?" label="Get in touch">
            An optional supporting line.
        </x-cta>
--}}

@props([
    'heading' => 'Let\'s talk about your space',
    'href' => '/contact',
    'label' => 'Start a project',
])

<section {{ $attributes->class('border-t border-brand-800 bg-brand-900') }}>
    <div class="mx-auto max-w-4xl px-6 py-20 text-center">
        <h2 class="text-balance font-heading text-3xl text-canvas sm:text-4xl">
            {{ $heading }}
        </h2>

        @if (trim($slot) !== '')
            <p class="mx-auto mt-5 max-w-lg leading-relaxed text-stone-300">{{ $slot }}</p>
        @endif

        <x-button :href="$href" variant="inverse" size="lg" class="mt-9">{{ $label }}</x-button>
    </div>
</section>
