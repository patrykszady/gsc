<x-layouts.app
    title="Our Warranty — Workmanship Guarantee | GS Construction"
    metaDescription="GS Construction's written warranty: 1-year workmanship coverage on all labor, manufacturer warranties passed through, and one company accountable for every trade."
>
    <div class="mx-auto max-w-3xl px-4 pt-10 sm:px-6 sm:pt-14 lg:px-8">
        <p class="text-sm font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-400">Warranty</p>
        <h1 class="mt-1 font-heading text-3xl font-bold tracking-tight text-balance text-zinc-900 sm:text-4xl dark:text-white">
            One warranty. One company accountable.
        </h1>
        {{-- Direct answer first — the paragraph AI answers and voice search quote. --}}
        <p class="speakable mt-4 text-base leading-7 text-zinc-600 dark:text-zinc-300">
            GS Construction provides a 1-year workmanship warranty on all labor, on top of the
            manufacturer warranties on your cabinets, countertops, fixtures, and appliances.
            The terms are spelled out in writing in every contract — and because every trade on
            your project works under GS, one call covers all of it.
        </p>

        <h2 class="mt-10 font-heading text-2xl font-bold text-zinc-900 dark:text-white">What's covered</h2>
        <div class="mt-5 space-y-5">
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">Workmanship — 1 year, all labor</h3>
                <p class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-400">
                    If something we built doesn't hold up the way it should in the first year — a door out of
                    alignment, trim that opens a seam, tile that hollows — we come back and make it right.
                    That covers the work of every trade on your project, not just the ones we staffed directly.
                </p>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">Manufacturer warranties — passed through to you</h3>
                <p class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-400">
                    Cabinets, countertops, windows, fixtures, and appliances carry their manufacturers'
                    warranties — often far longer than one year. We install to manufacturer spec so those
                    warranties stay valid, and we register or hand over the paperwork at closeout.
                </p>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">In writing, in every contract</h3>
                <p class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-400">
                    Warranty terms aren't a webpage promise — they're a section of your signed contract.
                    You'll know exactly what's covered and for how long before demo day.
                </p>
            </div>
        </div>

        <h2 class="mt-12 font-heading text-2xl font-bold text-zinc-900 dark:text-white">Making a warranty claim</h2>
        <p class="mt-4 text-zinc-700 dark:text-zinc-300">
            Call or text the owners — the same people who ran your project — at
            <a href="tel:+12247354200" class="font-medium text-sky-700 hover:underline dark:text-sky-400">(224) 735-4200</a>,
            or email <a href="mailto:crew@gs.construction" class="font-medium text-sky-700 hover:underline dark:text-sky-400">crew@gs.construction</a>.
            No claims department, no ticket queue: you talk to Greg or Patryk, we look at it, and we schedule the fix.
        </p>

        <div class="mt-10 rounded-2xl border border-sky-200 bg-sky-50 p-6 dark:border-sky-500/20 dark:bg-sky-500/5">
            <h2 class="font-heading text-xl font-bold text-zinc-900 dark:text-white">Why one warranty matters</h2>
            <p class="mt-2 text-sm leading-6 text-zinc-700 dark:text-zinc-300">
                When you hire trades separately, a failure becomes a blame chain — the plumber points at the
                tile setter, the tile setter points at the framer. On a GS project there is no chain:
                every trade worked under our supervision, so whatever needs attention, it's our call to fix.
                Read more about <a href="{{ route('trades.index') }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">how we work with our trade partners</a>.
            </p>
        </div>
    </div>

    <div class="mx-auto mt-12 max-w-3xl px-4 sm:px-6 lg:px-8">
        <x-faq-section
            heading="Warranty questions"
            :collapsed="false"
            :faqs="[
                ['question' => 'What warranty does GS Construction provide?', 'answer' => 'A 1-year workmanship warranty on all labor, on top of any manufacturer warranties on cabinets, countertops, and appliances. Terms are spelled out in every contract.'],
                ['question' => 'Does the warranty cover work done by your trade partners?', 'answer' => 'Yes. Every trade on a GS project works under our supervision and is covered by the same GS workmanship warranty — you call us, not the individual trade, if anything needs attention.'],
                ['question' => 'How do I make a warranty claim?', 'answer' => 'Call or text (224) 735-4200 or email crew@gs.construction. You deal directly with the owners who ran your project — no claims department.'],
            ]"
        />
    </div>

    <x-cta-section
        variant="blue"
        heading="Ready to scope your remodel?"
        description="Free in-home estimate, itemized scope, and warranty terms in writing before work begins."
    />
</x-layouts.app>
