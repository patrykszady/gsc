{{--
    "Worked on this project with": the partners credited on a project — role
    (linked to our /trades page), name and site (linked to theirs), and what
    they did on the job. Shared by the project page and the blog post.
--}}
@props(['project', 'heading' => 'Worked on this project with'])

@if ($project->collaborators->isNotEmpty())
    <section {{ $attributes }} aria-label="Project team">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $heading }}</h2>
        <ul class="mt-3 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($project->collaborators as $c)
                <li class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                    <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">
                        @if ($c->tradeSlug())
                            <a href="{{ route('trades.show', $c->tradeSlug()) }}" wire:navigate class="hover:text-sky-700 dark:hover:text-sky-400">{{ $c->roleLabel() }}</a>
                        @else
                            {{ $c->roleLabel() }}
                        @endif
                    </p>
                    <p class="mt-1 font-heading text-lg font-bold text-zinc-900 dark:text-white">
                        @if ($c->url)
                            <a href="{{ $c->url }}" target="_blank" rel="noopener" class="hover:text-sky-700 dark:hover:text-sky-400">{{ $c->name }}</a>
                        @else
                            {{ $c->name }}
                        @endif
                    </p>
                    @if ($c->contribution())
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ $c->contribution() }}</p>
                    @endif
                    @if ($c->host())
                        <a href="{{ $c->url }}" target="_blank" rel="noopener" class="mt-2 inline-block text-sm text-sky-700 hover:underline dark:text-sky-400">{{ $c->host() }}</a>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
@endif
