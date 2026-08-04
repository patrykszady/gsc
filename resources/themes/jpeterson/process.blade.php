{{--
    Process — J. Peterson Design.

    Factual basis: the six stages the studio publishes on its own site, reused
    with Jenn Peterson's authorisation (see docs/sites/jpeterson.md). The stage
    NAMES are hers; the description under each is written for this page from
    what that stage involves, so the repo holds no verbatim copy. Jenn approves
    the final wording before launch.

    Routing note: routes/web.php has a single /process route rendering
    view('process'). gs.construction has its own resources/views/process.blade.php;
    this file overrides it for this tenant only, via the view-finder overlay.
    Both sites claim 'process' in config/sites.php exclusive_paths.
--}}
@php
    // Shared with the home-page teaser — see config/sites/jpeterson/process.php.
    $steps = config('process.steps', []);
@endphp

<x-layouts.app title="Design Process — J. Peterson Design">

    <section class="mx-auto max-w-6xl px-6 pt-10 pb-4">
        <p class="text-xs tracking-[0.3em] text-stone-400 uppercase">How it works</p>
        <h1 class="mt-3 max-w-3xl font-heading text-4xl text-ink sm:text-5xl">The design process</h1>
        <p class="mt-5 max-w-xl leading-relaxed text-stone-600">
            Six stages, from the first conversation to the day the last piece is placed.
            You work with the same two designers throughout &mdash; nothing is handed off.
        </p>
    </section>

    {{-- TODO: photography supplied by Jenn — placeholder slides until then.
         No overlay text: this page already owns its single H1. --}}
    <x-hero-carousel
        :slides="\App\Support\HeroSlides::placeholders(['Consultation', 'Documentation', 'Completed space'])"
        container-classes="mx-auto max-w-6xl px-6 pb-2"
        rounded-classes="rounded-sm border border-stone-200"
        height-classes="aspect-16/7"
    />


    {{-- Numbered list rather than a card grid: the whole point of this page is
         that the stages happen in an order, and cards flatten that into a menu. --}}
    <section class="mx-auto max-w-6xl px-6 py-10">
        <ol class="grid gap-x-16 gap-y-12 sm:grid-cols-2">
            @foreach ($steps as $step)
                <li class="border-t border-stone-200 pt-6">
                    <span class="font-mono text-xs text-brand-600">
                        {{ str_pad((string) ($loop->index + 1), 2, '0', STR_PAD_LEFT) }}
                    </span>
                    <h2 class="mt-2 font-heading text-2xl text-ink">{{ $step['name'] }}</h2>
                    <p class="mt-3 max-w-md text-sm leading-relaxed text-stone-600">{{ $step['body'] }}</p>
                </li>
            @endforeach
        </ol>
    </section>

    <x-cta heading="Start at stage one" label="Schedule a consultation">
        It begins with a conversation and a walk through the space &mdash; and we will
        tell you plainly what it needs.
    </x-cta>
</x-layouts.app>
