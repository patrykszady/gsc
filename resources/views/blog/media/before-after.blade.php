@if ($project->beforeAfters->isNotEmpty())
    <div class="not-prose clear-both my-8 space-y-6">
        @foreach ($project->beforeAfters->take(3) as $pair)
            <figure>
                <div class="grid grid-cols-2 gap-3">
                    <a href="{{ $pair->before_url }}" target="_blank" rel="noopener" class="relative block overflow-hidden rounded-xl">
                        <img src="{{ $pair->before_url }}" alt="Before — {{ $pair->title ?: $project->title }}" loading="lazy" decoding="async" class="aspect-4/3 w-full object-cover transition duration-300 hover:scale-105">
                        <span class="absolute left-3 top-3 rounded-full bg-black/60 px-2.5 py-1 text-xs font-semibold uppercase tracking-wide text-white">Before</span>
                    </a>
                    <a href="{{ $pair->after_url }}" target="_blank" rel="noopener" class="relative block overflow-hidden rounded-xl">
                        <img src="{{ $pair->after_url }}" alt="After — {{ $pair->title ?: $project->title }}" loading="lazy" decoding="async" class="aspect-4/3 w-full object-cover transition duration-300 hover:scale-105">
                        <span class="absolute left-3 top-3 rounded-full bg-sky-600 px-2.5 py-1 text-xs font-semibold uppercase tracking-wide text-white">After</span>
                    </a>
                </div>
                @if ($pair->title)<figcaption class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $pair->title }}</figcaption>@endif
            </figure>
        @endforeach
    </div>
@endif
