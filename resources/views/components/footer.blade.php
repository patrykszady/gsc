<footer class="relative z-10 bg-gray-100 dark:bg-gray-900">
    <div class="mx-auto max-w-7xl px-6 py-10 lg:px-8 lg:py-12">
        <div class="xl:grid xl:grid-cols-3 xl:gap-8">
            {{-- Column 1: Logo, Company Name, Contact, Social Icons --}}
            <div class="space-y-6">
                {{-- GS Logo (same as navbar) --}}
                <a href="/" wire:navigate.hover>
                    <img src="{{ asset('favicon-source.png') }}" alt="GS Construction" width="99" height="64" class="h-16 w-auto" />
                </a>

                {{-- Company Name --}}
                <p class="text-sm font-bold tracking-wide text-gray-800 uppercase dark:text-white">
                    <a href="/" wire:navigate.hover class="hover:text-sky-600 dark:hover:text-sky-400">GS Construction</a>
                </p>

                {{-- Contact Info --}}
                <p class="text-sm/6 text-balance text-gray-700 dark:text-gray-300">
                    <a href="tel:8474304439" class="hover:text-sky-600 dark:hover:text-sky-400">(847) 430-4439</a><br>
                    <a href="mailto:patryk@gs.construction" class="hover:text-sky-600 dark:hover:text-sky-400">patryk@gs.construction</a>
                </p>

                {{-- Social Icons --}}
                <div class="flex gap-x-5">
                    @foreach(config('socials') as $key => $social)
                        <flux:tooltip content="{{ $social['label'] }}">
                            <a href="{{ $social['url'] }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-[44px] min-w-[44px] items-center justify-center text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">
                                <span class="sr-only">{{ $social['label'] }}</span>
                                <x-dynamic-component :component="'icons.social.' . $key" class="size-6" />
                            </a>
                        </flux:tooltip>
                    @endforeach
                </div>
            </div>

            {{-- Columns 2-4: Links --}}
            <div class="mt-16 grid grid-cols-2 gap-8 xl:col-span-2 xl:mt-0">
                <div class="md:grid md:grid-cols-2 md:gap-8">
                    {{-- GS Construction Links --}}
                    <div>
                        <h3 class="text-sm/6 font-semibold text-gray-900 dark:text-white">GS Construction</h3>
                        <ul class="mt-4 space-y-1">
                            <li>
                                <a href="/projects" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">Projects</a>
                            </li>
                            <li>
                                <a href="/about" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">About</a>
                            </li>
                            <li>
                                <a href="/testimonials" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">Reviews</a>
                            </li>
                            <li>
                                <a href="/contact" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">Contact</a>
                            </li>
                            <li>
                                <a href="/areas-served" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">Areas Served</a>
                            </li>
                        </ul>
                    </div>

                    {{-- Projects --}}
                    <div class="mt-10 md:mt-0">
                        <h3 class="text-sm/6 font-semibold text-gray-900 dark:text-white">Projects</h3>
                        <ul class="mt-4 space-y-1">
                            <li>
                                <a href="/projects?type=kitchen" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">Kitchen Projects</a>
                            </li>
                            <li>
                                <a href="/projects?type=bathroom" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">Bathroom Projects</a>
                            </li>
                            <li>
                                <a href="/projects?type=home-remodel" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">Home Remodel Projects</a>
                            </li>
                            <li>
                                <a href="/projects?type=mudroom" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">Laundry & Mudroom Projects</a>
                            </li>
                        </ul>
                    </div>
                </div>

                <div class="md:grid md:grid-cols-2 md:gap-8">
                    {{-- Services --}}
                    <div>
                        <h3 class="text-sm/6 font-semibold text-gray-900 dark:text-white">Services</h3>
                        <ul class="mt-4 space-y-1">
                            <li>
                                <a href="/services/kitchen-remodeling" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">Kitchen Remodeling</a>
                            </li>
                            <li>
                                <a href="/services/bathroom-remodeling" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">Bathroom Remodeling</a>
                            </li>
                            <li>
                                <a href="/services/home-remodeling" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">Home Remodeling</a>
                            </li>
                        </ul>
                    </div>

                    {{-- About --}}
                    <div class="mt-10 md:mt-0">
                        <h3 class="text-sm/6 font-semibold text-gray-900 dark:text-white">About</h3>
                        <ul class="mt-4 space-y-1">
                            <li>
                                <a href="/about" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">About Greg & Patryk</a>
                            </li>
                            <li>
                                <a href="/contact" wire:navigate.hover class="inline-block py-2 text-sm/6 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-300">Contact Greg & Patryk</a>
                            </li>
                            {{-- Socials Dropdown --}}
                            <li>
                                <flux:dropdown position="top">
                                    <flux:button variant="ghost" size="sm" icon:trailing="chevron-up" class="!px-0 !py-2 !text-gray-600 hover:!text-gray-900 dark:!text-gray-400 dark:hover:!text-gray-300">
                                        Socials
                                    </flux:button>
                                    <flux:menu>
                                        @foreach(config('socials') as $key => $social)
                                            <flux:menu.item href="{{ $social['url'] }}" target="_blank">
                                                <span class="inline-flex items-center gap-2">
                                                    <x-dynamic-component :component="'icons.social.' . $key" class="size-4" />
                                                    {{ $social['label'] }}
                                                </span>
                                            </flux:menu.item>
                                        @endforeach
                                    </flux:menu>
                                </flux:dropdown>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

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
