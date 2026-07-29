<x-layouts.app
    title="Warranty — We Show Up Long After It Ends | GS Construction"
    metaDescription="1-year written workmanship warranty in every contract — and years later we still come out, evaluate the issue, and give you honest answers until it's resolved."
>
    <x-breadcrumbs :items="[['label' => 'Warranty']]" />

    <x-page-hero key-suffix="warranty">
        <p class="text-sm font-semibold uppercase tracking-wide text-sky-300">Warranty</p>
        <h1 class="mt-2 font-heading text-3xl font-bold text-balance text-white text-shadow-lg sm:text-4xl lg:text-5xl">
            Our promise does not expire
        </h1>
        <p class="mt-3 max-w-2xl text-lg text-white/90 text-shadow-sm">
            Every contract includes a written 1-year workmanship warranty — and we still
            show up years after it ends.
        </p>
    </x-page-hero>

    <div class="mx-auto max-w-7xl px-4 pt-10 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-3xl">
        {{-- The promise, in plain words --}}
        <p class="speakable text-lg leading-8 text-zinc-700 dark:text-zinc-300">
            Whether your project finished last month or three years ago: call or text Greg and Patryk —
            the same people who ran your job — at
            <a href="tel:+12247354200" class="font-semibold text-sky-700 hover:underline dark:text-sky-400">(224) 735-4200</a>,
            or email
            <a href="mailto:crew@gs.construction" class="font-semibold text-sky-700 hover:underline dark:text-sky-400">crew@gs.construction</a>.
            No claims department, no "let me check if you're still covered." You talk to Greg or Patryk,
            we come out, evaluate the issue, and give you honest feedback on the problem.
        </p>
        <p class="mt-5 text-lg leading-8 text-zinc-700 dark:text-zinc-300">
            If it was a GS Construction mishap that just showed up years later — we'll tell you, and we'll
            fix it. If it's a device or manufacturer issue, we'll point you in the right direction and be
            there every step of the way until it's resolved.
        </p>

        {{-- Coverage at a glance --}}
        <div class="mt-10 grid gap-4 sm:grid-cols-3">
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                <p class="font-heading text-lg font-bold text-zinc-900 dark:text-white">Year 1</p>
                <p class="mt-1.5 text-sm leading-6 text-zinc-600 dark:text-zinc-400">
                    Written workmanship warranty on all labor, in every contract — covering every trade on your project.
                </p>
            </div>
            <div class="rounded-2xl border-2 border-sky-300 bg-sky-50/60 p-5 dark:border-sky-500/40 dark:bg-sky-500/5">
                <p class="font-heading text-lg font-bold text-zinc-900 dark:text-white">Every year after</p>
                <p class="mt-1.5 text-sm leading-6 text-zinc-700 dark:text-zinc-300">
                    We still answer, come out, and make it right. We've never told a client "your warranty expired."
                </p>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                <p class="font-heading text-lg font-bold text-zinc-900 dark:text-white">Manufacturers</p>
                <p class="mt-1.5 text-sm leading-6 text-zinc-600 dark:text-zinc-400">
                    Cabinets, counters, and appliances keep their own warranties — often 5–25 years. We install to spec so they stay valid.
                </p>
            </div>
        </div>
        </div>
    </div>

    <x-faq-section
        heading="Warranty questions"
        content-max-width="max-w-3xl"
        :collapsed="true"
            :faqs="[
                ['question' => 'What warranty does GS Construction provide?', 'answer' => 'A written 1-year workmanship warranty on all labor in every contract, on top of manufacturer warranties on cabinets, countertops, and appliances — and we still come out and address issues years after the written warranty ends.'],
                ['question' => 'What happens after the 1-year warranty ends?', 'answer' => 'We still show up. Call us in year 3 about something we built and we come out, evaluate it, and give you honest feedback. If it was our mishap, we tell you and we fix it. If it is a manufacturer issue, we guide you until it is resolved.'],
                ['question' => 'Does the warranty cover work done by your trade partners?', 'answer' => 'Yes. Every trade on a GS project works under our supervision and is covered by the same GS workmanship warranty — one call to us covers all of it.'],
                ['question' => 'How do I get something looked at?', 'answer' => 'Call or text (224) 735-4200 or email crew@gs.construction — whether your project finished last month or three years ago. You deal directly with Greg and Patryk, no claims department.'],
            ]"
    />

    <x-cta-section
        variant="blue"
        heading="Ready to scope your remodel?"
        description="Free in-home estimate, itemized scope, warranty in writing — and a contractor who still answers the phone years later."
    />
</x-layouts.app>
