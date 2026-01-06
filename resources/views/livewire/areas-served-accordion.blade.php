<div x-data="{ open: false }" class="mt-4 border-t border-gray-900/10 pt-4 dark:border-white/10">
    <button
        @click="open = !open"
        class="mx-auto flex items-center gap-2 text-xs font-semibold tracking-wide text-gray-600 uppercase hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-300"
    >
        Areas Served by GS Construction
        <svg
            class="size-3 transition-transform duration-200"
            :class="{ 'rotate-180': open }"
            fill="none"
            viewBox="0 0 24 24"
            stroke-width="2"
            stroke="currentColor"
        >
            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
        </svg>
    </button>

    <div
        x-show="open"
        x-collapse
        x-cloak
        class="mt-4"
    >
        <div class="columns-2 gap-x-4 sm:columns-3 md:columns-4 lg:columns-6">
            @foreach($areas as $area)
                <a
                    href="{{ route('area.home', $area) }}"
                    class="block text-xs leading-5 text-gray-500 hover:text-sky-600 dark:text-gray-400 dark:hover:text-sky-400"
                >
                    {{ $area->city }}
                </a>
            @endforeach
        </div>
    </div>
</div>
