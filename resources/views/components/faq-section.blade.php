@props(['faqs' => [], 'heading' => 'Frequently Asked Questions'])

@if(count($faqs) > 0)
{{-- FAQ Schema for Google rich results --}}
<x-faq-schema :faqs="$faqs" />

{{-- Visible FAQ section --}}
<section class="bg-white py-16 sm:py-24 dark:bg-zinc-900">
    <div class="mx-auto max-w-7xl px-6 lg:px-8">
        <div class="mx-auto max-w-4xl">
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">
                {{ $heading }}
            </h2>
            <dl class="mt-10 space-y-6 divide-y divide-zinc-900/10 dark:divide-white/10">
                @foreach($faqs as $faq)
                <div x-data="{ open: false }" class="pt-6 first:pt-0">
                    <dt>
                        <button
                            type="button"
                            @click="open = !open"
                            class="flex w-full items-start justify-between text-left text-zinc-900 dark:text-white"
                            :aria-expanded="open"
                        >
                            <span class="text-base font-semibold leading-7">{{ $faq['question'] }}</span>
                            <span class="ml-6 flex h-7 items-center">
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
                    </dt>
                    <dd x-show="open" x-collapse x-cloak class="mt-2 pr-12">
                        <p class="text-base leading-7 text-zinc-600 dark:text-zinc-400">{{ $faq['answer'] }}</p>
                    </dd>
                </div>
                @endforeach
            </dl>
        </div>
    </div>
</section>
@endif
