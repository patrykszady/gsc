<x-layouts.app title="Portfolio — J. Peterson Design">
    {{-- SKELETON: images + project names must come from Jenn. --}}
    <section class="mx-auto max-w-6xl px-6 pb-4 pt-16">
        <p class="text-xs uppercase tracking-[0.3em] text-stone-400">Selected work</p>
        <h1 class="mt-5 font-heading text-4xl text-ink sm:text-5xl">Portfolio</h1>
        <p class="mt-5 max-w-xl leading-relaxed text-stone-600">
            {{-- TODO: intro line from Jenn --}}
            Placeholder: one line about the range of work — full homes, kitchens,
            baths, and the spaces in between.
        </p>
    </section>

    {{-- TODO: photography supplied by Jenn — placeholder slides until then.
         No overlay text: this page already owns its single H1. --}}
    <x-hero-carousel
        :slides="\App\Support\HeroSlides::placeholders(['Featured project', 'Kitchen', 'Bath'])"
        container-classes="mx-auto max-w-6xl px-6 pb-2"
        rounded-classes="rounded-sm border border-stone-200"
        height-classes="aspect-16/7"
    />


    <section class="mx-auto max-w-6xl px-6 py-12">
        <div class="grid gap-x-6 gap-y-10 sm:grid-cols-2 lg:grid-cols-3">
            @foreach (['Kitchen', 'Primary Bath', 'Living Room', 'Lake House', 'Dining Room', 'Whole Home', 'Home Office', 'Guest Suite', 'Outdoor Room'] as $room)
                <figure>
                    <div class="aspect-4/3 overflow-hidden rounded-sm border border-stone-200 bg-stone-100">
                        <div class="flex h-full items-center justify-center text-xs uppercase tracking-[0.2em] text-stone-400">Project image</div>
                    </div>
                    <figcaption class="mt-3 flex items-baseline justify-between">
                        <span class="text-sm text-stone-800">{{ $room }}</span>
                        <span class="text-xs uppercase tracking-wider text-stone-400">Project</span>
                    </figcaption>
                </figure>
            @endforeach
        </div>
    </section>

    {{-- Curly apostrophe as a literal character, not &rsquo;: the component
         prints the heading through {{ }}, which escapes entities. --}}
    <x-cta heading="Every room here began as somebody’s list of frustrations" label="Start your project">
        Tell us what is not working in yours, and what you wish it did instead.
    </x-cta>
</x-layouts.app>
