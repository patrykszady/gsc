<section class="overflow-hidden bg-zinc-50 py-8 sm:py-10 dark:bg-slate-950">
    <div class="mx-auto max-w-7xl px-6 lg:px-8">
        <div class="mx-auto grid max-w-2xl grid-cols-1 gap-x-12 gap-y-8 lg:mx-0 lg:max-w-none lg:grid-cols-2 lg:items-start">
            {{-- Text Content --}}
            <div class="lg:pr-8">
                <div class="lg:max-w-lg">
                    <p class="text-sm font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400">About Us</p>
                    <h2 class="font-heading mt-2 whitespace-nowrap text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-100">
                        GS CONSTRUCTION & REMODELING
                    </h2>
                    <p class="mt-4 text-lg text-zinc-700 dark:text-zinc-100">
                        <strong class="font-semibold text-zinc-900 dark:text-white">GS Construction & Remodeling</strong> is a family affair, run by Gregory and Patryk, a dynamic <strong class="font-semibold text-zinc-900 dark:text-white">father-son duo</strong>. We're all about forming genuine connections with our homeowners.
                    </p>
                    <p class="mt-3 text-lg text-zinc-600 dark:text-zinc-200">
                        We make sure you're comfortable with every decision we make together. With our keen eye for detail and top-notch tradesmen, we catch and address concerns early. Plus, we're always on-site, ensuring your project is smooth and stress-free.
                    </p>

                    {{-- Features List with delayed scroll animation --}}
                    <ul
                        x-data="{ shown: false }"
                        x-intersect:enter.once.threshold.55="setTimeout(() => shown = true, 500)"
                        class="mt-6 space-y-3 text-base text-zinc-600 dark:text-zinc-300"
                    >
                        <li
                            x-show="shown"
                            x-transition:enter="transition ease-out duration-500 delay-100"
                            x-transition:enter-start="opacity-0 translate-x-4"
                            x-transition:enter-end="opacity-100 translate-x-0"
                            class="flex items-start gap-3"
                        >
                            <svg class="mt-0.5 size-5 flex-shrink-0 text-sky-500" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z" clip-rule="evenodd" />
                            </svg>
                            <span>Father-son team with combined 4 decades of experience</span>
                        </li>
                        <li
                            x-show="shown"
                            x-transition:enter="transition ease-out duration-500 delay-200"
                            x-transition:enter-start="opacity-0 translate-x-4"
                            x-transition:enter-end="opacity-100 translate-x-0"
                            class="flex items-start gap-3"
                        >
                            <svg class="mt-0.5 size-5 flex-shrink-0 text-sky-500" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z" clip-rule="evenodd" />
                            </svg>
                            <span>On-site supervision for every project</span>
                        </li>
                        <li
                            x-show="shown"
                            x-transition:enter="transition ease-out duration-500 delay-300"
                            x-transition:enter-start="opacity-0 translate-x-4"
                            x-transition:enter-end="opacity-100 translate-x-0"
                            class="flex items-start gap-3"
                        >
                            <svg class="mt-0.5 size-5 flex-shrink-0 text-sky-500" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z" clip-rule="evenodd" />
                            </svg>
                            <span>Transparent communication throughout</span>
                        </li>
                        <li
                            x-show="shown"
                            x-transition:enter="transition ease-out duration-500 delay-500"
                            x-transition:enter-start="opacity-0 translate-x-4"
                            x-transition:enter-end="opacity-100 translate-x-0"
                            class="flex items-start gap-3"
                        >
                            <svg class="mt-0.5 size-5 flex-shrink-0 text-sky-500" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z" clip-rule="evenodd" />
                            </svg>
                            <span>Top-notch craftsmanship guaranteed</span>
                        </li>
                    </ul>

                    {{-- CTA Button --}}
                    <div class="mt-6">
                        <flux:button href="/contact" variant="primary" class="w-full font-semibold uppercase tracking-wide sm:w-auto">
                            Contact Gregory & Patryk
                        </flux:button>
                    </div>
                </div>
            </div>

            {{-- Image + Quote --}}
            <div class="lg:mt-[4.5rem] lg:pl-4">
                <img
                    src="{{ asset('images/greg-patryk.jpg') }}"
                    alt="Gregory and Patryk - GS Construction"
                    class="w-full max-w-lg rounded-xl shadow-xl ring-1 ring-zinc-200 dark:ring-zinc-800 lg:max-w-none"
                />
                {{-- Quote animates only when it enters view (independent of checkmarks) --}}
                <blockquote
                    x-data="{ quoteVisible: false }"
                    x-intersect:enter.once.threshold.35="setTimeout(() => quoteVisible = true, 250)"
                    x-cloak
                    :class="quoteVisible ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-2'"
                    class="mt-4 border-l-4 border-sky-500 pl-4 italic text-lg text-zinc-800 transition duration-700 ease-out dark:text-zinc-100"
                >
                    "Simply put, you're in good hands with us."
                </blockquote>
            </div>
        </div>
    </div>
</section>
