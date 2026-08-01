{{--
    About — J. Peterson Design.

    Factual basis: the studio's own published About page — degrees,
    certifications, office locations, founding year, cabinetry partner — reused
    with Jenn Peterson's authorisation (recorded in docs/sites/jpeterson.md).
    The wording here is written for this page rather than transplanted, so the
    repo holds no verbatim copy of another site. Jenn signs off on the final
    prose before launch.

    Still needed from Jenn: portrait photographs for both designers, and a
    studio photograph.
--}}
@php
    // Team lives here rather than in config/sites/jpeterson/: this theme
    // directory already scopes it to one tenant, and it is page content, not
    // configuration that other pages or SEO output need to read.
    $team = [
        [
            'name' => 'Jennifer Peterson',
            'role' => 'Founder, Principal Designer',
            'credentials' => [
                'B.A. Interior Design, Michigan State University',
                'Certified Kitchen Designer, NKBA',
                'Passed the NCIDQ qualifying examination',
            ],
            'bio' => 'The sister who started it. Jennifer founded the studio in 2008 and leads its Chicago and Southwest Michigan work. A background in kitchen and bath design shapes how every project begins: with how a room is actually used, before anything is chosen for it.',
        ],
        [
            'name' => 'Jill Kearns',
            'role' => 'Designer — Atlanta',
            'credentials' => [
                'B.A. Interior Design, Kendall College of Art and Design',
                'NCIDQ certified',
                '15 years in the industry',
            ],
            'bio' => 'The sister who took it south. Jill leads the studio\'s Atlanta office, bringing fifteen years of residential practice and a calm, methodical approach — plus a habit of catching the detail that would otherwise have gone wrong on site.',
        ],
    ];
@endphp

<x-layouts.app title="About — J. Peterson Design">

    {{-- ---------- studio ---------- --}}
    <section class="mx-auto grid max-w-6xl gap-12 px-6 py-16 md:grid-cols-5 md:gap-16">
        <div class="md:col-span-2">
            <div class="aspect-4/5 overflow-hidden rounded-sm border border-stone-200 bg-stone-100">
                <div class="flex h-full items-center justify-center text-xs tracking-[0.2em] text-stone-400 uppercase">
                    {{-- TODO: studio photograph — must come from Jenn --}}
                    Studio
                </div>
            </div>
        </div>

        <div class="md:col-span-3">
            <p class="text-xs tracking-[0.3em] text-stone-400 uppercase">About</p>
            <h1 class="mt-5 font-heading text-4xl text-ink sm:text-5xl">Two sisters, one studio</h1>

            <div class="mt-7 space-y-5 leading-relaxed text-stone-600">
                <p>
                    J. Peterson Design is run by sisters. Jennifer Peterson founded the studio in
                    2008 and leads its Chicago and Southwest Michigan work; Jill Kearns leads the
                    Atlanta office. Both are formally trained interior designers, and both stay on
                    your project from the first conversation to the day the last piece is placed.
                </p>
                <p>
                    Working as sisters is the reason the studio runs the way it does. There is one
                    shared standard rather than a house style handed down to junior staff, and
                    every decision gets a second qualified opinion before it reaches you &mdash;
                    candidly, and long before anything is ordered or built.
                </p>
                <p>
                    The approach itself is holistic and deliberately unhurried: how a space
                    functions day to day comes first, and the finishes follow from it. The aim is
                    rooms that still read well in a decade rather than ones that date with the
                    season. Cabinetry is produced in partnership with Das Holz Haus.
                </p>
            </div>

            <div class="mt-10 border-t border-stone-200 pt-6">
                <p class="text-xs tracking-[0.18em] text-stone-400 uppercase">Studio markets</p>
                <p class="mt-2 text-sm text-stone-700">{{ collect(config('markets.list', []))->pluck('label')->implode(' · ') }}</p>
            </div>
        </div>
    </section>

    {{-- ---------- what the pairing means for a client ----------
         The differentiator, stated plainly. Every claim here is backed by a
         fact on the team list below (two qualified designers, two offices,
         one studio since 2008) — nothing asserted that Jenn cannot stand
         behind. --}}
    <section class="border-y border-stone-200/70 bg-stone-50/60">
        <div class="mx-auto max-w-6xl px-6 py-14">
            <p class="text-xs tracking-[0.3em] text-stone-400 uppercase">Why it matters</p>
            <div class="mt-8 grid gap-x-14 gap-y-9 sm:grid-cols-3">
                @foreach ([
                    'Two designers, not one' => 'Both sisters are credentialed interior designers. You get two trained eyes on the plan, the selections and the site — not a principal who presents and a junior who executes.',
                    'One aesthetic, three markets' => 'Chicago, Southwest Michigan and Atlanta are served by the same studio and the same standard, because the people running them grew up with the same one.',
                    'Straight answers' => 'Sisters tell each other the truth about a decision before a client ever sees it. Ideas get pressure-tested in-house, which is why very little gets walked back later.',
                ] as $heading => $body)
                    <div>
                        <h2 class="font-heading text-xl text-ink">{{ $heading }}</h2>
                        <p class="mt-3 text-sm leading-relaxed text-stone-600">{{ $body }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- ---------- team ---------- --}}
    <section class="mx-auto max-w-6xl px-6 py-12">
        <p class="text-xs tracking-[0.3em] text-stone-400 uppercase">Our team</p>
        <h2 class="mt-5 font-heading text-3xl text-ink">Jennifer &amp; Jill</h2>
        <p class="mt-4 max-w-xl leading-relaxed text-stone-600">
            Different training, different cities, the same standard &mdash; and enough shared
            history to disagree productively.
        </p>

        <div class="mt-12 grid gap-x-16 gap-y-14 sm:grid-cols-2">
            @foreach ($team as $person)
                <article>
                    <div class="aspect-square overflow-hidden rounded-sm border border-stone-200 bg-stone-100">
                        <div class="flex h-full items-center justify-center text-xs tracking-[0.2em] text-stone-400 uppercase">
                            {{-- TODO: portrait — must come from Jenn --}}
                            Portrait
                        </div>
                    </div>

                    <h3 class="mt-6 font-heading text-2xl text-ink">{{ $person['name'] }}</h3>
                    <p class="mt-1.5 text-xs tracking-[0.18em] text-stone-500 uppercase">{{ $person['role'] }}</p>

                    <p class="mt-5 text-sm leading-relaxed text-stone-600">{{ $person['bio'] }}</p>

                    <ul class="mt-6 space-y-1.5 border-t border-stone-200 pt-5 text-sm text-stone-600">
                        @foreach ($person['credentials'] as $credential)
                            <li class="flex gap-2.5">
                                <span aria-hidden="true" class="text-stone-300">&mdash;</span>
                                <span>{{ $credential }}</span>
                            </li>
                        @endforeach
                    </ul>
                </article>
            @endforeach
        </div>
    </section>

    {{-- ---------- cta ---------- --}}
    <x-cta heading="Work with both of us" label="Introduce yourself">
        Every project gets both sisters &mdash; two trained eyes from the first
        conversation through to the last piece placed.
    </x-cta>
</x-layouts.app>
