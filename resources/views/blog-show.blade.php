@php
    $project = $post->project;
    $typeLabel = \App\Models\Project::projectTypes()[$project?->project_type] ?? 'Project';
    $lightboxImages = \App\Support\Blog\BlogRenderer::lightboxImages($project);
    $review = \App\Support\Blog\BlogRenderer::review($project);
@endphp
<x-layouts.app
    :title="($post->meta_title ?: $post->title) . ' | ' . config('brand.name')"
    :metaDescription="$post->meta_description ?: \Illuminate\Support\Str::limit((string) $post->excerpt, 155)"
>
    @if (! $post->isPublished())
        <meta name="robots" content="noindex,nofollow">
    @endif
    @if ($post->isPublished())
        <x-blog-posting-schema :post="$post" />
        <x-breadcrumb-schema :items="[['name' => 'Blog', 'url' => route('blog.index')], ['name' => $post->title, 'url' => $post->url()]]" />
    @endif

    @push('head')
        <link rel="alternate" type="application/atom+xml" href="{{ route('blog.feed') }}" title="{{ config('brand.name') }} — Project Stories">
    @endpush

    <x-breadcrumbs :items="[['label' => 'Blog', 'url' => route('blog.index')], ['label' => $post->title]]" maxWidth="max-w-6xl" padding="pt-8 pb-0" />

    {{-- One lightbox scope for the whole article: every photo the renderer
         places — cover, pull photos, gallery — opens the same viewer, with
         arrow-key navigation across all of the project's images. --}}
    <article
        class="mx-auto max-w-6xl px-4 pt-10 pb-16 sm:px-6 sm:pt-14 lg:px-8"
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
                @if ($post->published_at)
                    Published <time datetime="{{ $post->published_at->toDateString() }}">{{ $post->published_at->format('F j, Y') }}</time>
                    @if ($post->updated_at && $post->updated_at->gt($post->published_at->copy()->addDay())) · Updated <time datetime="{{ $post->updated_at->toDateString() }}">{{ $post->updated_at->format('F j, Y') }}</time> @endif
                    @if ($post->dated_at) · Project completed {{ $post->dated_at->format('F Y') }} @endif
                @else
                    <time datetime="{{ $post->displayDate()?->toDateString() }}">{{ $post->displayDate()?->format('F j, Y') }}</time>
                @endif
                @if (! $post->isPublished()) · <span class="rounded bg-amber-100 px-1.5 py-0.5 text-xs font-semibold text-amber-800">Draft preview</span> @endif
                @if ($project) · {{ count($lightboxImages) }} photos @endif
            </p>
            @if ($project)
                <div class="mt-5">
                    <x-buttons.cta :href="route('projects.show', $project)" variant="outline-primary" size="sm">See the full project</x-buttons.cta>
                </div>
            @endif
        </header>

        <div class="prose prose-lg prose-zinc mt-10 flow-root max-w-none dark:prose-invert prose-headings:font-heading prose-headings:clear-both prose-a:text-sky-700 dark:prose-a:text-sky-400 prose-p:text-zinc-700 dark:prose-p:text-zinc-300">
            {!! \App\Support\Blog\BlogRenderer::render($post) !!}
        </div>

        @if ($project && $project->collaborators->isNotEmpty())
            <x-project-team :project="$project" class="mt-14 border-t border-zinc-200 pt-10 dark:border-zinc-800" />
        @endif

        @if ($review)
            <aside class="mt-14 border-t border-zinc-200 pt-10 dark:border-zinc-800" aria-label="Homeowner review">
                <p class="mb-6 text-sm font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-400">What the homeowners said</p>
                <x-review-card
                    :testimonial="$review['testimonial']"
                    :paragraphs="$review['paragraphs']"
                    :image-url="$review['imageUrl']"
                    :area-slug="$review['areaSlug']"
                    :wide="true"
                />
            </aside>
        @endif

        @if ($lightboxImages)
            <x-lightbox />
        @endif
    </article>

    <x-cta-section variant="blue" heading="Ready to scope your own {{ strtolower($typeLabel) }}?" class="mt-16" />
</x-layouts.app>
