<div class="bg-white dark:bg-gray-950">
    <x-breadcrumb-schema :items="[
        ['name' => 'Our Trade Partners'],
    ]" />

    <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
        <nav class="flex" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-2 text-sm">
                <li><a href="/" wire:navigate class="text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Home</a></li>
                <li class="flex items-center">
                    <svg class="h-4 w-4 shrink-0 text-gray-500" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" /></svg>
                    <span class="ml-2 text-gray-700 dark:text-gray-300">Trade Partners</span>
                </li>
            </ol>
        </nav>
    </div>

    <div class="mx-auto max-w-3xl px-6 pt-2 text-center lg:px-8">
        <p class="text-sm font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400">The Team Behind Every Remodel</p>
    </div>

    <div class="mx-auto mt-4 w-full max-w-7xl px-4 sm:px-6 lg:px-8">
        <div class="relative overflow-hidden rounded-2xl">
            <livewire:main-project-hero-slider
                :images-only="true"
                height-classes="h-[340px] sm:h-[420px] lg:h-[500px]"
                :slides="[
                    ['projectType' => 'kitchen'],
                    ['projectType' => 'bathroom'],
                    ['projectType' => 'home-remodel'],
                ]"
                :key="'trades-index-hero'"
            />
            <div class="pointer-events-none absolute inset-0 z-10 flex items-end bg-linear-to-t from-black/80 via-black/40 to-transparent pb-12 sm:pb-16 lg:pb-20">
                <div class="mx-auto w-full max-w-7xl px-6 lg:px-8">
                    <h1 class="font-heading text-4xl font-bold text-white text-shadow-lg sm:text-5xl lg:text-6xl">
                        The skilled trades behind every GS remodel
                    </h1>
                </div>
            </div>
        </div>
    </div>

    <main class="mx-auto max-w-7xl px-6 pb-16 lg:px-8">
        <div class="mx-auto max-w-3xl text-center">
            <p class="mt-8 text-lg text-zinc-600 dark:text-zinc-300">
                {{ $intro }}
            </p>
        </div>

        {{-- How the GC model works --}}
        <div class="mx-auto mt-12 grid max-w-5xl grid-cols-1 gap-6 sm:grid-cols-3">
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex size-12 items-center justify-center rounded-xl bg-sky-50 dark:bg-sky-500/10">
                    <svg class="size-6 text-sky-600 dark:text-sky-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                    </svg>
                </div>
                <h2 class="mt-4 text-lg font-semibold text-zinc-900 dark:text-white">One contract</h2>
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                    You hire GS Construction — not a dozen separate companies. One agreement, one warranty,
                    one number to call. We hold the relationships (and the responsibility) with every trade.
                </p>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex size-12 items-center justify-center rounded-xl bg-sky-50 dark:bg-sky-500/10">
                    <svg class="size-6 text-sky-600 dark:text-sky-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5" />
                    </svg>
                </div>
                <h2 class="mt-4 text-lg font-semibold text-zinc-900 dark:text-white">One schedule</h2>
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                    Remodels fail in the hand-offs. Your GS project lead sequences every trade — demo, framing,
                    rough-ins, inspections, finishes — so crews arrive ready and no week is wasted waiting.
                </p>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex size-12 items-center justify-center rounded-xl bg-sky-50 dark:bg-sky-500/10">
                    <svg class="size-6 text-sky-600 dark:text-sky-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M8.25 4.5a3.001 3.001 0 00-2.599 1.5H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V8.25A2.25 2.25 0 0018.75 6h-.401A3.001 3.001 0 0015.75 4.5h-7.5zM12 3a1.5 1.5 0 00-1.5 1.5h3A1.5 1.5 0 0012 3z" />
                    </svg>
                </div>
                <h2 class="mt-4 text-lg font-semibold text-zinc-900 dark:text-white">One standard</h2>
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                    Our partners are licensed where Illinois or your town requires it, insured, and proven across
                    years of our projects. Their work passes our punch list before it is ever shown to you.
                </p>
            </div>
        </div>

        {{-- Trade cards --}}
        <div class="mx-auto mt-16 max-w-5xl">
            <h2 class="text-center font-heading text-3xl font-bold tracking-tight text-zinc-900 dark:text-white">
                The trades on a GS project
            </h2>
            <ul class="mt-10 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($trades as $trade)
                    @continue(empty($trade['slug']))
                    <li>
                        <a href="{{ route('trades.show', ['slug' => $trade['slug']]) }}"
                           wire:navigate
                           class="flex h-full flex-col rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm transition hover:border-sky-300 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-sky-500">
                            <div class="flex items-center justify-between">
                                <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $trade['name'] }}</h3>
                                @if(!empty($trade['licensed']))
                                    <span class="ml-3 shrink-0 rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400">Licensed</span>
                                @endif
                            </div>
                            <p class="mt-2 grow text-sm text-zinc-600 dark:text-zinc-400">{{ $trade['summary'] }}</p>
                            <p class="mt-4 text-sm font-semibold text-sky-600 dark:text-sky-400">
                                How we work with {{ strtolower($trade['short']) }} →
                            </p>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>

        {{-- Trade recruiting --}}
        <div class="mx-auto mt-16 max-w-5xl">
            <x-trade-partner-cta />
        </div>

        {{-- Hub FAQ --}}
        <div class="mx-auto mt-16 max-w-4xl">
            <x-faq-section
                heading="Working with trade partners — common questions"
                :collapsed="false"
                :faqs="[
                    [
                        'question' => 'Do I hire or pay the trades separately?',
                        'answer' => 'No. You contract with GS Construction only. We hire, schedule, pay, and supervise every trade partner, and our warranty covers their work on your project.',
                    ],
                    [
                        'question' => 'Are the trades on my project licensed and insured?',
                        'answer' => 'Yes — every partner is insured, and licensed wherever Illinois or your municipality requires it (plumbers and roofers are state-licensed; electricians are licensed at the municipal level). We keep certificates on file and pull the permits.',
                    ],
                    [
                        'question' => 'Who do I talk to when a question comes up mid-project?',
                        'answer' => 'Your GS project lead — one person who knows every trade\'s schedule and scope. You never have to chase an electrician or plumber yourself.',
                    ],
                    [
                        'question' => 'Who actually does the work on my project?',
                        'answer' => 'Every trade on a GS project — carpentry, electrical, plumbing, tile, and the rest — is performed by our long-standing trade partners. What GS delivers directly is everything that makes those trades produce a great remodel: the planning, scheduling, daily supervision, quality control, and a dedicated project lead who owns your job from demo to punch list.',
                    ],
                ]"
            />
        </div>
    </main>

    <x-cta-section
        variant="blue"
        heading="One team for your whole remodel"
        description="Skip the subcontractor juggling. Tell us about your project and we'll bring the right trades — scheduled, supervised, and warrantied."
    />
</div>
