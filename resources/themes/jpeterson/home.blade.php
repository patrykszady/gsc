<x-layouts.app>
    {{--
        SKELETON. Structure mirrors the studio's existing information architecture
        (Portfolio / Services / About / Testimonials / Contact + a six-step
        process). All prose here is PLACEHOLDER and every image is a blocked-out
        panel — final copy and photography must come from Jenn, not be lifted
        from the current site. Search this file for TODO.
    --}}

    {{-- ---------------- hero ---------------- --}}
    {{-- Hero spacing is deliberately tight: eyebrow → headline → tagline →
         buttons read as ONE block, then a single larger gap before the image.
         The whitespace that gives this theme its air belongs between sections,
         not inside the stack. --}}
    <section class="mx-auto max-w-6xl px-6 pb-14 pt-5 sm:pt-7">
        <p class="text-xs uppercase tracking-[0.3em] text-stone-400">Interior Design Studio</p>
        <h1 class="mt-3 max-w-4xl text-balance font-heading text-4xl font-normal leading-[1.05] text-ink sm:text-6xl">
            {{-- TODO: confirm headline with Jenn --}}
            Striving to enrich your life by creating extraordinary spaces
        </h1>
        {{-- The studio's own tagline. Set in sentence case with an `uppercase`
             utility rather than typed in caps: screen readers spell out literal
             all-caps strings letter by letter, and this way the text stays
             readable if the class is ever dropped. --}}
        <p class="mt-4 max-w-xl text-lg leading-snug tracking-[0.14em] text-stone-600 uppercase">
            Exceptional Design for Inspired Living
        </p>
        <div class="mt-6 flex flex-wrap items-center gap-4">
            <x-button href="/portfolio">View the portfolio</x-button>
            <x-button href="/contact" variant="outline">Start a project</x-button>
        </div>

        {{-- TODO: hero photography supplied by Jenn — replace the placeholder
             slides below with real images. Keeps the blocked-out panel's exact
             frame (aspect, rounding, border) so the layout does not move when
             the photographs land. --}}
        <x-hero-carousel
            :slides="\App\Support\HeroSlides::placeholders(['Hero photograph', 'Interior detail', 'Finished space'])"
            container-classes="mt-10"
            rounded-classes="rounded-sm border border-stone-200"
            height-classes="aspect-16/7"
            eager
        />
    </section>

    {{-- ---------------- markets ---------------- --}}
    <section class="border-y border-stone-200/70 bg-white/50">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-center gap-x-14 gap-y-3 px-6 py-7 text-xs uppercase tracking-[0.25em] text-stone-500">
            @foreach (config('markets.list', []) as $market)
                <a href="/{{ $market['slug'] }}" wire:navigate class="transition hover:text-brand-700">{{ $market['label'] }}</a>
            @endforeach
        </div>
    </section>

    {{-- ---------------- about teaser ----------------
         Cliffnotes of /about: the sisters, their two markets, and the reason
         the pairing matters. Kept to two short paragraphs on purpose — the
         full version, with credentials and individual bios, lives on /about
         and this section exists to send people there. --}}
    <section class="mx-auto grid max-w-6xl gap-12 px-6 py-20 md:grid-cols-2 md:gap-16">
        {{-- TODO: ONE photograph of Jennifer and Jill together, supplied by
             Jenn. A combined portrait, not two headshots — the studio being a
             pair is the point of this section. --}}
        <div class="aspect-4/5 overflow-hidden rounded-sm border border-stone-200 bg-stone-100">
            <div class="flex h-full flex-col items-center justify-center gap-1 text-xs tracking-[0.2em] text-stone-400 uppercase">
                <span>Jennifer &amp; Jill</span>
                <span class="text-[10px] tracking-[0.15em] text-stone-300">Combined portrait</span>
            </div>
        </div>
        <div class="flex flex-col justify-center">
            <p class="text-xs uppercase tracking-[0.3em] text-stone-400">About the studio</p>
            <h2 class="mt-5 font-heading text-3xl leading-tight text-ink">
                Two sisters, one studio
            </h2>
            <div class="mt-6 space-y-4 leading-relaxed text-stone-600">
                <p>
                    Jennifer Peterson founded J. Peterson Design in 2008 and leads its
                    Chicago and Southwest Michigan work. Her sister, Jill Kearns, leads the
                    Atlanta office. Both are formally trained interior designers, and both
                    stay on your project from the first conversation to the last piece placed.
                </p>
                <p>
                    Working as sisters is why there is one shared standard rather than a
                    house style handed down to junior staff &mdash; and why every decision
                    gets a second qualified opinion before it ever reaches you.
                </p>
            </div>
            <x-button href="/about" variant="outline" class="mt-8 self-start">
                More about the studio
            </x-button>
        </div>
    </section>

    {{-- ---------------- portfolio grid ---------------- --}}
    <section class="mx-auto max-w-6xl px-6 py-16">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-xs uppercase tracking-[0.3em] text-stone-400">Selected work</p>
                <h2 class="mt-4 font-heading text-3xl text-ink">Portfolio</h2>
            </div>
            <x-button href="/portfolio" variant="outline" size="sm">View all projects</x-button>
        </div>

        {{-- TODO: replace with real projects once Jenn supplies images + titles.
             Deliberately generic room types, not her project names. --}}
        <div class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach (['Kitchen', 'Primary Bath', 'Living Room', 'Lake House', 'Dining Room', 'Whole Home'] as $room)
                <figure class="group">
                    {{-- uniform ratio: mixed ratios in a CSS grid leave the
                         captions on different baselines and read as broken --}}
                    <div class="aspect-4/3 overflow-hidden rounded-sm border border-stone-200 bg-stone-100">
                        <div class="flex h-full items-center justify-center text-xs uppercase tracking-[0.2em] text-stone-400">
                            Project image
                        </div>
                    </div>
                    <figcaption class="mt-3 flex items-baseline justify-between">
                        <span class="text-sm text-stone-800">{{ $room }}</span>
                        <span class="text-xs uppercase tracking-wider text-stone-400">Project</span>
                    </figcaption>
                </figure>
            @endforeach
        </div>
    </section>

    {{-- ---------------- design process ---------------- --}}
    <section class="border-y border-stone-200/70 bg-white/50">
        <div class="mx-auto max-w-6xl px-6 py-20">
            <p class="text-xs uppercase tracking-[0.3em] text-stone-400">How it works</p>
            <h2 class="mt-4 font-heading text-3xl text-ink">The design process</h2>
            <p class="mt-4 max-w-2xl leading-relaxed text-stone-600">
                {{-- TODO: confirm wording --}}
                Six stages, from the first conversation to the day the last piece is placed.
            </p>

            {{-- The same six stages as /process, from
                 config/sites/jpeterson/process.php. This grid uses the short
                 `summary` of each; /process renders the full `body`. One
                 source, two lengths — so the teaser and the page cannot drift. --}}
            <ol class="mt-12 grid gap-x-12 gap-y-10 sm:grid-cols-2 lg:grid-cols-3">
                @foreach (config('process.steps', []) as $step)
                    <li class="border-t border-stone-200 pt-5">
                        {{-- brand-teal numerals, matching /process --}}
                        <span class="font-mono text-xs text-brand-600">{{ str_pad((string) ($loop->index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                        <h3 class="mt-2 font-heading text-lg text-ink">{{ $step['name'] }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-stone-600">{{ $step['summary'] }}</p>
                    </li>
                @endforeach
            </ol>

            <x-button href="/process" variant="outline" class="mt-12">See the full process</x-button>
        </div>
    </section>

    {{-- ---------------- testimonial ---------------- --}}
    <section class="mx-auto max-w-3xl px-6 py-24 text-center">
        <p class="text-xs uppercase tracking-[0.3em] text-stone-400">Testimonials</p>
        <blockquote class="mt-8 text-balance font-heading text-2xl leading-relaxed text-ink sm:text-3xl">
            {{-- TODO: real client testimonial, with permission --}}
            &ldquo;Placeholder for a client testimonial — one or two sentences that capture
            what working with the studio was actually like.&rdquo;
        </blockquote>
        <p class="mt-6 text-sm uppercase tracking-wider text-stone-500">Client name &middot; Project</p>
        <x-button href="/testimonials" variant="outline" class="mt-8">Read more</x-button>
    </section>

    {{-- ---------------- contact CTA ---------------- --}}
    <x-cta>
        {{-- TODO: Jenn's own line inviting enquiries, and what to expect after. --}}
        Placeholder: a line inviting enquiries, and what to expect after getting in touch.
    </x-cta>
</x-layouts.app>
