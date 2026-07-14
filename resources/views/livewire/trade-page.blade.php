<div class="bg-white dark:bg-gray-950">
    <x-breadcrumb-schema :items="[
        ['name' => 'Our Trade Partners', 'url' => route('trades.index')],
        ['name' => $trade['name']],
    ]" />

    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <nav class="flex" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-2 text-sm">
                <li><a href="/" wire:navigate class="text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Home</a></li>
                <li class="flex items-center">
                    <svg class="h-4 w-4 shrink-0 text-gray-500" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" /></svg>
                    <a href="{{ route('trades.index') }}" wire:navigate class="ml-2 text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Trade Partners</a>
                </li>
                <li class="flex items-center">
                    <svg class="h-4 w-4 shrink-0 text-gray-500" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" /></svg>
                    <span class="ml-2 text-gray-700 dark:text-gray-300">{{ $trade['name'] }}</span>
                </li>
            </ol>
        </nav>
    </div>

    <main class="mx-auto max-w-7xl px-6 pb-16 lg:px-8">
        <div class="mx-auto max-w-3xl">
            <div class="flex flex-wrap items-center gap-3">
                <p class="text-sm font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400">Trade Partners</p>
                @if(!empty($trade['licensed']))
                    <span class="rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">
                        Licensed &amp; Insured
                    </span>
                @else
                    <span class="rounded-full bg-sky-50 px-2.5 py-0.5 text-xs font-semibold text-sky-700 dark:bg-sky-500/10 dark:text-sky-400">
                        Insured &amp; GS-Vetted
                    </span>
                @endif
            </div>

            <h1 class="mt-3 font-heading text-4xl font-bold tracking-tight text-zinc-900 sm:text-5xl dark:text-white">
                {{ $trade['name'] }} on your remodel
            </h1>

            <p class="mt-4 text-lg text-zinc-600 dark:text-zinc-300">
                {{ $trade['summary'] }}
            </p>

            {{-- What they do --}}
            <h2 class="mt-12 font-heading text-2xl font-bold text-zinc-900 dark:text-white">
                What {{ strtolower($trade['short']) }} work looks like on a GS project
            </h2>
            <ul class="mt-5 space-y-3">
                @foreach(($trade['what'] ?? []) as $item)
                    <li class="flex gap-3">
                        <svg class="mt-1 h-5 w-5 shrink-0 text-sky-600 dark:text-sky-400" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" /></svg>
                        <span class="text-zinc-700 dark:text-zinc-300">{{ $item }}</span>
                    </li>
                @endforeach
            </ul>

            {{-- When you need them --}}
            <h2 class="mt-12 font-heading text-2xl font-bold text-zinc-900 dark:text-white">
                When your project needs this trade
            </h2>
            <p class="mt-4 text-zinc-700 dark:text-zinc-300">
                {{ $trade['when'] }}
            </p>

            {{-- How GS vets & supervises (shared standard) --}}
            <div class="mt-12 rounded-2xl border border-zinc-200 bg-zinc-50 p-6 sm:p-8 dark:border-zinc-800 dark:bg-zinc-900">
                <h2 class="font-heading text-2xl font-bold text-zinc-900 dark:text-white">
                    How GS vets and supervises this trade
                </h2>
                <ul class="mt-5 space-y-3 text-zinc-700 dark:text-zinc-300">
                    <li class="flex gap-3">
                        <span class="font-semibold text-sky-600 dark:text-sky-400">1.</span>
                        <span><strong>Credentials on file.</strong> Licensing wherever Illinois or your municipality requires it, plus current insurance certificates — checked before anyone sets foot on your property.</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="font-semibold text-sky-600 dark:text-sky-400">2.</span>
                        <span><strong>Proven on our jobs.</strong> Our bench is built from crews who have delivered on GS projects for years — not whoever answers a listing this week.</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="font-semibold text-sky-600 dark:text-sky-400">3.</span>
                        <span><strong>Supervised and sequenced.</strong> Your GS project lead schedules the crew, walks their work, and holds it to our punch-list standard before the next trade builds on top of it.</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="font-semibold text-sky-600 dark:text-sky-400">4.</span>
                        <span><strong>Covered by one warranty.</strong> Their work on your project is backed by GS — you call us, not them, if anything ever needs attention.</span>
                    </li>
                </ul>
            </div>
        </div>

        {{-- Trade FAQ --}}
        @if(!empty($trade['faq']))
            <div class="mx-auto mt-12 max-w-3xl">
                <x-faq-section
                    heading="{{ $trade['name'] }} — common questions"
                    :collapsed="false"
                    :faqs="collect($trade['faq'])->map(fn ($f) => ['question' => $f['q'], 'answer' => $f['a']])->all()"
                />
            </div>
        @endif

        {{-- Trade recruiting (tailored to this trade) --}}
        @php
            $tradeSingular = \Illuminate\Support\Str::singular($trade['name']);
            $tradeArticle = in_array(strtolower(substr($tradeSingular, 0, 1)), ['a', 'e', 'i', 'o', 'u'], true)
                || str_starts_with($tradeSingular, 'HVAC') ? 'an' : 'a';
            $tradeLower = str_starts_with($tradeSingular, 'HVAC')
                ? 'HVAC ' . strtolower(substr($tradeSingular, 5))
                : strtolower($tradeSingular);
        @endphp
        <div class="mx-auto mt-12 max-w-3xl">
            <x-trade-partner-cta
                :heading="'Are you ' . $tradeArticle . ' ' . $tradeLower . '? Partner with GS Construction.'"
                :description="'If you take pride in your ' . strtolower($trade['short']) . ' work and show up when you say you will, we want to meet you. Steady projects across the North Shore and northwest suburbs, clear scopes, and fast pay.'"
            />
        </div>

        {{-- Related trades --}}
        <div class="mx-auto mt-12 max-w-3xl">
            <h2 class="font-heading text-xl font-bold text-zinc-900 dark:text-white">More trades on a GS remodel</h2>
            <div class="mt-4 flex flex-wrap gap-2">
                @foreach($otherTrades as $other)
                    <a href="{{ route('trades.show', ['slug' => $other['slug']]) }}"
                       wire:navigate
                       class="rounded-full border border-zinc-200 bg-white px-3.5 py-1.5 text-sm text-zinc-700 transition hover:border-sky-300 hover:text-sky-700 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:border-sky-500 dark:hover:text-sky-400">
                        {{ $other['short'] }}
                    </a>
                @endforeach
                <a href="{{ route('trades.index') }}" wire:navigate
                   class="rounded-full bg-sky-600 px-3.5 py-1.5 text-sm font-semibold text-white transition hover:bg-sky-500">
                    All trade partners →
                </a>
            </div>
        </div>
    </main>

    <x-cta-section
        variant="blue"
        heading="Ready to scope your remodel?"
        description="Tell us about your project — we'll bring the right trades, one schedule, and one warranty."
    />
</div>
