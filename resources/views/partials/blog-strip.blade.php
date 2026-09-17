{{--
    Three blog posts as cards, the same card the /blog index draws, for the
    pages that should send readers (and crawlers) into the blog: the home
    page, each trade's page, a town's trade page and the foot of a post. A
    post used to be reachable from the index alone.
    Variables: $posts (Collection of BlogPost, with project.images loaded),
               $heading (string), optional $subheading, optional $exclude (post id).
--}}
@php
    $stripPosts = collect($posts ?? [])->reject(fn ($p) => isset($exclude) && (int) $p->id === (int) $exclude)->take(3);
@endphp
@if ($stripPosts->isNotEmpty())
    <section class="mx-auto max-w-7xl px-6 py-12 lg:px-8" aria-label="{{ $heading }}" data-blog-strip>
        <div class="flex items-end justify-between gap-4">
            <div>
                <h2 class="font-heading text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">{{ $heading }}</h2>
                @if (! empty($subheading))
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ $subheading }}</p>
                @endif
            </div>
            <a href="{{ route('blog.index') }}" wire:navigate class="shrink-0 text-sm font-semibold text-sky-600 hover:text-sky-700 dark:text-sky-400">All posts →</a>
        </div>
        <div class="mt-6 grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($stripPosts as $post)
                @php $cover = $post->project?->cover(); @endphp
                <a href="{{ $post->url() }}" wire:navigate class="group flex flex-col overflow-hidden rounded-2xl bg-white shadow-md ring-1 ring-zinc-900/5 transition hover:shadow-xl dark:bg-zinc-800/75 dark:ring-white/10">
                    <div class="relative aspect-4/3 overflow-hidden">
                        @if ($cover)
                            <x-lqip-image :image="$cover" size="medium" width="600" height="450" class="h-full w-full transition duration-300 group-hover:scale-105" />
                        @endif
                    </div>
                    <div class="flex flex-1 flex-col p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-400">
                            {{ \App\Models\Project::projectTypes()[$post->project?->project_type] ?? 'Project' }}{{ $post->project?->location ? ' · ' . $post->project->location : '' }}
                        </p>
                        <h3 class="mt-2 font-heading text-lg font-bold text-zinc-900 group-hover:text-sky-700 dark:text-white">{{ $post->title }}</h3>
                        <p class="mt-2 line-clamp-3 text-sm text-zinc-600 dark:text-zinc-300">{{ $post->excerpt }}</p>
                        <p class="mt-auto pt-4 text-xs text-zinc-500">{{ ($post->published_at ?? $post->displayDate())?->format('M j, Y') }}</p>
                    </div>
                </a>
            @endforeach
        </div>
    </section>
@endif
