@props([
    'testimonials' => collect(),
    'heading' => 'What Customers Say About This Project',
])

@if($testimonials->isNotEmpty())
<section class="bg-white pt-1 pb-6 sm:pt-2 sm:pb-8 dark:bg-zinc-900">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <h2 class="mb-4 text-lg font-bold tracking-tight text-zinc-900 sm:text-xl dark:text-white">
            {{ $heading }}
        </h2>

        <div @class([
            'grid gap-4',
            'sm:grid-cols-1' => $testimonials->count() === 1,
            'sm:grid-cols-2' => $testimonials->count() === 2,
            'sm:grid-cols-2 lg:grid-cols-3' => $testimonials->count() >= 3,
        ])>
            @foreach($testimonials as $testimonial)
                <a
                    href="{{ route('testimonials.show', $testimonial->slug) }}"
                    wire:navigate
                    class="group flex h-full flex-col overflow-hidden rounded-2xl border border-zinc-200/80 bg-white p-5 shadow-sm transition hover:border-sky-400 hover:shadow-md dark:border-white/10 dark:bg-zinc-900/70 dark:hover:border-sky-500"
                >
                    {{-- Stars --}}
                    <div class="mb-3 flex items-center gap-1" aria-label="{{ $testimonial->star_rating ?? 5 }} out of 5 stars">
                        @for($i = 0; $i < ($testimonial->star_rating ?? 5); $i++)
                            <svg class="size-4 fill-amber-400" viewBox="0 0 20 20"><path d="M10 15.27 16.18 19l-1.64-7.03L20 7.24l-7.19-.61L10 0 7.19 6.63 0 7.24l5.46 4.73L3.82 19z"/></svg>
                        @endfor
                    </div>

                    {{-- Review text --}}
                    <blockquote class="flex-1 text-sm leading-6 text-zinc-700 dark:text-zinc-300">
                        <p class="line-clamp-5">"{{ $testimonial->review_description }}"</p>
                    </blockquote>

                    {{-- Reviewer --}}
                    <figcaption class="mt-4 flex items-center justify-between border-t border-zinc-200/80 pt-3 dark:border-white/10">
                        <div>
                            <div class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $testimonial->display_name }}</div>
                            @if($testimonial->project_location)
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $testimonial->project_location }}</div>
                            @endif
                        </div>
                        <span class="text-xs font-medium text-sky-600 group-hover:underline dark:text-sky-400">Read &rarr;</span>
                    </figcaption>
                </a>
            @endforeach
        </div>
    </div>
</section>
@endif
