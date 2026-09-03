@php $cover = $project->cover(); @endphp
@if ($cover)
    <figure class="not-prose clear-both my-8">
        <button type="button" @click="open({{ \App\Support\Blog\BlogRenderer::lightboxIndex($project, $cover) }})" class="group block w-full overflow-hidden rounded-2xl text-left" aria-label="Open cover photo">
            <x-lqip-image :image="$cover" size="hero" width="1200" height="675" aspectRatio="16/9" rounded="2xl" class="w-full transition duration-300 group-hover:scale-[1.02]" />
        </button>
        @if ($cover->caption)
            <figcaption class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $cover->caption }}</figcaption>
        @endif
    </figure>
@endif
