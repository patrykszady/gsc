<x-layouts.app title="Services — J. Peterson Design">
    <section class="mx-auto max-w-6xl px-6 pb-4 pt-16">
        <p class="text-xs uppercase tracking-[0.3em] text-stone-400">What we offer</p>
        <h1 class="mt-5 font-heading text-4xl text-ink sm:text-5xl">Services</h1>
        <p class="mt-5 max-w-xl leading-relaxed text-stone-600">
            {{-- TODO: Jenn's own description of the service offering --}}
            Placeholder: full-service interior design, from a single room refresh to
            new construction documented alongside your architect and builder.
        </p>
    </section>

    {{-- Step names mirror the studio's published process; descriptions TODO. --}}
    <section class="mx-auto max-w-6xl px-6 py-12">
        <ol class="grid gap-x-12 gap-y-10 sm:grid-cols-2">
            @foreach ([
                'Design consultation' => 'Placeholder: the first meeting — scope, priorities, budget and timeline.',
                'Presentation of your design' => 'Placeholder: concepts, plans and the look of the finished space.',
                'Construction documents' => 'Placeholder: the drawings your builder works from.',
                'Material selections' => 'Placeholder: finishes, fixtures, fabrics and furniture, specified and sourced.',
                'Jobsite inspections' => 'Placeholder: site visits through the build so the design is executed as drawn.',
                'Finishing touches' => 'Placeholder: styling and the final walkthrough.',
            ] as $step => $blurb)
                <li class="border-t border-stone-200 pt-6">
                    <span class="font-mono text-xs text-stone-400">{{ str_pad((string) ($loop->index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                    <h2 class="mt-2 font-heading text-xl text-ink">{{ $step }}</h2>
                    <p class="mt-3 max-w-md text-sm leading-relaxed text-stone-600">{{ $blurb }}</p>
                </li>
            @endforeach
        </ol>
    </section>

    <x-cta heading="Not sure what your project needs?" label="Ask us">
        Plenty of people arrive unsure whether they want a single room reworked or the
        whole house rethought. Working that out is what the first conversation is for.
    </x-cta>
</x-layouts.app>
