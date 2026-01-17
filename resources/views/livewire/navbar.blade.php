<header class="relative z-50 bg-white dark:bg-slate-950" x-data="{ mobileMenuOpen: false, projectsOpen: true }">
    @php
        $homeUrl = $area ? $area->url : '/';
        $contactUrl = $area ? $area->pageUrl('contact') : '/contact';
    @endphp
    <nav aria-label="Global" class="mx-auto flex max-w-7xl items-center justify-between px-6 py-3 lg:px-8">
        {{-- Logo + Brand Name --}}
        <div class="flex items-center gap-x-4">
            <a href="{{ $homeUrl }}" wire:navigate.hover class="flex items-center gap-x-3">
                <img src="{{ asset('images/logo.svg') }}" alt="GS Construction" width="64" height="64" class="size-16 dark:hidden" />
                <img src="{{ asset('images/logo-dark.svg') }}" alt="GS Construction" width="64" height="64" class="hidden size-16 dark:block" />
                <span class="font-heading text-xl font-bold tracking-wide text-zinc-800 dark:text-zinc-100">GS CONSTRUCTION</span>
            </a>
        </div>

        {{-- Mobile menu button --}}
        <div class="flex lg:hidden">
            <button
                type="button"
                @click="mobileMenuOpen = true"
                class="-m-2.5 inline-flex min-h-[44px] min-w-[44px] items-center justify-center rounded-md p-2.5 text-gray-700 dark:text-zinc-200"
            >
                <span class="sr-only">Open main menu</span>
                <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                </svg>
            </button>
        </div>

        {{-- Desktop navigation --}}
        <div class="hidden lg:flex lg:items-center lg:gap-x-8">
            {{-- All nav links --}}
            @foreach($navLinks as $link)
                <a href="{{ $link['href'] }}" wire:navigate.hover class="text-base {{ $link['bold'] ? 'font-bold text-zinc-800 dark:text-zinc-100' : 'font-medium text-zinc-700 dark:text-zinc-200' }} hover:text-sky-600 dark:hover:text-sky-400">{{ $link['label'] }}</a>
            @endforeach
        </div>

        {{-- Desktop CTA --}}
        <div class="hidden lg:flex lg:items-center lg:gap-x-6">
            <x-buttons.cta :href="$contactUrl" size="sm">
                Start Your Project
            </x-buttons.cta>
        </div>
    </nav>

    {{-- Mobile menu --}}
    <div
        x-show="mobileMenuOpen"
        x-cloak
        class="lg:hidden"
        role="dialog"
        aria-modal="true"
        aria-labelledby="mobile-menu-title"
    >
        {{-- Background backdrop --}}
        <div
            x-show="mobileMenuOpen"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-40 bg-gray-900/50"
            @click="mobileMenuOpen = false"
        ></div>

        {{-- Mobile menu panel --}}
        <div
            x-show="mobileMenuOpen"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
            class="fixed inset-y-0 right-0 z-50 w-full overflow-y-auto bg-white px-6 py-6 sm:max-w-sm sm:ring-1 sm:ring-gray-900/10 dark:bg-slate-950 dark:ring-white/10"
        >
            <div class="flex items-center justify-between">
                <a href="{{ $homeUrl }}" wire:navigate class="flex items-center gap-x-3">
                    <img src="{{ asset('images/logo.svg') }}" alt="GS Construction" width="48" height="48" class="size-12 dark:hidden" />
                    <img src="{{ asset('images/logo-dark.svg') }}" alt="GS Construction" width="48" height="48" class="hidden size-12 dark:block" />
                    <span id="mobile-menu-title" class="font-heading text-lg font-semibold uppercase tracking-wide text-zinc-800 dark:text-zinc-100">GS CONSTRUCTION</span>
                </a>
                <button type="button" @click="mobileMenuOpen = false" class="-m-2.5 min-h-[44px] min-w-[44px] rounded-md p-2.5 text-gray-700 dark:text-zinc-200">
                    <span class="sr-only">Close menu</span>
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div class="mt-6 flow-root">
                <div class="-my-6 divide-y divide-gray-500/10 dark:divide-white/10">
                    <div class="space-y-2 py-6">
                        @foreach($navLinks as $link)
                            <a href="{{ $link['href'] }}" wire:navigate class="-mx-3 block rounded-lg px-3 py-2 text-base/7 {{ $link['bold'] ? 'font-bold' : 'font-semibold' }} text-gray-900 hover:bg-gray-50 dark:text-zinc-100 dark:hover:bg-white/5">{{ $link['label'] }}</a>
                        @endforeach
                    </div>
                    <div class="py-6">
                        <x-buttons.cta :href="$contactUrl" size="sm" class="w-full">
                            Start Your Project
                        </x-buttons.cta>
                        <div class="mt-4 space-y-2 text-center text-sm text-zinc-600 dark:text-zinc-300">
                            <a href="tel:8474304439" class="block hover:text-zinc-900 dark:hover:text-zinc-100">(847) 430-4439</a>
                            <a href="mailto:patryk@gs.construction" class="block hover:text-zinc-900 dark:hover:text-zinc-100">patryk@gs.construction</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>
