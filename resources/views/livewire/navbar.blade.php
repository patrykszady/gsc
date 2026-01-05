<header class="bg-white dark:bg-slate-950" x-data="{ mobileMenuOpen: false, projectsOpen: true }">
    <nav aria-label="Global" class="mx-auto flex max-w-7xl items-center justify-between p-6 lg:px-8">
        {{-- Logo + Brand Name --}}
        <div class="flex items-center gap-x-4">
            <a href="/" class="flex items-center gap-x-3">
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
            <a href="/projects/kitchens" class="text-base font-medium text-zinc-700 hover:text-sky-600 dark:text-zinc-200 dark:hover:text-sky-400">Kitchens</a>
            <a href="/projects/bathrooms" class="text-base font-medium text-zinc-700 hover:text-sky-600 dark:text-zinc-200 dark:hover:text-sky-400">Bathrooms</a>

            {{-- Projects dropdown --}}
            <div class="relative" x-data="{ open: false }" @mouseenter="open = true" @mouseleave="open = false">
                <button
                    type="button"
                    @click="open = !open"
                    class="flex items-center gap-x-1 text-base font-medium text-zinc-700 hover:text-sky-600 dark:text-zinc-200 dark:hover:text-sky-400"
                    :aria-expanded="open"
                >
                    Projects
                    <svg class="size-5 flex-none text-gray-400 dark:text-zinc-500" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
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
                    class="absolute left-1/2 z-10 mt-3 w-screen max-w-md -translate-x-1/2 overflow-hidden rounded-3xl bg-white shadow-lg ring-1 ring-gray-900/5 dark:bg-slate-900 dark:ring-white/10"
                    @click.away="open = false"
                >
                    <div class="p-4">
                        <div class="group relative flex items-center gap-x-6 rounded-lg p-4 text-sm/6 hover:bg-gray-50 dark:hover:bg-white/5">
                            <div class="flex size-11 flex-none items-center justify-center rounded-lg bg-gray-50 group-hover:bg-white dark:bg-white/5 dark:group-hover:bg-white/10">
                                <svg class="size-6 text-gray-600 group-hover:text-sky-600 dark:text-zinc-300 dark:group-hover:text-sky-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 21v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21m0 0h4.5V3.545M12.75 21h7.5V10.75M2.25 21h1.5m18 0h-18M2.25 9l4.5-1.636M18.75 3l-1.5.545m0 6.205 3 1m1.5.5-1.5-.5M6.75 7.364V3h-3v18m3-13.636 10.5-3.819" />
                                </svg>
                            </div>
                            <div class="flex-auto">
                                <a href="/projects/kitchens" class="block font-semibold text-gray-900 dark:text-zinc-100">
                                    Kitchens
                                    <span class="absolute inset-0"></span>
                                </a>
                                <p class="mt-1 text-gray-600 dark:text-zinc-300">Custom kitchen remodeling</p>
                            </div>
                        </div>
                        <div class="group relative flex items-center gap-x-6 rounded-lg p-4 text-sm/6 hover:bg-gray-50 dark:hover:bg-white/5">
                            <div class="flex size-11 flex-none items-center justify-center rounded-lg bg-gray-50 group-hover:bg-white dark:bg-white/5 dark:group-hover:bg-white/10">
                                <svg class="size-6 text-gray-600 group-hover:text-sky-600 dark:text-zinc-300 dark:group-hover:text-sky-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                                </svg>
                            </div>
                            <div class="flex-auto">
                                <a href="/projects/bathrooms" class="block font-semibold text-gray-900 dark:text-zinc-100">
                                    Bathrooms
                                    <span class="absolute inset-0"></span>
                                </a>
                                <p class="mt-1 text-gray-600 dark:text-zinc-300">Bathroom renovations</p>
                            </div>
                        </div>
                        <div class="group relative flex items-center gap-x-6 rounded-lg p-4 text-sm/6 hover:bg-gray-50 dark:hover:bg-white/5">
                            <div class="flex size-11 flex-none items-center justify-center rounded-lg bg-gray-50 group-hover:bg-white dark:bg-white/5 dark:group-hover:bg-white/10">
                                <svg class="size-6 text-gray-600 group-hover:text-sky-600 dark:text-zinc-300 dark:group-hover:text-sky-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" />
                                </svg>
                            </div>
                            <div class="flex-auto">
                                <a href="/projects/home-remodels" class="block font-semibold text-gray-900 dark:text-zinc-100">
                                    Home Remodels
                                    <span class="absolute inset-0"></span>
                                </a>
                                <p class="mt-1 text-gray-600 dark:text-zinc-300">Complete home transformations</p>
                            </div>
                        </div>
                        <div class="group relative flex items-center gap-x-6 rounded-lg p-4 text-sm/6 hover:bg-gray-50 dark:hover:bg-white/5">
                            <div class="flex size-11 flex-none items-center justify-center rounded-lg bg-gray-50 group-hover:bg-white dark:bg-white/5 dark:group-hover:bg-white/10">
                                <svg class="size-6 text-gray-600 group-hover:text-sky-600 dark:text-zinc-300 dark:group-hover:text-sky-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v17.25m0 0c-1.472 0-2.882.265-4.185.75M12 20.25c1.472 0 2.882.265 4.185.75M18.75 4.97A48.416 48.416 0 0 0 12 4.5c-2.291 0-4.545.16-6.75.47m13.5 0c1.01.143 2.01.317 3 .52m-3-.52 2.62 10.726c.122.499-.106 1.028-.589 1.202a5.988 5.988 0 0 1-2.031.352 5.988 5.988 0 0 1-2.031-.352c-.483-.174-.711-.703-.59-1.202L18.75 4.97Zm-16.5.52c.99-.203 1.99-.377 3-.52m0 0 2.62 10.726c.122.499-.106 1.028-.589 1.202a5.989 5.989 0 0 1-2.031.352 5.989 5.989 0 0 1-2.031-.352c-.483-.174-.711-.703-.59-1.202L5.25 4.97Z" />
                                </svg>
                            </div>
                            <div class="flex-auto">
                                <a href="/projects/basements" class="block font-semibold text-gray-900 dark:text-zinc-100">
                                    Basements
                                    <span class="absolute inset-0"></span>
                                </a>
                                <p class="mt-1 text-gray-600 dark:text-zinc-300">Basement finishing & remodeling</p>
                            </div>
                        </div>
                    </div>
                    <div class="bg-gray-50 px-8 py-4 dark:bg-white/5">
                        <a href="/projects" class="text-sm/6 font-semibold text-gray-900 hover:text-sky-600 dark:text-zinc-100 dark:hover:text-sky-400">
                            View all projects <span aria-hidden="true">&rarr;</span>
                        </a>
                    </div>
                </div>
            </div>

            <a href="/reviews" class="text-base font-bold text-zinc-800 hover:text-sky-600 dark:text-zinc-100 dark:hover:text-sky-400">Reviews</a>
        </div>

        {{-- Desktop CTA --}}
        <div class="hidden lg:flex lg:items-center lg:gap-x-6">
            <flux:button href="/contact" variant="primary" class="font-bold uppercase tracking-wide">
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
                <a href="/" class="flex items-center gap-x-3">
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
                        {{-- Projects accordion --}}
                        <div class="-mx-3">
                            <button
                                type="button"
                                @click="projectsOpen = !projectsOpen"
                                class="flex w-full items-center justify-between rounded-lg py-2 pl-3 pr-3.5 text-base/7 font-semibold text-gray-900 hover:bg-gray-50 dark:text-zinc-100 dark:hover:bg-white/5"
                                :aria-expanded="projectsOpen"
                            >
                                Projects
                                <svg
                                    class="size-5 flex-none transition-transform duration-200"
                                    :class="{ 'rotate-180': projectsOpen }"
                                    viewBox="0 0 20 20"
                                    fill="currentColor"
                                    aria-hidden="true"
                                >
                                    <path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />
                                </svg>
                            </button>
                            <div x-show="projectsOpen" x-cloak class="mt-2 space-y-2">
                                <a href="/projects/kitchens" class="block rounded-lg py-2 pl-6 pr-3 text-sm/7 font-semibold text-gray-900 hover:bg-gray-50 dark:text-zinc-100 dark:hover:bg-white/5">Kitchens</a>
                                <a href="/projects/bathrooms" class="block rounded-lg py-2 pl-6 pr-3 text-sm/7 font-semibold text-gray-900 hover:bg-gray-50 dark:text-zinc-100 dark:hover:bg-white/5">Bathrooms</a>
                                <a href="/projects/home-remodels" class="block rounded-lg py-2 pl-6 pr-3 text-sm/7 font-semibold text-gray-900 hover:bg-gray-50 dark:text-zinc-100 dark:hover:bg-white/5">Home Remodels</a>
                                <a href="/projects/basements" class="block rounded-lg py-2 pl-6 pr-3 text-sm/7 font-semibold text-gray-900 hover:bg-gray-50 dark:text-zinc-100 dark:hover:bg-white/5">Basements</a>
                                <a href="/projects" class="block rounded-lg py-2 pl-6 pr-3 text-sm/7 font-semibold text-gray-900 hover:bg-gray-50 dark:text-zinc-100 dark:hover:bg-white/5">All Projects</a>
                            </div>
                        </div>
                        <a href="/reviews" class="-mx-3 block rounded-lg px-3 py-2 text-base/7 font-semibold text-gray-900 hover:bg-gray-50 dark:text-zinc-100 dark:hover:bg-white/5">Reviews</a>
                    </div>
                    <div class="py-6">
                        <flux:button href="/contact" variant="primary" class="w-full justify-center">
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
