{{-- Guest layout, not the admin layout: the admin chrome links to
     site-scoped routes (route('admin.dashboard') etc.) and the picker
     deliberately has no site bound yet. --}}
<x-layouts.guest>
    {{-- The hub landing page: every site this install administers.
         Reached at ss.systems/admin (and gs.construction/admin). --}}
    <div class="mx-auto max-w-3xl px-6 py-12">
        <h1 class="font-heading text-2xl font-bold text-zinc-900 dark:text-white">Choose a site</h1>
        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
            You are managing {{ $sites->count() }} {{ Str::plural('site', $sites->count()) }}.
            The site you pick becomes part of the URL, so admin links stay bookmarkable.
        </p>

        <ul class="mt-8 space-y-3">
            @foreach($sites as $site)
                <li>
                    <a href="/admin/{{ $site->primary_host }}"
                       class="group flex items-center justify-between rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm transition hover:border-sky-300 hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-sky-500">
                        <span>
                            <span class="block font-heading text-lg font-semibold text-zinc-900 group-hover:text-sky-700 dark:text-white dark:group-hover:text-sky-400">
                                {{ $site->name }}
                            </span>
                            <span class="mt-0.5 block text-sm text-zinc-500 dark:text-zinc-400">{{ $site->primary_host }}</span>
                        </span>

                        <span class="flex items-center gap-3">
                            @if($site->is_active)
                                <span class="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-400">Live</span>
                            @else
                                {{-- Inactive means the host does not resolve to this site yet,
                                     so the public domain stays noindexed until its theme ships. --}}
                                <span class="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-medium text-amber-700 dark:bg-amber-950/40 dark:text-amber-400">In build</span>
                            @endif
                            <span aria-hidden="true" class="text-zinc-400 group-hover:text-sky-600">&rarr;</span>
                        </span>
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
</x-layouts.guest>
