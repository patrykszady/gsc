<x-layouts.app
    title="Our Remodeling Process & Timelines | GS Construction"
    metaDescription="The 6-step GS Construction process — free in-home estimate, itemized scope, permits, owner-supervised build with a live client portal, walkthrough & warranty — with real timelines."
>

    <x-breadcrumbs :items="[['label' => 'Our Process']]" maxWidth="max-w-3xl" padding="pt-8 pb-0" />
    <div class="mx-auto max-w-3xl px-4 pt-10 sm:px-6 sm:pt-14 lg:px-8">
        <p class="text-sm font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-400">How We Work</p>
        <h1 class="mt-1 font-heading text-3xl font-bold tracking-tight text-balance text-zinc-900 sm:text-4xl dark:text-white">
            The GS process, step by step
        </h1>
        {{-- Direct answer first — the paragraph AI answers and voice search quote. --}}
        <p class="speakable mt-4 text-base leading-7 text-zinc-600 dark:text-zinc-300">
            Every GS Construction remodel follows the same owner-led process: a free in-home estimate,
            an itemized written scope, permits handled by us, an owner-supervised build you can track in
            a live client portal, and a final walkthrough backed by a written warranty. Most kitchen
            remodels take 8–12 weeks and most bathrooms 3–5 weeks, with a written schedule before work begins.
        </p>

        @php
            // One process, one file: config/sites/gsc/services-content.php.
            // These six stages were ALSO written out here in full, and the two
            // copies were one edit away from disagreeing (the kitchen-week
            // change earlier had to be made twice). The config carries the
            // condensed card copy ('description') for the service pages and
            // the long copy ('time'/'body'/'pageTitle') for this page.
            $steps = collect(config('services-content.process', []))->map(fn ($s) => [
                'n' => $s['step'],
                'title' => $s['pageTitle'] ?? $s['title'],
                'time' => $s['time'] ?? '',
                'body' => $s['body'] ?? $s['description'],
                'link' => $s['link'] ?? null,
            ])->all();
        @endphp

        <ol class="mt-10 space-y-6">
            @foreach($steps as $step)
                <li class="group relative rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900 shadow-sm transition hover:shadow-lg hover:border-zinc-300 dark:hover:border-zinc-600">
                    <div class="flex items-start gap-4">
                        <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-sky-600 font-heading text-lg font-bold text-white">{{ $step['n'] }}</span>
                        <div>
                            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                <h2 class="font-heading text-xl font-bold text-zinc-900 dark:text-white">{{ $step['title'] }}</h2>
                                <span class="text-xs font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400">{{ $step['time'] }}</span>
                            </div>
                            <p class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-400">
                                {{ $step['body'] }}
                                @if(!empty($step['link']))
                                    <a href="{{ $step['link']['href'] }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">{{ $step['link']['text'] }}</a>.
                                @endif
                            </p>
                        </div>
                    </div>
                </li>
            @endforeach
        </ol>

        <h2 class="mt-12 font-heading text-2xl font-bold text-zinc-900 dark:text-white">Typical timelines at a glance</h2>
        <div class="mt-5 overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 text-left text-xs uppercase text-zinc-500 dark:border-zinc-700">
                        <th class="py-2 pr-4">Project</th>
                        <th class="py-2 pr-4">Construction time</th>
                        <th class="py-2">Notes</th>
                    </tr>
                </thead>
                <tbody class="text-zinc-700 dark:text-zinc-300">
                    <tr class="border-b border-zinc-100 dark:border-zinc-800">
                        <td class="py-3 pr-4 font-medium">Kitchen remodel</td>
                        <td class="py-3 pr-4">8–12 weeks</td>
                        <td class="py-3">Custom-cabinet projects can run 12+ weeks due to lead times</td>
                    </tr>
                    <tr class="border-b border-zinc-100 dark:border-zinc-800">
                        <td class="py-3 pr-4 font-medium">Bathroom remodel</td>
                        <td class="py-3 pr-4">3–5 weeks</td>
                        <td class="py-3">Large primary baths with custom tile and glass: 6–8 weeks</td>
                    </tr>
                    <tr>
                        <td class="py-3 pr-4 font-medium">Permits (most suburbs)</td>
                        <td class="py-3 pr-4">+1–2 weeks</td>
                        <td class="py-3">Pulled and managed by GS before construction starts</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="mt-4 text-sm text-zinc-500 dark:text-zinc-400">
            Every project gets a written schedule before work begins — these are the honest ranges we see across
            our completed projects, not best-case marketing numbers.
        </p>
    </div>

    <div class="mx-auto mt-12 max-w-3xl px-4 sm:px-6 lg:px-8">
        <x-faq-section
            heading="Process questions"
            :collapsed="false"
            :faqs="[
                ['question' => 'How long does a kitchen remodel take?', 'answer' => 'Most kitchen remodels take 8–12 weeks from demo to final walkthrough. Custom-cabinet projects can take 14+ weeks due to lead times. GS Construction provides a written schedule before work begins.'],
                ['question' => 'How long does a bathroom remodel take?', 'answer' => 'A typical bathroom remodel takes 3–5 weeks. Larger primary bathrooms with custom tile and glass enclosures can take 6–8 weeks. Permits in some Chicago suburbs add 1–2 weeks.'],
                ['question' => 'Who is my point of contact during the project?', 'answer' => 'The owners — Greg and Patryk Szady — from the first call to the final walkthrough, plus a private client portal that tracks your schedule, change orders, and balances in real time.'],
                ['question' => 'Do you handle permits?', 'answer' => 'Yes. We pull and manage building, plumbing, and electrical permits with the village or city for every project that requires them.'],
            ]"
        />
    </div>

    <x-cta-section
        variant="blue"
        heading="Ready to start step one?"
        description="Book your free in-home estimate — you'll have an itemized scope and a real timeline before you commit to anything."
    />
</x-layouts.app>
