@php $images = $images ?? $project->images; @endphp
@if ($images->isNotEmpty())
    <div class="not-prose clear-both my-8 grid grid-cols-2 gap-3 sm:grid-cols-3">
        @foreach ($images as $img)
            <button type="button" @click="open({{ \App\Support\Blog\BlogRenderer::lightboxIndex($project, $img) }})" class="group block overflow-hidden rounded-xl text-left" aria-label="Open photo">
                <x-lqip-image :image="$img" size="medium" width="600" height="450" aspectRatio="4/3" rounded="xl" class="w-full transition duration-300 group-hover:scale-105" />
            </button>
        @endforeach
    </div>
@endif
