<section class="overflow-hidden bg-zinc-50 py-8 sm:py-10 dark:bg-slate-950">
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

                    {{-- Features List --}}
                    <ul class="mt-6 space-y-3 text-base text-zinc-600 dark:text-zinc-300">
                        @foreach($content['features'] as $feature)
                        <li class="flex items-start gap-3">
                            <svg class="mt-0.5 size-5 flex-shrink-0 text-sky-500" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z" clip-rule="evenodd" />
                            </svg>
                            <span>{{ $feature }}</span>
                        </li>
                        @endforeach
                    </ul>

                    {{-- CTA Buttons --}}
                    <div class="mt-6 flex flex-wrap items-center gap-4">
                        <x-buttons.cta href="{{ $content['cta_href'] }}" class="w-full sm:w-auto">
                            {{ $content['cta_text'] }}
                        </x-buttons.cta>
                        <x-buttons.cta href="/about" variant="secondary" class="w-full sm:w-auto">
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
