<div class="relative isolate bg-white pt-6 pb-6 sm:pt-10 sm:pb-10 dark:bg-gray-900">
    <div aria-hidden="true" class="absolute inset-x-0 top-1/2 -z-10 -translate-y-1/2 transform-gpu overflow-hidden opacity-30 blur-3xl">
        <div style="clip-path: polygon(74.1% 44.1%, 100% 61.6%, 97.5% 26.9%, 85.5% 0.1%, 80.7% 2%, 72.5% 32.5%, 60.2% 62.4%, 52.4% 68.1%, 47.5% 58.3%, 45.2% 34.5%, 27.5% 76.7%, 0.1% 64.9%, 17.9% 100%, 27.6% 76.8%, 76.1% 97.7%, 74.1% 44.1%)" class="ml-[max(50%,38rem)] aspect-[1313/771] w-[82.0625rem] bg-gradient-to-tr from-sky-300 to-sky-600"></div>
    </div>
    <div aria-hidden="true" class="absolute inset-x-0 top-0 -z-10 flex transform-gpu overflow-hidden pt-32 opacity-25 blur-3xl sm:pt-40 xl:justify-end">
        <div style="clip-path: polygon(74.1% 44.1%, 100% 61.6%, 97.5% 26.9%, 85.5% 0.1%, 80.7% 2%, 72.5% 32.5%, 60.2% 62.4%, 52.4% 68.1%, 47.5% 58.3%, 45.2% 34.5%, 27.5% 76.7%, 0.1% 64.9%, 17.9% 100%, 27.6% 76.8%, 76.1% 97.7%, 74.1% 44.1%)" class="ml-[-22rem] aspect-[1313/771] w-[82.0625rem] flex-none origin-top-right rotate-[30deg] bg-gradient-to-tr from-sky-300 to-sky-600 xl:mr-[calc(50%-12rem)] xl:ml-0"></div>
    </div>
    <div class="mx-auto max-w-7xl px-6 lg:px-8">
        <div class="mx-auto max-w-2xl text-center">
            <h2 class="text-base/7 font-semibold text-sky-600 dark:text-sky-400">Testimonials</h2>
            <p class="mt-2 font-heading text-4xl font-semibold tracking-tight text-balance text-gray-900 sm:text-5xl dark:text-white">
                @if($area)
                    Your neighbours in {{ $area->city }} love us
                @else
                    Your neighbours love us
                @endif
            </p>
        </div>
        @php
            // 4 visible rows on desktop: top row (left + featured + right) + row2 (4) + row3 (4) + row4 (4) = 15 total
            // Featured is separate, so we need 2 for top row sides + 12 for rows 2-4 = 14 from $testimonials
            $list = $testimonials->take(14)->values();

            $leftTop = $list->get(0);
            $rightTop = $list->get(1);

            $row2 = $list->slice(2, 4)->values();
            $row3 = $list->slice(6, 4)->values();
            $row4 = $list->slice(10, 4)->values();
        @endphp

        <div class="mx-auto mt-16 grid max-w-2xl grid-cols-1 gap-x-8 gap-y-8 text-sm/6 text-gray-900 sm:mt-20 sm:grid-cols-2 lg:mx-0 lg:max-w-none lg:grid-cols-4 lg:items-stretch dark:text-gray-100">
            {{-- Featured review (always visible, first on mobile) --}}
            @if ($featured)
                <figure class="order-first flex flex-col rounded-2xl bg-white shadow-lg ring-1 ring-gray-900/5 sm:col-span-2 lg:order-none lg:col-span-2 lg:col-start-2 lg:row-start-1 dark:bg-gray-800/75 dark:shadow-none dark:ring-white/10">
                    <blockquote class="flex-1 p-6 text-lg font-semibold tracking-tight text-gray-900 sm:p-12 sm:text-xl/8 dark:text-white">
                        <p>"{{ Str::limit($featured['description'], 220) }}"</p>
                    </blockquote>
                    <figcaption class="flex flex-wrap items-center gap-x-4 gap-y-4 border-t border-gray-900/10 px-6 py-4 sm:flex-nowrap dark:border-white/10">
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
                        <div>
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
                        <div>
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
                </figure>
            @endif

            {{-- Row 2: show first 2 on mobile, all 4 on lg+ --}}
            @foreach ($row2 as $i => $testimonial)
                <figure class="{{ $i >= 2 ? 'hidden lg:block' : '' }} rounded-2xl bg-white p-6 shadow-lg ring-1 ring-gray-900/5 lg:col-start-{{ $i + 1 }} lg:row-start-2 dark:bg-gray-800/75 dark:shadow-none dark:ring-white/10">
                    <blockquote class="text-gray-900 dark:text-white">
                        <p>"{{ Str::limit($testimonial['description'], 190) }}"</p>
                    </blockquote>
                    <figcaption class="mt-6 flex items-center gap-x-4">
                        <img src="{{ $testimonial['image'] }}" alt="{{ $testimonial['name'] }}" class="size-10 rounded-full bg-gray-50 object-cover dark:bg-gray-700" />
                        <div>
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
                </figure>
            @endforeach

            {{-- Row 3: hidden on mobile, visible lg+ --}}
            @foreach ($row3 as $i => $testimonial)
                <figure class="hidden rounded-2xl bg-white p-6 shadow-lg ring-1 ring-gray-900/5 lg:col-start-{{ $i + 1 }} lg:row-start-3 lg:block dark:bg-gray-800/75 dark:shadow-none dark:ring-white/10">
                    <blockquote class="text-gray-900 dark:text-white">
                        <p>"{{ Str::limit($testimonial['description'], 190) }}"</p>
                    </blockquote>
                    <figcaption class="mt-6 flex items-center gap-x-4">
                        <img src="{{ $testimonial['image'] }}" alt="{{ $testimonial['name'] }}" class="size-10 rounded-full bg-gray-50 object-cover dark:bg-gray-700" />
                        <div>
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
                </figure>
            @endforeach

            {{-- Row 4: hidden on mobile, visible lg+ --}}
            @foreach ($row4 as $i => $testimonial)
                <figure class="hidden rounded-2xl bg-white p-6 shadow-lg ring-1 ring-gray-900/5 lg:col-start-{{ $i + 1 }} lg:row-start-4 lg:block dark:bg-gray-800/75 dark:shadow-none dark:ring-white/10">
                    <blockquote class="text-gray-900 dark:text-white">
                        <p>"{{ Str::limit($testimonial['description'], 190) }}"</p>
                    </blockquote>
                    <figcaption class="mt-6 flex items-center gap-x-4">
                        <img src="{{ $testimonial['image'] }}" alt="{{ $testimonial['name'] }}" class="size-10 rounded-full bg-gray-50 object-cover dark:bg-gray-700" />
                        <div>
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
                </figure>
            @endforeach
        </div>
    </div>
</div>
