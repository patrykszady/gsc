@php $cover = $project->cover(); @endphp
@if ($cover)
    <figure class="not-prose my-8 overflow-hidden rounded-2xl">
        <x-lqip-image :image="$cover" size="hero" width="1200" height="675" aspectRatio="16/9" rounded="2xl" class="w-full" />
        @if ($cover->caption)
            <figcaption class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $cover->caption }}</figcaption>
        @endif
    </figure>
@endif
