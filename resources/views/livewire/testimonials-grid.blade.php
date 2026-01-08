<div class="relative isolate bg-white pt-6 pb-6 sm:pt-10 sm:pb-10 dark:bg-gray-900">
    {{-- Review Schema for rich snippets --}}
    <x-review-schema :testimonials="$rawTestimonials" />
    
    <div aria-hidden="true" class="absolute inset-x-0 top-1/2 -z-10 -translate-y-1/2 transform-gpu overflow-hidden opacity-30 blur-3xl">
        <div style="clip-path: polygon(74.1% 44.1%, 100% 61.6%, 97.5% 26.9%, 85.5% 0.1%, 80.7% 2%, 72.5% 32.5%, 60.2% 62.4%, 52.4% 68.1%, 47.5% 58.3%, 45.2% 34.5%, 27.5% 76.7%, 0.1% 64.9%, 17.9% 100%, 27.6% 76.8%, 76.1% 97.7%, 74.1% 44.1%)" class="ml-[max(50%,38rem)] aspect-[1313/771] w-[82.0625rem] bg-gradient-to-tr from-sky-300 to-sky-600"></div>
    </div>
    <div aria-hidden="true" class="absolute inset-x-0 top-0 -z-10 flex transform-gpu overflow-hidden pt-32 opacity-25 blur-3xl sm:pt-40 xl:justify-end">
        <div style="clip-path: polygon(74.1% 44.1%, 100% 61.6%, 97.5% 26.9%, 85.5% 0.1%, 80.7% 2%, 72.5% 32.5%, 60.2% 62.4%, 52.4% 68.1%, 47.5% 58.3%, 45.2% 34.5%, 27.5% 76.7%, 0.1% 64.9%, 17.9% 100%, 27.6% 76.8%, 76.1% 97.7%, 74.1% 44.1%)" class="ml-[-22rem] aspect-[1313/771] w-[82.0625rem] flex-none origin-top-right rotate-[30deg] bg-gradient-to-tr from-sky-300 to-sky-600 xl:mr-[calc(50%-12rem)] xl:ml-0"></div>
    </div>
    <div class="mx-auto max-w-7xl px-6 lg:px-8">
        
        <x-testimonials-header :area="$area" :show-subtitle="true" />
        @php
            // Calculate visible testimonials based on visibleRows
            // Row 1: featured (2 cols) + leftTop + rightTop = 2 from $testimonials
            // Row 2+: 4 testimonials each
            $maxVisible = 2 + (($visibleRows - 1) * 4);
            $list = $testimonials->take($maxVisible)->values();

            $leftTop = $list->get(0);
            $rightTop = $list->get(1);

            $row2 = $list->slice(2, 4)->values();
            $row3 = $list->slice(6, 4)->values();
            
            // Additional rows (row 4, 5, etc.)
            $additionalRows = [];
            for ($i = 4; $i <= $visibleRows; $i++) {
                $startIndex = 2 + (($i - 2) * 4); // row 4 starts at index 10, row 5 at 14, etc.
                $rowData = $list->slice($startIndex, 4)->values();
                if ($rowData->isNotEmpty()) {
                    $additionalRows[] = ['row' => $i, 'testimonials' => $rowData];
                }
            }
        @endphp

        <div 
            x-data="{ visible: false }"
            x-intersect:enter.threshold.25="visible = true"
            :class="visible ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8'"
            class="mx-auto mt-16 grid max-w-2xl grid-cols-1 gap-x-8 gap-y-8 text-sm/6 text-gray-900 transition-all duration-700 ease-out sm:mt-20 sm:grid-cols-2 lg:mx-0 lg:max-w-none lg:grid-cols-4 lg:items-stretch dark:text-gray-100"
        >
            {{-- Featured review (always visible, first on mobile) --}}
            @if ($featured)
                <figure class="order-first flex flex-col rounded-2xl bg-white shadow-lg ring-1 ring-gray-900/5 sm:col-span-2 lg:order-none lg:col-span-2 lg:col-start-2 lg:row-start-1 dark:bg-gray-800/75 dark:shadow-none dark:ring-white/10">
                    <blockquote class="flex-1 p-6 text-lg font-semibold tracking-tight text-gray-900 sm:p-12 sm:text-xl/8 dark:text-white">
                        <p>"{{ Str::limit($featured['description'], 220) }}"</p>
                    </blockquote>
                    <figcaption class="flex flex-col gap-4 border-t border-gray-900/10 px-6 py-4 dark:border-white/10">
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-4 sm:flex-nowrap">
                            <img src="{{ $featured['image'] }}" alt="{{ $featured['name'] }}" class="size-10 flex-none rounded-full bg-gray-50 object-cover dark:bg-gray-700" />
                            <div class="flex-auto">
                                <div class="font-semibold text-gray-900 dark:text-white">{{ $featured['name'] }}</div>
                                <div class="text-gray-600 dark:text-gray-400">
                                    @if($featured['area_slug'])
                                        <a href="/areas/{{ $featured['area_slug'] }}" class="hover:text-sky-600 hover:underline dark:hover:text-sky-400">{{ $featured['location'] }}</a>
                                    @else
                                        {{ $featured['location'] }}
                                    @endif
                                    · {{ $featured['date'] }}
                                </div>
                            </div>
                            <img src="{{ asset('images/gs construction five starts.png') }}" alt="5 Stars" class="h-10 w-auto flex-none" />
                        </div>
                        <div>
                            <x-buttons.cta href="{{ route('testimonials.show', $featured['slug']) }}" variant="secondary" size="sm">
                                Show This Review
                            </x-buttons.cta>
                        </div>
                    </figcaption>
                </figure>
            @endif

            {{-- Left top card (hidden on mobile, visible sm+) --}}
            @if ($leftTop)
                <figure class="hidden flex-col rounded-2xl bg-white p-6 shadow-lg ring-1 ring-gray-900/5 sm:flex lg:col-start-1 lg:row-start-1 dark:bg-gray-800/75 dark:shadow-none dark:ring-white/10">
                    <blockquote class="flex-1 text-gray-900 dark:text-white">
                        <p>"{{ Str::limit($leftTop['description'], 180) }}"</p>
                    </blockquote>
                    <figcaption class="mt-6 flex items-center gap-x-4">
                        <img src="{{ $leftTop['image'] }}" alt="{{ $leftTop['name'] }}" class="size-10 rounded-full bg-gray-50 object-cover dark:bg-gray-700" />
                        <div class="flex-auto">
                            <div class="font-semibold text-gray-900 dark:text-white">{{ $leftTop['name'] }}</div>
                            <div class="text-gray-600 dark:text-gray-400">
                                @if($leftTop['area_slug'])
                                    <a href="/areas/{{ $leftTop['area_slug'] }}" class="hover:text-sky-600 hover:underline dark:hover:text-sky-400">{{ $leftTop['location'] }}</a>
                                @else
                                    {{ $leftTop['location'] }}
                                @endif
                            </div>
                        </div>
                    </figcaption>
                    <div class="mt-4">
                        <x-buttons.cta href="{{ route('testimonials.show', $leftTop['slug']) }}" variant="secondary" size="sm">
                            Show This Review
                        </x-buttons.cta>
                    </div>
                </figure>
            @endif

            {{-- Right top card (hidden on mobile, visible lg+) --}}
            @if ($rightTop)
                <figure class="hidden flex-col rounded-2xl bg-white p-6 shadow-lg ring-1 ring-gray-900/5 lg:col-start-4 lg:row-start-1 lg:flex dark:bg-gray-800/75 dark:shadow-none dark:ring-white/10">
                    <blockquote class="flex-1 text-gray-900 dark:text-white">
                        <p>"{{ Str::limit($rightTop['description'], 180) }}"</p>
                    </blockquote>
                    <figcaption class="mt-6 flex items-center gap-x-4">
                        <img src="{{ $rightTop['image'] }}" alt="{{ $rightTop['name'] }}" class="size-10 rounded-full bg-gray-50 object-cover dark:bg-gray-700" />
                        <div class="flex-auto">
                            <div class="font-semibold text-gray-900 dark:text-white">{{ $rightTop['name'] }}</div>
                            <div class="text-gray-600 dark:text-gray-400">
                                @if($rightTop['area_slug'])
                                    <a href="/areas/{{ $rightTop['area_slug'] }}" class="hover:text-sky-600 hover:underline dark:hover:text-sky-400">{{ $rightTop['location'] }}</a>
                                @else
                                    {{ $rightTop['location'] }}
                                @endif
                            </div>
                        </div>
                    </figcaption>
                    <div class="mt-4">
                        <x-buttons.cta href="{{ route('testimonials.show', $rightTop['slug']) }}" variant="secondary" size="sm">
                            Show This Review
                        </x-buttons.cta>
                    </div>
                </figure>
            @endif

            {{-- Row 2: show first 2 on mobile, all 4 on lg+ --}}
            @foreach ($row2 as $i => $testimonial)
                <figure class="{{ $i >= 2 ? 'hidden lg:flex' : 'flex' }} flex-col rounded-2xl bg-white p-6 shadow-lg ring-1 ring-gray-900/5 lg:col-start-{{ $i + 1 }} lg:row-start-2 dark:bg-gray-800/75 dark:shadow-none dark:ring-white/10">
                    <blockquote class="flex-1 text-gray-900 dark:text-white">
                        <p>"{{ Str::limit($testimonial['description'], 190) }}"</p>
                    </blockquote>
                    <figcaption class="mt-6 flex items-center gap-x-4">
                        <img src="{{ $testimonial['image'] }}" alt="{{ $testimonial['name'] }}" class="size-10 rounded-full bg-gray-50 object-cover dark:bg-gray-700" />
                        <div class="flex-auto">
                            <div class="font-semibold text-gray-900 dark:text-white">{{ $testimonial['name'] }}</div>
                            <div class="text-gray-600 dark:text-gray-400">
                                @if($testimonial['area_slug'])
                                    <a href="/areas/{{ $testimonial['area_slug'] }}" class="hover:text-sky-600 hover:underline dark:hover:text-sky-400">{{ $testimonial['location'] }}</a>
                                @else
                                    {{ $testimonial['location'] }}
                                @endif
                            </div>
                        </div>
                    </figcaption>
                    <div class="mt-4">
                        <x-buttons.cta href="{{ route('testimonials.show', $testimonial['slug']) }}" variant="secondary" size="sm">
                            Show This Review
                        </x-buttons.cta>
                    </div>
                </figure>
            @endforeach

            {{-- Row 3: hidden on mobile, visible lg+ --}}
            @foreach ($row3 as $i => $testimonial)
                <figure class="hidden flex-col rounded-2xl bg-white p-6 shadow-lg ring-1 ring-gray-900/5 lg:col-start-{{ $i + 1 }} lg:row-start-3 lg:flex dark:bg-gray-800/75 dark:shadow-none dark:ring-white/10">
                    <blockquote class="flex-1 text-gray-900 dark:text-white">
                        <p>"{{ Str::limit($testimonial['description'], 190) }}"</p>
                    </blockquote>
                    <figcaption class="mt-6 flex items-center gap-x-4">
                        <img src="{{ $testimonial['image'] }}" alt="{{ $testimonial['name'] }}" class="size-10 rounded-full bg-gray-50 object-cover dark:bg-gray-700" />
                        <div class="flex-auto">
                            <div class="font-semibold text-gray-900 dark:text-white">{{ $testimonial['name'] }}</div>
                            <div class="text-gray-600 dark:text-gray-400">
                                @if($testimonial['area_slug'])
                                    <a href="/areas/{{ $testimonial['area_slug'] }}" class="hover:text-sky-600 hover:underline dark:hover:text-sky-400">{{ $testimonial['location'] }}</a>
                                @else
                                    {{ $testimonial['location'] }}
                                @endif
                            </div>
                        </div>
                    </figcaption>
                    <div class="mt-4">
                        <x-buttons.cta href="{{ route('testimonials.show', $testimonial['slug']) }}" variant="secondary" size="sm">
                            Show This Review
                        </x-buttons.cta>
                    </div>
                </figure>
            @endforeach

            {{-- Additional rows (row 4+) --}}
            @foreach ($additionalRows as $rowData)
                @foreach ($rowData['testimonials'] as $i => $testimonial)
                    <figure 
                        x-data="{ shown: false }"
                        x-init="setTimeout(() => shown = true, {{ $loop->parent->index * 100 + $i * 50 }})"
                        x-show="shown"
                        x-transition:enter="transition ease-out duration-500"
                        x-transition:enter-start="opacity-0 translate-y-8"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        class="flex flex-col rounded-2xl bg-white p-6 shadow-lg ring-1 ring-gray-900/5 lg:col-start-{{ $i + 1 }} lg:row-start-{{ $rowData['row'] }} dark:bg-gray-800/75 dark:shadow-none dark:ring-white/10"
                    >
                        <blockquote class="flex-1 text-gray-900 dark:text-white">
                            <p>"{{ Str::limit($testimonial['description'], 190) }}"</p>
                        </blockquote>
                        <figcaption class="mt-6 flex items-center gap-x-4">
                            <img src="{{ $testimonial['image'] }}" alt="{{ $testimonial['name'] }}" class="size-10 rounded-full bg-gray-50 object-cover dark:bg-gray-700" />
                            <div class="flex-auto">
                                <div class="font-semibold text-gray-900 dark:text-white">{{ $testimonial['name'] }}</div>
                                <div class="text-gray-600 dark:text-gray-400">
                                    @if($testimonial['area_slug'])
                                        <a href="/areas/{{ $testimonial['area_slug'] }}" class="hover:text-sky-600 hover:underline dark:hover:text-sky-400">{{ $testimonial['location'] }}</a>
                                    @else
                                        {{ $testimonial['location'] }}
                                    @endif
                                </div>
                            </div>
                        </figcaption>
                        <div class="mt-4">
                            <x-buttons.cta href="{{ route('testimonials.show', $testimonial['slug']) }}" variant="secondary" size="sm">
                                Show This Review
                            </x-buttons.cta>
                        </div>
                    </figure>
                @endforeach
            @endforeach
        </div>

        {{-- Show More Reviews CTA --}}
        @if($hasMore)
        <div class="mt-12 text-center">
            <button 
                wire:click="loadMore"
                class="inline-flex items-center justify-center rounded-lg bg-sky-500 px-6 py-3 text-base font-semibold uppercase tracking-wide text-white shadow-lg transition hover:bg-sky-600"
            >
                Show More Reviews
            </button>
        </div>
        @endif
    </div>
</div>
