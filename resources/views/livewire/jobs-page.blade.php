<div class="bg-white dark:bg-slate-950">
    {{-- Hero (shared project slider) --}}
    @php
        $jobSlides = [
            [
                'heading' => 'Build with GS Construction',
                'subheading' => 'Join our crew or partner with us — bilingual tradesmen, subs, designers & suppliers welcome',
                'type' => 'kitchen',
            ],
            [
                'heading' => 'Grow Your Trade With Us',
                'subheading' => 'Steady, quality remodeling work across the Chicago suburbs',
                'type' => 'home-remodel',
            ],
            [
                'heading' => 'Partners & Craftsmen Wanted',
                'subheading' => 'Carpenters, tile, countertops, cabinets, design partners & more',
                'type' => 'bathroom',
            ],
        ];
    @endphp
    <section class="mx-auto w-full max-w-7xl px-4 sm:px-6 lg:px-8">
        <livewire:main-project-hero-slider
            project-type="mixed"
            :slides="$jobSlides"
            :slide-count="3"
            primary-cta-text="Reach Out"
            primary-cta-url="#apply"
            secondary-cta-text="Call (224) 735-4200"
            secondary-cta-url="tel:2247354200"
        />
    </section>

    {{-- Bilingual welcome note --}}
    <div class="mx-auto max-w-7xl px-6 pt-8 lg:px-8">
        <p class="text-base text-gray-600 dark:text-gray-300">
            <span class="font-semibold text-gray-900 dark:text-white">Bilingual welcome.</span>
            We're a family-owned Chicago-suburbs remodeling company, always looking to grow our crew and our circle of
            trusted partners. Buscamos tradesmen y socios bilingües. Witamy polskojęzycznych fachowców.
        </p>
    </div>

    {{-- Who we're looking for --}}
    <section class="mx-auto max-w-7xl px-6 py-16 lg:px-8 lg:py-20">
        <div class="mx-auto max-w-2xl text-center">
            <h2 class="font-heading text-3xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-4xl">
                Who we're looking for
            </h2>
            <p class="mt-4 text-lg/8 text-gray-600 dark:text-gray-300">
                Great remodels are a team effort. Here's who we'd love to hear from.
            </p>
        </div>

        @php
            $audiences = [
                ['icon' => 'M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.336l-3.276 3.277a3.004 3.004 0 0 1-2.25-2.25l3.276-3.276a4.5 4.5 0 0 0-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437 1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008Z', 'title' => 'Tradesmen &amp; craftsmen', 'desc' => 'Carpenters, tile setters, electricians, plumbers, painters, drywall &amp; finish pros. Full-time or steady project work.'],
                ['icon' => 'M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Zm0 3h.008v.008h-.008v-.008Z', 'title' => 'Subcontractors &amp; trade companies', 'desc' => 'Reliable subs and specialty crews who take pride in clean, on-time work and want a consistent pipeline of projects.'],
                ['icon' => 'M9.53 16.122a3 3 0 0 0-5.78 1.128 2.25 2.25 0 0 1-2.4 2.245 4.5 4.5 0 0 0 8.4-2.245c0-.399-.078-.78-.22-1.128Zm0 0a15.998 15.998 0 0 0 3.388-1.62m-5.043-.025a15.994 15.994 0 0 1 1.622-3.395m3.42 3.42a15.995 15.995 0 0 0 4.764-4.648l3.876-5.814a1.151 1.151 0 0 0-1.597-1.597L14.146 6.32a15.996 15.996 0 0 0-4.649 4.763m3.42 3.42a6.776 6.776 0 0 0-3.42-3.42', 'title' => 'Designers &amp; architects', 'desc' => 'Interior designers and architects looking for a builder who respects the vision and executes the details.'],
                ['icon' => 'm21 7.5-9-5.25L3 7.5m18 0-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9', 'title' => 'Showrooms &amp; design centers', 'desc' => 'Kitchen &amp; bath showrooms and design centers (like Studio 41) we can specify and partner with.'],
                ['icon' => 'M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 0 1-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0 1 12 18.375m9.75-12.75c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125m19.5 0v1.5c0 .621-.504 1.125-1.125 1.125M2.25 5.625v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25m-17.25 0h7.5c.621 0 1.125.504 1.125 1.125M3.375 8.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125m0 0h7.5', 'title' => 'Countertop &amp; cabinet partners', 'desc' => 'Stone fabricators, countertop suppliers and cabinet makers who deliver quality on schedule.'],
                ['icon' => 'M20.25 7.5l-.625 10.632a2.25 2.25 0 0 1-2.247 2.118H6.622a2.25 2.25 0 0 1-2.247-2.118L3.75 7.5m8.25 3v6.75m0 0-3-3m3 3 3-3M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z', 'title' => 'Suppliers &amp; manufacturers', 'desc' => 'Material suppliers and manufacturers who want a dependable contractor specifying their products.'],
            ];
        @endphp

        <div class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach($audiences as $item)
                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm transition hover:shadow-md dark:border-white/10 dark:bg-white/5">
                    <div class="flex size-12 items-center justify-center rounded-xl bg-sky-100 text-sky-600 dark:bg-sky-900/40 dark:text-sky-400">
                        <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}" />
                        </svg>
                    </div>
                    <h3 class="mt-4 text-lg font-semibold text-gray-900 dark:text-white">{!! $item['title'] !!}</h3>
                    <p class="mt-2 text-sm/6 text-gray-600 dark:text-gray-300">{!! $item['desc'] !!}</p>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Why work with us --}}
    <section class="bg-gray-50 dark:bg-slate-900/50">
        <div class="mx-auto max-w-7xl px-6 py-16 lg:px-8 lg:py-20">
            <div class="mx-auto max-w-2xl text-center">
                <h2 class="font-heading text-3xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-4xl">
                    Why join, partner &amp; build with GS Construction
                </h2>
            </div>
            @php
                $reasons = [
                    ['title' => 'Steady, quality work', 'desc' => '40+ years of remodeling in Chicagoland means a consistent pipeline of projects for the right people.'],
                    ['title' => 'Respect &amp; fair pay', 'desc' => 'We treat tradesmen and partners the way we want to be treated &mdash; on time, fairly, and with respect.'],
                    ['title' => 'A real team', 'desc' => 'Family-owned and crew-first. We build long-term relationships, not one-off jobs.'],
                ];
            @endphp
            <div class="mt-12 grid gap-6 sm:grid-cols-3">
                @foreach($reasons as $reason)
                    <div class="rounded-2xl bg-white p-6 text-center shadow-sm dark:bg-white/5">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">{!! $reason['title'] !!}</h3>
                        <p class="mt-2 text-sm/6 text-gray-600 dark:text-gray-300">{!! $reason['desc'] !!}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Application / inquiry form --}}
    <section id="apply" class="mx-auto max-w-3xl px-6 py-16 lg:px-8 lg:py-20">
        <div class="mx-auto max-w-2xl text-center">
            <h2 class="font-heading text-3xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-4xl">
                Tell us about yourself
            </h2>
            <p class="mt-4 text-lg/8 text-gray-600 dark:text-gray-300">
                Send us a quick note and we'll get back to you. No portal, no account &mdash; just a real conversation.
            </p>
        </div>

        @if (session('success'))
            <div class="mt-8 rounded-md bg-green-50 p-4 dark:bg-green-900/20">
                <div class="flex">
                    <div class="shrink-0">
                        <svg class="size-5 text-green-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z" clip-rule="evenodd" />
                        </svg>
                    </div>
                    <div class="ml-3">
                        <p class="text-sm font-medium text-green-800 dark:text-green-200">{{ session('success') }}</p>
                    </div>
                </div>
            </div>
        @endif

        @if (session('error'))
            <div class="mt-8 rounded-md bg-red-50 p-4 dark:bg-red-900/20">
                <p class="text-sm font-medium text-red-800 dark:text-red-200">{{ session('error') }}</p>
            </div>
        @endif

        <form wire:submit="submit" class="mt-10 space-y-6">
            {{-- Honeypot (hidden from humans) --}}
            <div class="absolute left-[-9999px]" aria-hidden="true">
                <label for="nickname">Nickname</label>
                <input type="text" id="nickname" wire:model="nickname" tabindex="-1" autocomplete="off" />
            </div>

            <div class="grid gap-6 sm:grid-cols-2">
                {{-- Name --}}
                <div>
                    <label for="job-name" class="block text-sm font-medium text-gray-900 dark:text-white">Name <span class="text-red-500">*</span></label>
                    <input type="text" id="job-name" wire:model="name" autocomplete="name"
                           class="mt-2 block w-full rounded-md border-0 bg-white px-3.5 py-2.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-sky-500 dark:bg-white/5 dark:text-white dark:ring-white/10" />
                    @error('name') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                {{-- Email --}}
                <div>
                    <label for="job-email" class="block text-sm font-medium text-gray-900 dark:text-white">Email <span class="text-red-500">*</span></label>
                    <input type="email" id="job-email" wire:model="email" autocomplete="email"
                           class="mt-2 block w-full rounded-md border-0 bg-white px-3.5 py-2.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-sky-500 dark:bg-white/5 dark:text-white dark:ring-white/10" />
                    @error('email') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                {{-- Phone --}}
                <div>
                    <label for="job-phone" class="block text-sm font-medium text-gray-900 dark:text-white">Phone</label>
                    <input type="tel" id="job-phone" wire:model="phone" autocomplete="tel"
                           class="mt-2 block w-full rounded-md border-0 bg-white px-3.5 py-2.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-sky-500 dark:bg-white/5 dark:text-white dark:ring-white/10" />
                    @error('phone') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                {{-- Applicant type --}}
                <div>
                    <label for="job-type" class="block text-sm font-medium text-gray-900 dark:text-white">I'm reaching out as <span class="text-red-500">*</span></label>
                    <select id="job-type" wire:model="applicantType"
                            class="mt-2 block w-full rounded-md border-0 bg-white px-3.5 py-2.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-sky-500 dark:bg-white/5 dark:text-white dark:ring-white/10">
                        @foreach($applicantTypes as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('applicantType') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                {{-- Trade / specialty --}}
                <div>
                    <label for="job-trade" class="block text-sm font-medium text-gray-900 dark:text-white">Trade / specialty</label>
                    <input type="text" id="job-trade" wire:model="trade" placeholder="e.g. Tile, carpentry, cabinetry"
                           class="mt-2 block w-full rounded-md border-0 bg-white px-3.5 py-2.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-sky-500 dark:bg-white/5 dark:text-white dark:ring-white/10" />
                    @error('trade') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                {{-- Company --}}
                <div>
                    <label for="job-company" class="block text-sm font-medium text-gray-900 dark:text-white">Company <span class="text-gray-400">(if any)</span></label>
                    <input type="text" id="job-company" wire:model="company" autocomplete="organization"
                           class="mt-2 block w-full rounded-md border-0 bg-white px-3.5 py-2.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-sky-500 dark:bg-white/5 dark:text-white dark:ring-white/10" />
                    @error('company') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                {{-- Website --}}
                <div>
                    <label for="job-website" class="block text-sm font-medium text-gray-900 dark:text-white">Website / portfolio</label>
                    <input type="text" id="job-website" wire:model="companyWebsite" placeholder="https://"
                           class="mt-2 block w-full rounded-md border-0 bg-white px-3.5 py-2.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-sky-500 dark:bg-white/5 dark:text-white dark:ring-white/10" />
                    @error('companyWebsite') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                {{-- Languages --}}
                <div>
                    <label for="job-languages" class="block text-sm font-medium text-gray-900 dark:text-white">Languages</label>
                    <input type="text" id="job-languages" wire:model="languages" placeholder="e.g. English, Spanish, Polish"
                           class="mt-2 block w-full rounded-md border-0 bg-white px-3.5 py-2.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-sky-500 dark:bg-white/5 dark:text-white dark:ring-white/10" />
                    @error('languages') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>
            </div>

            {{-- Message --}}
            <div>
                <label for="job-message" class="block text-sm font-medium text-gray-900 dark:text-white">Tell us about your work or partnership <span class="text-red-500">*</span></label>
                <textarea id="job-message" wire:model="message" rows="5" placeholder="Experience, what you're looking for, availability, etc."
                          class="mt-2 block w-full rounded-md border-0 bg-white px-3.5 py-2.5 text-gray-900 shadow-sm ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-sky-500 dark:bg-white/5 dark:text-white dark:ring-white/10"></textarea>
                @error('message') <p class="mt-1 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            {{-- Cloudflare Turnstile (Anti-Spam) --}}
            @if($turnstileEnabled && $turnstileSiteKey)
            <div wire:ignore @class(['sr-only' => $isUSVisitor])>
                @if(!$isUSVisitor)
                <p class="mb-2 text-xs text-zinc-500 dark:text-zinc-400">Please complete the security check below:</p>
                @endif
                <div
                    x-data="{
                        init() {
                            if (!window.turnstile) {
                                const script = document.createElement('script');
                                script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
                                script.async = true;
                                script.defer = true;
                                script.onload = () => this.renderWidget();
                                document.head.appendChild(script);
                            } else {
                                this.renderWidget();
                            }
                        },
                        renderWidget() {
                            if (window.turnstile && this.$refs.turnstileWidget) {
                                window.turnstile.render(this.$refs.turnstileWidget, {
                                    sitekey: '{{ $turnstileSiteKey }}',
                                    theme: 'auto',
                                    size: '{{ $isUSVisitor ? 'invisible' : 'flexible' }}',
                                    callback: (token) => { @this.set('turnstileToken', token); },
                                    'expired-callback': () => { @this.set('turnstileToken', ''); },
                                    'error-callback': () => { @this.set('turnstileToken', ''); }
                                });
                            }
                        }
                    }"
                >
                    <div x-ref="turnstileWidget"></div>
                </div>
            </div>
            @endif

            {{-- Submit --}}
            <div class="flex justify-end">
                <button type="submit"
                        class="inline-flex items-center rounded-md bg-sky-500 px-6 py-3 text-base font-semibold text-white shadow-lg transition hover:bg-sky-600 disabled:opacity-60">
                    <span wire:loading.remove wire:target="submit">Send inquiry</span>
                    <span wire:loading wire:target="submit">Sending...</span>
                </button>
            </div>
        </form>

        <p class="mt-8 text-center text-sm text-gray-500 dark:text-gray-400">
            Prefer to call? Reach us at
            <a href="tel:2247354200" class="font-medium text-sky-600 hover:text-sky-500 dark:text-sky-400">(224) 735-4200</a>
            or email
            <a href="mailto:crew@gs.construction" class="font-medium text-sky-600 hover:text-sky-500 dark:text-sky-400">crew@gs.construction</a>.
        </p>
    </section>
</div>
