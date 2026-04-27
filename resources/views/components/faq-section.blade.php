@blaze
@props([
    'faqs' => [],
    'heading' => 'Frequently Asked Questions',
    'sectionClasses' => 'bg-white py-12 sm:py-16 dark:bg-zinc-900',
    'collapsed' => true,
    'contentMaxWidth' => 'max-w-none',
])

@if(count($faqs) > 0)
{{-- FAQ Schema for Google rich results --}}
<x-faq-schema :faqs="$faqs" />

{{-- Visible FAQ section --}}
<section class="{{ $sectionClasses }}">
    <div class="mx-auto max-w-7xl px-6 lg:px-8">
        <div class="mx-auto {{ $contentMaxWidth }}">
            @if($collapsed)
            <x-faq-card x-data="{ open: false }" class="overflow-visible">
                <button
                    type="button"
                    @click="open = !open"
                    class="flex w-full items-center justify-between px-5 py-3 text-left sm:px-6"
                    :aria-expanded="open"
                >
                    <h2 class="text-lg font-bold tracking-tight text-zinc-900 sm:text-xl dark:text-white">
                        {{ $heading }}
                    </h2>
                    <span class="ml-6 flex h-7 items-center text-zinc-900 dark:text-white">
                        <svg
                            class="size-6 transition-transform duration-200"
                            :class="{ 'rotate-180': open }"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke-width="1.5"
                            stroke="currentColor"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
                        </svg>
                    </span>
                </button>

                <div
                    x-show="open"
                    x-transition.duration.150ms
                    x-cloak
                    class="border-t border-zinc-200/80 px-0 py-5 dark:border-white/10"
                    style="display: none;"
                >
                    <dl class="space-y-4 px-5 sm:px-6">
            @else
            <h2 class="text-lg font-bold tracking-tight text-zinc-900 sm:text-xl dark:text-white">
                {{ $heading }}
            </h2>
            <dl class="mt-8 space-y-4">
            @endif
                @foreach($faqs as $faq)
                <div class="border-b border-zinc-200/80 px-5 py-4 last:border-b-0 dark:border-white/10 sm:px-6">
                    <dt class="text-base font-semibold leading-7 text-zinc-900 dark:text-white">{{ $faq['question'] }}</dt>
                    <dd class="mt-2">
                        <p class="text-base leading-7 text-zinc-600 dark:text-zinc-400">{{ $faq['answer'] }}</p>
                    </dd>
                </div>
                @endforeach
            </dl>
            @if($collapsed)
                </div>
            </x-faq-card>
            @endif
        </div>
    </div>
</section>
@endif
