<footer class="relative z-10 bg-gray-100 dark:bg-gray-900">
    <div class="mx-auto max-w-7xl px-6 py-10 lg:px-8 lg:py-12">
        {{-- 6-col grid: brand rail takes 1, the five link columns take 5. --}}
        <div class="xl:grid xl:grid-cols-6 xl:gap-8">
            {{-- Column 1: Logo, Company Name, Contact, Social Icons --}}
            <div class="space-y-6">
                {{-- GS Logo (same as navbar) --}}
                <a href="/" wire:navigate.hover aria-label="{{ config('brand.name') }} homepage">
                    <img src="{{ asset('images/logo.svg') }}" alt="" width="80" height="80" class="size-20 dark:hidden" />
                    <img src="{{ asset('images/logo-dark.svg') }}" alt="" width="80" height="80" class="hidden size-20 dark:block" />
                </a>

                {{-- Company Name + legacy trading name (both are real entity
                     names — keeping them crawlable helps branded search). --}}
                <div>
                    <p class="text-sm font-bold tracking-wide text-gray-800 uppercase dark:text-white">
                        <a href="/" wire:navigate.hover class="inline-block py-1 hover:text-sky-600 dark:hover:text-sky-400">{{ config('brand.display_name') }}</a>
                    </p>
                    <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">{{ config('brand.also_known_as') }}</p>
                </div>

                {{-- Contact Info --}}
                <p class="text-sm/6 text-balance text-gray-700 dark:text-gray-300">
                    <a href="tel:{{ config('brand.phone_href') }}" class="inline-block py-1 hover:text-sky-600 dark:hover:text-sky-400">{{ config('brand.phone') }}</a><br>
                    <a href="mailto:{{ config('brand.email') }}" class="inline-block py-1 hover:text-sky-600 dark:hover:text-sky-400">{{ config('brand.email') }}</a>
                </p>

                {{-- Social Icons — 3 per row so the narrow brand rail holds them --}}
                <div class="grid w-fit grid-cols-3 gap-x-2 gap-y-1">
                    @foreach(site_config('socials') as $key => $social)
                        <flux:tooltip content="{{ $social['label'] }}">
                            <a href="{{ $social['url'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">
                                <span class="sr-only">{{ $social['label'] }}</span>
                                <x-dynamic-component :component="'icons.social.' . $key" class="size-6" />
                            </a>
                        </flux:tooltip>
                    @endforeach
                </div>

                {{-- Crawlable review-source links for local SEO citations. --}}
                <div>
                    <p class="text-xs font-semibold tracking-wide text-gray-700 uppercase dark:text-gray-300">Review Profiles</p>
                    {{-- Driven by the `review` flag in config/socials.php so the
                         footer, icon row, and schema sameAs never drift apart. --}}
                    @php $reviewProfiles = array_filter(site_config('socials'), fn ($s) => $s['review'] ?? false); @endphp
                    {{-- Kept on one line: the xl brand rail is only ~176px wide, so
                         the separator is a plain spaced bullet (no mx-* margins) to
                         keep the four labels inside it. Spacing stays symmetric
                         because the markup whitespace collapses into the spaces in
                         the span. A fifth review platform would need a rethink. --}}
                    <p class="mt-2 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300">
                        @foreach($reviewProfiles as $profile)
                            <a href="{{ $profile['url'] }}" target="_blank" rel="noopener noreferrer external" class="hover:text-sky-600 dark:hover:text-sky-400">{{ $profile['label'] }}</a>
                            @if(! $loop->last)<span class="text-gray-400"> &bull; </span>@endif
                        @endforeach
                    </p>
                </div>
            </div>

            {{-- Columns 2-6: five link columns. Previously four columns where
                 "About" carried 12 links plus a socials dropdown; split into
                 Company / Services / Projects / Service Areas / Resources, and
                 the duplicate About/Contact entries were removed so each page
                 gets one link with one anchor text. --}}
            <div class="mt-12 grid grid-cols-2 gap-8 sm:grid-cols-3 xl:col-span-5 xl:mt-0 xl:grid-cols-5">
                <div>
                    <h3 class="text-sm/6 font-semibold text-gray-900 dark:text-white">Company</h3>
                    <ul class="mt-4 space-y-1">
                        <li><a href="/about" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">About Greg &amp; Patryk</a></li>
                        <li><a href="/process" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Our Process</a></li>
                        <li><a href="/reviews" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Reviews</a></li>
                        <li><a href="/trades" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Our Trade Partners</a></li>
                        <li><a href="/jobs" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Careers &amp; Partners</a></li>
                        <li><a href="/contact" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Contact</a></li>
                    </ul>
                </div>

                <div>
                    <h3 class="text-sm/6 font-semibold text-gray-900 dark:text-white">Services</h3>
                    <ul class="mt-4 space-y-1">
                        <li><a href="/services/kitchen-remodeling" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Kitchen Remodeling</a></li>
                        <li><a href="/services/bathroom-remodeling" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Bathroom Remodeling</a></li>
                        <li><a href="/services/home-remodeling" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Home Remodeling</a></li>
                        <li><a href="/services/basement-remodeling" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Basement Remodeling</a></li>
                        <li><a href="/services/home-additions" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Home Additions</a></li>
                    </ul>
                </div>

                <div>
                    <h3 class="text-sm/6 font-semibold text-gray-900 dark:text-white">Projects</h3>
                    <ul class="mt-4 space-y-1">
                        <li><a href="/projects" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">All Projects</a></li>
                        <li><a href="/projects?type=kitchen" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Kitchen Projects</a></li>
                        <li><a href="/projects?type=bathroom" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Bathroom Projects</a></li>
                        <li><a href="/projects?type=home-remodel" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Home Remodel Projects</a></li>
                        <li><a href="/projects?type=mudroom" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Laundry &amp; Mudroom Projects</a></li>
                    </ul>
                </div>

                <div>
                    <h3 class="text-sm/6 font-semibold text-gray-900 dark:text-white">Service Areas</h3>
                    <ul class="mt-4 space-y-1">
                        <li><a href="/areas-served" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">All Areas Served</a></li>
                        <li><a href="/service-area" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Service Area by ZIP</a></li>
                        <li><a href="/areas-served/arlington-heights" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Arlington Heights</a></li>
                        <li><a href="/areas-served/palatine" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Palatine</a></li>
                        <li><a href="/areas-served/schaumburg" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Schaumburg</a></li>
                        <li><a href="/areas-served/barrington" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Barrington</a></li>
                        <li><a href="/areas-served/winnetka" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Winnetka</a></li>
                    </ul>
                </div>

                <div>
                    <h3 class="text-sm/6 font-semibold text-gray-900 dark:text-white">Resources</h3>
                    <ul class="mt-4 space-y-1">
                        <li><a href="/costs" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Remodeling Costs</a></li>
                        <li><a href="/permits" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Permit Guides</a></li>
                        <li><a href="/financing" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Financing</a></li>
                        <li><a href="/warranty" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Warranty</a></li>
                        <li><a href="/insurance-claims" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Insurance Claim Repairs</a></li>
                        <li><a href="/how-to-choose-a-remodeling-contractor" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">How to Choose a Contractor</a></li>
                        <li><a href="/compare" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Compare Contractors</a></li>
                    </ul>
                </div>
            </div>
        </div>

        {{-- Trade Partners strip — /jobs only. Sitewide it put 12 near-identical
             links on every page; on /trades and /trades/{slug} it duplicates
             what those pages already render in-body (full list, sibling chips,
             and the same partner CTA). /jobs is where a visiting tradesperson
             benefits from the list, and /trades stays linked from the nav
             column above on every page for discovery. --}}
        @if(request()->routeIs('jobs.*'))
        <div class="mt-10 border-t border-gray-900/10 pt-8 dark:border-white/10">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <h3 class="shrink-0 text-sm/6 font-semibold text-gray-900 dark:text-white">
                    <a href="/trades" wire:navigate.hover class="hover:text-sky-700 dark:hover:text-sky-400">Trade Partners</a>
                </h3>
                <a href="/contact" wire:navigate.hover
                   class="order-last shrink-0 text-sm/6 font-semibold text-sky-600 hover:text-sky-500 dark:text-sky-400 dark:hover:text-sky-300 sm:order-0">
                    Are you a trade? Get in touch →
                </a>
            </div>
            <ul class="mt-3 flex flex-wrap gap-x-5 gap-y-1">
                @foreach((array) config('trades.trades', []) as $footerTrade)
                    @continue(empty($footerTrade['slug']))
                    <li>
                        <a href="{{ route('trades.show', ['slug' => $footerTrade['slug']]) }}" wire:navigate.hover
                           class="inline-block py-1 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">
                            {{ $footerTrade['short'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
        @endif

        {{-- Bottom Section - Compact --}}
        <div class="mt-8 border-t border-gray-900/10 pt-6 dark:border-white/10">
            <p class="text-center text-xs text-gray-600 dark:text-gray-400">
                GS Construction & Remodeling, Inc. DBA GS Construction & Remodeling. DBA GS Construction. AKA Greg & Son Construction, Co. Copyright &copy; {{ date('Y') }}. We're just a small Construction and Remodeling Company in Chicago.
            </p>
            <p class="mt-1 text-center text-xs text-gray-600 dark:text-gray-400">
                <span class="font-medium">Grzegorz Szady: I Love You dad!</span> <span class="italic">— You encourage and challenge me to strive every day. -Patryk Szady</span>
            </p>
        </div>

        {{-- Areas Served Accordion (Livewire) --}}
        <livewire:areas-served-accordion />
    </div>
</footer>
