@php
    $project = $post->project;
    $typeLabel = \App\Models\Project::projectTypes()[$project?->project_type] ?? 'Project';
    $lightboxImages = \App\Support\Blog\BlogRenderer::lightboxImages($project);
    $review = $project?->testimonials->where('is_hidden', false)->sortByDesc('review_date')->first();
@endphp
<x-layouts.app
    :title="($post->meta_title ?: $post->title) . ' | ' . config('brand.name')"
    :metaDescription="$post->meta_description ?: \Illuminate\Support\Str::limit((string) $post->excerpt, 155)"
>
    @if (! $post->isPublished())
        <meta name="robots" content="noindex,nofollow">
    @endif

    <x-breadcrumbs :items="[['label' => 'Blog', 'url' => route('blog.index')], ['label' => $post->title]]" maxWidth="max-w-6xl" padding="pt-8 pb-0" />

    {{-- One lightbox scope for the whole article: every photo the renderer
         places — cover, pull photos, gallery — opens the same viewer, with
         arrow-key navigation across all of the project's images. --}}
    <article
        class="mx-auto max-w-6xl px-4 pt-10 sm:px-6 sm:pt-14 lg:px-8"
        x-data="{
            lightbox: false,
            currentIndex: 0,
            images: {{ \Illuminate\Support\Js::from($lightboxImages) }},
            open(index) { this.currentIndex = index; this.lightbox = true; document.body.style.overflow = 'hidden'; },
            close() { this.lightbox = false; document.body.style.overflow = ''; },
            next() { this.currentIndex = (this.currentIndex + 1) % this.images.length; },
            prev() { this.currentIndex = (this.currentIndex - 1 + this.images.length) % this.images.length; },
            get current() { return this.images[this.currentIndex]; }
        }"
        @keydown.escape.window="close()"
        @keydown.arrow-right.window="if(lightbox) next()"
        @keydown.arrow-left.window="if(lightbox) prev()"
    >
        <header class="max-w-4xl">
            <p class="text-sm font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-400">
                {{ $typeLabel }}{{ $project?->location ? ' · ' . $project->location : '' }}
            </p>
            <h1 class="mt-1 font-heading text-3xl font-bold tracking-tight text-balance text-zinc-900 sm:text-5xl dark:text-white">{{ $post->title }}</h1>
            <p class="mt-3 text-sm text-zinc-500">
                {{ $post->published_at?->format('F j, Y') ?? 'Draft preview' }}
                @if ($project) · <a href="{{ route('projects.show', $project) }}" wire:navigate class="text-sky-700 hover:underline dark:text-sky-400">See the full project</a>
                · {{ count($lightboxImages) }} photos @endif
            </p>
        </header>

        <div class="prose prose-lg prose-zinc mt-10 flow-root max-w-none dark:prose-invert prose-headings:font-heading prose-headings:clear-both prose-a:text-sky-700 dark:prose-a:text-sky-400 prose-p:text-zinc-700 dark:prose-p:text-zinc-300">
            {!! \App\Support\Blog\BlogRenderer::render($post) !!}
        </div>

        @if ($review)
            <aside class="mt-14 border-t border-zinc-200 pt-10 dark:border-zinc-800" aria-label="Homeowner review">
                <p class="text-sm font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-400">What the homeowners said</p>
                <figure class="mt-4 max-w-4xl">
                    <div class="flex items-center gap-1 text-amber-400" aria-label="{{ $review->star_rating ?? 5 }} out of 5 stars">
                        @for ($i = 0; $i < 5; $i++)
                            <flux:icon.star variant="{{ $i < ($review->star_rating ?? 5) ? 'solid' : 'outline' }}" class="size-5" />
                        @endfor
                    </div>
                    <blockquote class="mt-4 font-heading text-xl leading-relaxed text-zinc-800 sm:text-2xl dark:text-zinc-100">
                        &ldquo;{{ $review->review_description }}&rdquo;
                    </blockquote>
                    <figcaption class="mt-4 text-sm text-zinc-500 dark:text-zinc-400">
                        <span class="font-semibold text-zinc-800 dark:text-zinc-200">{{ $review->display_name }}</span>
                        @if ($review->project_location) · {{ $review->project_location }} @endif
                        @if ($review->review_date) · {{ $review->review_date->format('F Y') }} @endif
                        · <a href="{{ route('reviews.index') }}" wire:navigate class="text-sky-700 hover:underline dark:text-sky-400">More reviews</a>
                    </figcaption>
                </figure>
            </aside>
        @endif

        @if ($lightboxImages)
            <x-lightbox />
        @endif
    </article>

    <x-cta-section variant="blue" heading="Ready to scope your own {{ strtolower($typeLabel) }}?" class="mt-16" />
</x-layouts.app>
