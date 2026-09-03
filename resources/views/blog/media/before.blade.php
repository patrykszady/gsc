{{-- The "before" photo: pull-photo size, floated beside the paragraph that
     describes the space as we found it. Opens in the lightbox. --}}
@php $before = \App\Support\Blog\BlogRenderer::beforeImage($project); @endphp
@if ($before)
    <figure class="not-prose clear-both my-2 w-full sm:float-right sm:mb-4 sm:ml-8 sm:w-[46%]">
        <button type="button" @click="open({{ \App\Support\Blog\BlogRenderer::beforeLightboxIndex($project) }})" class="group relative block w-full overflow-hidden rounded-xl bg-zinc-100 text-left dark:bg-zinc-800" aria-label="Open the before photo">
            <img src="{{ $before['url'] }}" alt="{{ $before['alt'] }}" loading="lazy" decoding="async" width="600" height="450" class="aspect-4/3 w-full object-cover transition duration-300 group-hover:scale-105">
            <span class="absolute left-3 top-3 rounded-full bg-black/65 px-2.5 py-1 text-xs font-semibold uppercase tracking-wide text-white backdrop-blur-sm">Before</span>
        </button>
        <figcaption class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $before['caption'] }}</figcaption>
    </figure>
@endif
