<x-layouts.app
    :title="'Project Stories & Remodeling Blog | ' . config('brand.name')"
    metaDescription="Real remodeling projects, told step by step — the before, the build, and the after — from a family-owned contractor in the Chicago suburbs."
>
    <x-breadcrumbs :items="[['label' => 'Blog']]" maxWidth="max-w-6xl" padding="pt-8 pb-0" />

    <div class="mx-auto max-w-6xl px-4 pt-10 sm:px-6 sm:pt-14 lg:px-8">
        <p class="text-sm font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-400">Project stories</p>
        <h1 class="mt-1 font-heading text-3xl font-bold tracking-tight text-balance text-zinc-900 sm:text-4xl dark:text-white">
            Remodels, told the way they actually happened
        </h1>
        <p class="mt-4 max-w-2xl text-lg text-zinc-600 dark:text-zinc-300">
            Every post is one real project — the plan, the permits, the build, the before and after — written from the job itself.
        </p>

        @if ($posts->isEmpty())
            <p class="mt-12 text-zinc-500">First stories are on their way.</p>
        @else
            <div class="mt-12 grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($posts as $post)
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
                            <h2 class="mt-2 font-heading text-lg font-bold text-zinc-900 group-hover:text-sky-700 dark:text-white">{{ $post->title }}</h2>
                            <p class="mt-2 line-clamp-3 text-sm text-zinc-600 dark:text-zinc-300">{{ $post->excerpt }}</p>
                            <p class="mt-auto pt-4 text-xs text-zinc-500">{{ $post->published_at?->format('M j, Y') }}</p>
                        </div>
                    </a>
                @endforeach
            </div>
            <div class="mt-10">{{ $posts->links() }}</div>
        @endif
    </div>

    <x-cta-section variant="blue" heading="Have a project like these in mind?" class="mt-16" />
</x-layouts.app>
