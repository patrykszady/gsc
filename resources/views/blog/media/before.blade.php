{{-- The big "before": full article width, labelled, opens in the lightbox. --}}
@php $before = \App\Support\Blog\BlogRenderer::beforeImage($project); @endphp
@if ($before)
    <figure class="not-prose clear-both my-10">
        <button type="button" @click="open({{ \App\Support\Blog\BlogRenderer::beforeLightboxIndex($project) }})" class="group relative block w-full overflow-hidden rounded-2xl bg-zinc-100 text-left dark:bg-zinc-800" aria-label="Open the before photo">
            <img src="{{ $before['url'] }}" alt="{{ $before['alt'] }}" loading="lazy" decoding="async" class="aspect-16/10 w-full object-cover transition duration-500 group-hover:scale-[1.02] sm:aspect-2/1">
            <span class="absolute left-4 top-4 rounded-full bg-black/65 px-3 py-1.5 text-sm font-semibold uppercase tracking-wide text-white backdrop-blur-sm">Before</span>
        </button>
        <figcaption class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $before['caption'] }}</figcaption>
    </figure>
@endif
