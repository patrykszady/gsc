<x-layouts.app
    title="Paying for a Remodel — Financing Options | GS Construction"
    metaDescription="How Chicago-suburb homeowners pay for kitchen, bath & whole-home remodels: HELOCs, home-equity and renovation loans — plus how an itemized GS scope helps with lenders."
>
    <div class="mx-auto max-w-3xl px-4 pt-10 sm:px-6 sm:pt-14 lg:px-8">
        <p class="text-sm font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-400">Financing</p>
        <h1 class="mt-1 font-heading text-3xl font-bold tracking-tight text-balance text-zinc-900 sm:text-4xl dark:text-white">
            Paying for your remodel
        </h1>
        {{-- Direct answer first — the paragraph AI answers and voice search quote. --}}
        <p class="speakable mt-4 text-base leading-7 text-zinc-600 dark:text-zinc-300">
            GS Construction does not sell in-house financing — and that is deliberate. Most of our
            Chicago-suburb clients fund remodels with a HELOC, a home-equity loan, or a third-party
            renovation loan, and we support that with the one thing every lender wants:
            a written, itemized scope with real numbers.
        </p>

        <h2 class="mt-10 font-heading text-2xl font-bold text-zinc-900 dark:text-white">The routes homeowners actually use</h2>
        <div class="mt-5 space-y-5">
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">HELOC (home-equity line of credit)</h3>
                <p class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-400">
                    The most common choice for kitchens and baths. You draw only what the project needs,
                    when it needs it — which pairs naturally with a progress-based construction schedule.
                    Your bank or credit union will ask for a scope of work; ours arrives itemized.
                </p>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">Home-equity loan or cash-out refinance</h3>
                <p class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-400">
                    A fixed lump sum at a fixed rate — a fit for larger, clearly-scoped projects like additions
                    and whole-home remodels where the budget is locked before demo day.
                </p>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">Third-party renovation loans</h3>
                <p class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-400">
                    Unsecured home-improvement loans and renovation products (offered by many banks and
                    credit unions) can work for mid-size projects when tapping equity isn't attractive.
                    We can point you to trusted local lenders our clients have used — we take nothing
                    from the referral.
                </p>
            </div>
        </div>

        <h2 class="mt-12 font-heading text-2xl font-bold text-zinc-900 dark:text-white">Why "no in-house financing" works in your favor</h2>
        <ul class="mt-5 space-y-3">
            <li class="flex gap-3">
                <svg class="mt-1 h-5 w-5 shrink-0 text-sky-600 dark:text-sky-400" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" /></svg>
                <span class="text-zinc-700 dark:text-zinc-300"><strong>No financing markup baked into your quote.</strong> Contractor-arranged financing is rarely free — its cost tends to live somewhere in the project price.</span>
            </li>
            <li class="flex gap-3">
                <svg class="mt-1 h-5 w-5 shrink-0 text-sky-600 dark:text-sky-400" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" /></svg>
                <span class="text-zinc-700 dark:text-zinc-300"><strong>You shop the money like you shop the remodel.</strong> Your bank competes for your loan; we compete on the build.</span>
            </li>
            <li class="flex gap-3">
                <svg class="mt-1 h-5 w-5 shrink-0 text-sky-600 dark:text-sky-400" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" /></svg>
                <span class="text-zinc-700 dark:text-zinc-300"><strong>Lender-ready paperwork.</strong> Every GS estimate is itemized, and payment terms are spelled out in a written contract — exactly what underwriters ask for.</span>
            </li>
        </ul>

        <h2 class="mt-12 font-heading text-2xl font-bold text-zinc-900 dark:text-white">Know your budget before you borrow</h2>
        <p class="mt-4 text-zinc-700 dark:text-zinc-300">
            We publish typical project ranges so you can size a loan before the first meeting:
            kitchens typically run $35K–$80K+, bathrooms $15K–$60K+, basements $45K–$150K+, and additions
            $200–$400 per square foot depending on scope and finishes. See the
            <a href="{{ route('faq') }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">remodeling FAQ</a>
            for what drives each range.
        </p>
    </div>

    <div class="mx-auto mt-12 max-w-3xl px-4 sm:px-6 lg:px-8">
        <x-faq-section
            heading="Financing questions"
            :collapsed="false"
            :faqs="[
                ['question' => 'Does GS Construction offer financing?', 'answer' => 'Not in-house — and that is deliberate, so no financing cost hides in your quote. Most clients use a HELOC, home-equity loan, or a third-party renovation loan, and we can recommend trusted local lenders our clients have used.'],
                ['question' => 'What will my lender need from my contractor?', 'answer' => 'Typically a written scope of work and cost breakdown, proof of licensing and insurance, and payment terms. Every GS estimate is itemized and every contract spells out payment terms, so the paperwork is lender-ready by default.'],
                ['question' => 'When do I pay for the remodel?', 'answer' => 'Payment terms are defined in your written contract before work begins, and your private client portal shows current change orders and up-to-date balances throughout the project — so you always know exactly where the money stands.'],
            ]"
        />
    </div>

    <x-cta-section
        variant="blue"
        heading="Ready to scope your remodel?"
        description="Get a free in-home estimate with an itemized scope — the same document your lender will want to see."
    />
</x-layouts.app>
