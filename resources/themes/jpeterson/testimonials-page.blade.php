<x-layouts.app title="Testimonials — J. Peterson Design">
    <section class="mx-auto max-w-6xl px-6 pb-4 pt-16">
        <p class="text-xs uppercase tracking-[0.3em] text-stone-400">Kind words</p>
        <h1 class="mt-5 font-heading text-4xl text-ink sm:text-5xl">Testimonials</h1>
    </section>

    {{-- TODO: photography supplied by Jenn — placeholder slides until then.
         No overlay text: this page already owns its single H1. --}}
    <x-hero-carousel
        :slides="\App\Support\HeroSlides::placeholders(['Client home', 'Interior detail', 'Finished space'])"
        container-classes="mx-auto max-w-6xl px-6 pb-2"
        rounded-classes="rounded-sm border border-stone-200"
        height-classes="aspect-16/7"
    />


    {{-- TODO: real client quotes, with permission, supplied by Jenn. --}}
    <section class="mx-auto max-w-4xl px-6 py-12">
        <div class="space-y-14">
            @foreach (range(1, 4) as $i)
                <blockquote class="border-l-2 border-stone-300 pl-8">
                    <p class="font-heading text-xl leading-relaxed text-stone-800">
                        &ldquo;Placeholder testimonial {{ $i }} — two or three sentences from a
                        client about what the studio was like to work with and how the
                        finished space feels.&rdquo;
                    </p>
                    <footer class="mt-4 text-sm uppercase tracking-wider text-stone-500">Client name &middot; Project</footer>
                </blockquote>
            @endforeach
        </div>
    </section>

    <x-cta heading="Every one of these started with a conversation" label="Start yours">
        No plans required, and no obligation to go further than the first meeting.
    </x-cta>
</x-layouts.app>
