<header class="bg-white dark:bg-slate-950" x-data="{ mobileMenuOpen: false, projectsOpen: true }">
    <nav aria-label="Global" class="mx-auto flex max-w-7xl items-center justify-between p-6 lg:px-8">
        {{-- Logo + Brand Name --}}
        <div class="flex items-center gap-x-4">
            <a href="/" wire:navigate.hover class="flex items-center gap-x-3">
                <img src="{{ asset('favicon-source.png') }}" alt="GS Construction" class="h-12 w-auto" />
                <span class="font-heading text-xl font-bold tracking-wide text-zinc-800 dark:text-zinc-100">GS CONSTRUCTION</span>
            </a>
        </div>

        {{-- Mobile menu button --}}
        <div class="flex lg:hidden">
            <button
                type="button"
                @click="mobileMenuOpen = true"
                class="-m-2.5 inline-flex items-center justify-center rounded-md p-2.5 text-gray-700 dark:text-zinc-200"
            >
                <span class="sr-only">Open main menu</span>
                <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                </svg>
            </button>
        </div>

        {{-- Desktop navigation --}}
        <div class="hidden lg:flex lg:items-center lg:gap-x-8">
            <a href="/projects?type=kitchen" wire:navigate.hover class="text-base font-medium text-zinc-700 hover:text-sky-600 dark:text-zinc-200 dark:hover:text-sky-400">Kitchens</a>
            <a href="/projects?type=bathroom" wire:navigate.hover class="text-base font-medium text-zinc-700 hover:text-sky-600 dark:text-zinc-200 dark:hover:text-sky-400">Bathrooms</a>
            <a href="/projects" wire:navigate.hover class="text-base font-bold text-zinc-800 hover:text-sky-600 dark:text-zinc-100 dark:hover:text-sky-400">Projects</a>

            {{-- More menu dropdown --}}
            <div class="relative" x-data="{ open: false }" @mouseenter="open = true" @mouseleave="open = false">
                <button
                    type="button"
                    @click="open = !open"
                    class="flex items-center justify-center rounded-md p-2 text-zinc-700 hover:text-sky-600 dark:text-zinc-200 dark:hover:text-sky-400"
                    :aria-expanded="open"
                >
                    <span class="sr-only">More</span>
                    <svg class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                    </svg>
                </button>

                <div
                    x-show="open"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 translate-y-1"
                    x-transition:enter-end="opacity-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="opacity-100 translate-y-0"
                    x-transition:leave-end="opacity-0 translate-y-1"
                    class="absolute right-0 z-10 mt-3 w-48 overflow-hidden rounded-xl bg-white shadow-lg ring-1 ring-gray-900/5 dark:bg-slate-900 dark:ring-white/10"
                    @click.away="open = false"
                >
                    <div class="py-2">
                        <a href="/about" wire:navigate.hover class="block px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 hover:text-sky-600 dark:text-zinc-200 dark:hover:bg-white/5 dark:hover:text-sky-400">About</a>
                        <a href="/contact" wire:navigate.hover class="block px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 hover:text-sky-600 dark:text-zinc-200 dark:hover:bg-white/5 dark:hover:text-sky-400">Contact</a>
                        <a href="/testimonials" wire:navigate.hover class="block px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 hover:text-sky-600 dark:text-zinc-200 dark:hover:bg-white/5 dark:hover:text-sky-400">Reviews</a>
                    </div>
                </div>
            </div>

            <a href="/testimonials" wire:navigate.hover class="text-base font-bold text-zinc-800 hover:text-sky-600 dark:text-zinc-100 dark:hover:text-sky-400">Reviews</a>
        </div>

        {{-- Desktop CTA --}}
        <div class="hidden lg:flex lg:items-center lg:gap-x-6">
            <flux:button href="/contact" variant="primary" class="font-bold uppercase tracking-wide" @click="trackCTA('Start Your Project', 'navbar_desktop')">
                START YOUR PROJECT
            </flux:button>
        </div>
    </nav>

    {{-- Mobile menu --}}
    <div
        x-show="mobileMenuOpen"
        x-cloak
        class="lg:hidden"
        role="dialog"
        aria-modal="true"
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
                <a href="/" wire:navigate class="flex items-center gap-x-3">
                    <img src="{{ asset('favicon-source.png') }}" alt="GS Construction" class="h-10 w-auto" />
                    <span class="font-heading text-lg font-semibold uppercase tracking-wide text-zinc-800 dark:text-zinc-100">GS CONSTRUCTION</span>
                </a>
                <button type="button" @click="mobileMenuOpen = false" class="-m-2.5 rounded-md p-2.5 text-gray-700 dark:text-zinc-200">
                    <span class="sr-only">Close menu</span>
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <div class="mt-6 flow-root">
                <div class="-my-6 divide-y divide-gray-500/10 dark:divide-white/10">
                    <div class="space-y-2 py-6">
                        <a href="/projects?type=kitchen" wire:navigate class="-mx-3 block rounded-lg px-3 py-2 text-base/7 font-semibold text-gray-900 hover:bg-gray-50 dark:text-zinc-100 dark:hover:bg-white/5">Kitchens</a>
                        <a href="/projects?type=bathroom" wire:navigate class="-mx-3 block rounded-lg px-3 py-2 text-base/7 font-semibold text-gray-900 hover:bg-gray-50 dark:text-zinc-100 dark:hover:bg-white/5">Bathrooms</a>
                        <a href="/projects" wire:navigate class="-mx-3 block rounded-lg px-3 py-2 text-base/7 font-bold text-gray-900 hover:bg-gray-50 dark:text-zinc-100 dark:hover:bg-white/5">Projects</a>
                        <a href="/about" wire:navigate class="-mx-3 block rounded-lg px-3 py-2 text-base/7 font-semibold text-gray-900 hover:bg-gray-50 dark:text-zinc-100 dark:hover:bg-white/5">About</a>
                        <a href="/contact" wire:navigate class="-mx-3 block rounded-lg px-3 py-2 text-base/7 font-semibold text-gray-900 hover:bg-gray-50 dark:text-zinc-100 dark:hover:bg-white/5">Contact</a>
                        <a href="/testimonials" wire:navigate class="-mx-3 block rounded-lg px-3 py-2 text-base/7 font-bold text-gray-900 hover:bg-gray-50 dark:text-zinc-100 dark:hover:bg-white/5">Reviews</a>
                    </div>
                    <div class="py-6">
                        <flux:button href="/contact" variant="primary" class="w-full justify-center" @click="trackCTA('Start Your Project', 'navbar_mobile')">
                            START YOUR PROJECT
                        </flux:button>
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
