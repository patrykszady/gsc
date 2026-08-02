<section class="overflow-hidden bg-zinc-50 py-8 sm:py-10 dark:bg-zinc-950">
    <div class="mx-auto max-w-7xl px-6 lg:px-8">
        <div class="mx-auto grid max-w-2xl grid-cols-1 gap-x-12 gap-y-8 lg:mx-0 lg:max-w-none lg:grid-cols-2 lg:items-start">
            {{-- Text Content --}}
            <div class="lg:pr-8">
                <div class="lg:max-w-lg">
                    <p class="text-sm font-semibold uppercase tracking-wide text-sky-600 dark:text-sky-400">{{ $content['label'] }}</p>
                    <h2 class="font-heading mt-2 whitespace-nowrap text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-100">
                        {{ $content['heading'] }}
                    </h2>
                    <p class="mt-4 text-lg text-zinc-700 dark:text-zinc-100">
                        {!! $content['intro'] !!}
                    </p>
                    <p class="mt-3 text-lg text-zinc-600 dark:text-zinc-200">
                        {{ $content['body'] }}
                    </p>

                    {{-- Features List — <x-marker-list>, the shared check-bullet
                         idiom, via its slot form because a feature can carry an
                         inline proof link. --}}
                    <x-marker-list class="mt-6 text-base text-zinc-600 dark:text-zinc-300">
                        @foreach($content['features'] as $feature)
                            {{-- A feature is either a plain string or
                                 ['text' =>, 'href' =>, 'linkText' =>] when it
                                 points at a page that backs the claim up. --}}
                            @php $f = is_array($feature) ? $feature : ['text' => $feature]; @endphp
                            <li class="flex items-start gap-2.5">
                                <svg class="mt-0.5 size-5 shrink-0 text-sky-600 dark:text-sky-400" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                </svg>
                                <span>
                                    {{ $f['text'] }}@if(!empty($f['href'])) — <a href="{{ $f['href'] }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">{{ $f['linkText'] }}</a>@endif
                                </span>
                            </li>
                        @endforeach
                    </x-marker-list>

                    {{-- CTA Buttons --}}
                    <div class="mt-6 flex flex-wrap items-center gap-4">
                        <x-buttons.cta href="{{ $content['cta_href'] }}" class="w-full sm:w-auto">
                            {{ $content['cta_text'] }}
                        </x-buttons.cta>
                        {{-- Stay in the area when there is one: from a Barrington
                             page this goes to /areas-served/barrington/about, not
                             the company-wide page. The primary CTA beside it
                             already scopes to the area this way. --}}
                        <x-buttons.cta href="{{ $area?->pageUrl('about') ?? '/about' }}" variant="secondary" class="w-full sm:w-auto">
                            About Us
                        </x-buttons.cta>
                    </div>
                </div>
            </div>

            {{-- Image + Quote --}}
            <div class="lg:mt-[4.5rem] lg:pl-4">
                <livewire:team-photo-slider wire:key="about-slider" />
                {{-- Quote --}}
                <blockquote class="mt-4 border-l-4 border-sky-500 pl-4 italic text-lg text-zinc-800 dark:text-zinc-100">
                    "{{ $content['quote'] }}"
                </blockquote>
            </div>
        </div>
    </div>
</section>
