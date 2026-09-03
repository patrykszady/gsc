@php $tl = $project->timelapses->first(fn ($t) => $t->frames->count() >= 3); @endphp
@if ($tl)
    @php
        $frames = $tl->frames;
        // Up to 8 evenly spaced frames — the arc of the build, not every shot.
        $pick = collect(range(0, max(0, $frames->count() - 1), max(1, intdiv(max(1, $frames->count() - 1), 7))))
            ->map(fn ($i) => $frames[$i] ?? null)->filter()->take(8);
    @endphp
    <figure class="not-prose my-8">
        <div class="grid grid-cols-4 gap-2">
            @foreach ($pick as $i => $frame)
                <img src="{{ $frame->url }}" alt="{{ $tl->title ?: 'Construction progress' }} — step {{ $loop->iteration }}" loading="lazy" decoding="async" class="aspect-4/3 w-full rounded-lg object-cover">
            @endforeach
        </div>
        <figcaption class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
            {{ $tl->title ?: 'How the build progressed' }} — {{ $frames->count() }} timelapse frames.
            <a href="{{ route('projects.show', $project) }}#timelapse" wire:navigate class="text-sky-700 hover:underline dark:text-sky-400">Watch the full timelapse</a>
        </figcaption>
    </figure>
@endif
