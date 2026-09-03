@php
    $project = $post->project;
    $cover = $project?->cover();
    $typeLabel = \App\Models\Project::projectTypes()[$project?->project_type] ?? 'Project';
@endphp
<x-layouts.app
    :title="($post->meta_title ?: $post->title) . ' | ' . config('brand.name')"
    :metaDescription="$post->meta_description ?: \Illuminate\Support\Str::limit((string) $post->excerpt, 155)"
>
    @if (! $post->isPublished())
        <meta name="robots" content="noindex,nofollow">
    @endif

    <x-breadcrumbs :items="[['label' => 'Blog', 'url' => route('blog.index')], ['label' => $post->title]]" maxWidth="max-w-3xl" padding="pt-8 pb-0" />

    <article class="mx-auto max-w-3xl px-4 pt-10 sm:px-6 sm:pt-14 lg:px-8">
        <p class="text-sm font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-400">
            {{ $typeLabel }}{{ $project?->location ? ' · ' . $project->location : '' }}
        </p>
        <h1 class="mt-1 font-heading text-3xl font-bold tracking-tight text-balance text-zinc-900 sm:text-4xl dark:text-white">{{ $post->title }}</h1>
        <p class="mt-3 text-sm text-zinc-500">
            {{ $post->published_at?->format('F j, Y') ?? 'Draft preview' }}
            @if ($project) · <a href="{{ route('projects.show', $project) }}" wire:navigate class="text-sky-700 hover:underline dark:text-sky-400">See the full project</a> @endif
        </p>

        <div class="prose prose-zinc mt-8 max-w-none dark:prose-invert prose-headings:font-heading prose-a:text-sky-700 dark:prose-a:text-sky-400">
            {!! \App\Support\Blog\BlogRenderer::render($post) !!}
        </div>
    </article>

    <x-cta-section variant="blue" heading="Ready to scope your own {{ strtolower($typeLabel) }}?" class="mt-16" />
</x-layouts.app>
