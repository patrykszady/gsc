<div class="bg-white dark:bg-zinc-950">
    {{-- <x-breadcrumbs> emits the BreadcrumbList itself; a separate
         <x-breadcrumb-schema> here produced two on one page. --}}
    <x-breadcrumbs :items="[['label' => 'Design Professionals']]" />

    <div class="mx-auto max-w-3xl px-6 pt-2 text-center lg:px-8">
        <p class="text-sm font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400">Designers We Build For</p>
    </div>

    <x-page-hero
        title="Design professionals we work with"
        key-suffix="design-partners"
    />

    <div class="mx-auto max-w-7xl px-6 pb-16 lg:px-8">
        <div class="mx-auto max-w-3xl text-center">
            <p class="mt-8 text-lg text-zinc-600 dark:text-zinc-300">
                {{ $intro }}
            </p>
        </div>

        {{-- One section per discipline. A group with no named firms still
             renders its explanation and links to the matching /trades page —
             the page never implies partners we have not named. --}}
        @foreach($groups as $group)
            <section class="mx-auto mt-16 max-w-5xl">
                <h2 class="font-heading text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">
                    {{ $group['heading'] }}
                </h2>
                <p class="mt-2 max-w-3xl text-zinc-600 dark:text-zinc-400">
                    {{ $group['blurb'] }}
                </p>

                @if(!empty($group['partners']))
                    {{-- rel="noopener" with target=_blank because these leave the
                         site; NOT nofollow — these are real working relationships
                         and an honest outbound link is the point of the page. --}}
                    <div class="mt-6 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach($group['partners'] as $partner)
                            {{-- x-link-card detects the external URL and adds
                                 target=_blank + noopener itself. --}}
                            <x-link-card :href="$partner['url']">
                                <div class="flex size-12 items-center justify-center rounded-xl bg-sky-50 dark:bg-sky-500/10">
                                    <svg class="size-6 text-sky-600 dark:text-sky-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 0 0-5.78 1.128 2.25 2.25 0 0 1-2.4 2.245 4.5 4.5 0 0 0 8.4-2.245c0-.399-.078-.78-.22-1.128Zm0 0a15.998 15.998 0 0 0 3.388-1.62m-5.043-.025a15.994 15.994 0 0 1 1.622-3.395m3.42 3.42a15.995 15.995 0 0 0 4.764-4.648l3.876-5.814a1.151 1.151 0 0 0-1.597-1.597L14.146 6.32a15.996 15.996 0 0 0-4.649 4.763m3.42 3.42a6.776 6.776 0 0 0-3.42-3.42" />
                                    </svg>
                                </div>

                                <h3 class="mt-4 text-lg font-semibold text-zinc-900 dark:text-white">
                                    {{ $partner['name'] }}
                                </h3>

                                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                                    {{ $partner['discipline'] }}@if(!empty($partner['location'])) &middot; {{ $partner['location'] }}@endif
                                </p>

                                <p class="mt-3 flex-1 text-sm leading-6 text-zinc-600 dark:text-zinc-400">
                                    {{ $partner['blurb'] }}
                                </p>

                                <span class="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-sky-700 group-hover:underline dark:text-sky-400">
                                    Visit {{ $partner['name'] }}
                                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                    </svg>
                                </span>
                            </x-link-card>
                        @endforeach
                    </div>
                @endif

                @if(!empty($group['trade_slug']) && \Illuminate\Support\Facades\Route::has('trades.show'))
                    <div class="mt-5">
                        <x-buttons.cta
                            :href="route('trades.show', ['slug' => $group['trade_slug']])"
                            variant="outline"
                            size="sm">
                            How we work with {{ strtolower($group['heading']) }}
                        </x-buttons.cta>
                    </div>
                @endif
            </section>
        @endforeach

        {{-- Working with a designer, plainly stated. Everything here is already
             published on /process — bring your own designer, or use ours. --}}
        <div class="mx-auto mt-16 max-w-4xl">
            <x-faq-section
                heading="Working with a designer on a GS build"
                :collapsed="false"
                :faqs="[
                    [
                        'question' => 'Can I bring my own designer?',
                        'answer' => 'Yes, and plenty of our clients do. If you already have a designer or architect, we build to their drawings and coordinate with them directly through the job — you do not have to relay messages between us.',
                    ],
                    [
                        'question' => 'What if I do not have a designer yet?',
                        'answer' => 'We can introduce you to the studios on this page, or you can design it yourself and we will send you to trusted showrooms for selections. None of the three options changes how we price or run the build.',
                    ],
                    [
                        'question' => 'Do I need an architect or a structural engineer?',
                        'answer' => 'For additions and structural changes, almost always — most Chicago-suburb villages require sealed drawings for the permit, and removing a load-bearing wall needs an engineer\'s sealed spec for the beam and footings. For kitchen and bath remodels inside existing walls, usually neither. We will tell you which your project needs before you spend anything on drawings.',
                    ],
                    [
                        'question' => 'Do I pay the designer through GS Construction?',
                        'answer' => 'No. Design is a separate agreement between you and the studio. Our contract covers construction — labor, materials and the permits — priced line by line before demo day.',
                    ],
                    [
                        'question' => 'Are you paid to recommend these studios?',
                        'answer' => 'No. They are here because we have built their designs and would happily do it again. No referral fees change hands in either direction.',
                    ],
                ]"
            />
        </div>
    </div>

    <x-cta-section
        variant="blue"
        heading="Already working with a designer?"
        description="Send us the drawings and we will price the build line by line — labor, materials and permits — before anything is committed."
    />
</div>
