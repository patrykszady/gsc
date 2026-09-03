{{-- A pull photo; the renderer places it in a row beside one or two paragraphs. --}}
<figure class="not-prose my-2">
    <button type="button" @click="open({{ $index }})" class="group block w-full overflow-hidden rounded-xl text-left" aria-label="Open photo">
        <x-lqip-image :image="$image" size="medium" width="600" height="450" aspectRatio="4/3" rounded="xl" class="w-full transition duration-300 group-hover:scale-105" />
    </button>
    @if ($image->caption)
        <figcaption class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $image->caption }}</figcaption>
    @endif
</figure>
