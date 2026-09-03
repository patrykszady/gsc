@php $cover = $project->cover(); $shots = $project->images->reject(fn ($i) => $cover && $i->id === $cover->id)->take(6); @endphp
@if ($shots->count() >= 2)
    <div class="not-prose my-8 grid grid-cols-2 gap-3 sm:grid-cols-3">
        @foreach ($shots as $img)
            <a href="{{ route('projects.show', $project) }}" wire:navigate class="block overflow-hidden rounded-xl">
                <x-lqip-image :image="$img" size="medium" width="600" height="450" aspectRatio="4/3" rounded="xl" class="w-full transition duration-300 hover:scale-105" />
            </a>
        @endforeach
    </div>
@endif
