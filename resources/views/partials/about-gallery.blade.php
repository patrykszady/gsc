{{-- Project photo grid for the About hero.

     Shared by /about and every /areas-served/{area}/about, so both show the
     same arrangement.

     Replaces the Tailwind UI staggered hero both pages used to carry: three
     w-40 columns pushed apart by pt-32 / sm:pt-80 / sm:pt-52 / xl:pt-80. Those
     are fixed pixel offsets that know nothing about how many images exist, so
     the grid opened up large voids and the photos read as scattered rather
     than arranged. It also meant six near-identical @if blocks by index, which
     left holes when a tenant had fewer than six images.

     Expects $galleryImages (Collection<ProjectImage>), each with its project
     loaded — every tile links to that photo's own page.

     2 columns on mobile, 3 from sm: up. Each tile zooms on hover, matching the
     project cards elsewhere on the site. --}}
<div class="mt-14 w-full sm:mt-0 lg:max-w-lg lg:flex-none">
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 sm:gap-5">
        @foreach($galleryImages->take(6) as $galleryImage)
            <a
                href="{{ route('projects.image', [$galleryImage->project, $galleryImage]) }}"
                class="group relative block overflow-hidden rounded-xl shadow-lg transition-shadow duration-300 hover:shadow-xl"
                aria-label="View {{ $galleryImage->project?->title ?? 'project photo' }}"
            >
                <x-lqip-image
                    :image="$galleryImage"
                    size="medium"
                    aspectRatio="square"
                    rounded="xl"
                    class="w-full transition duration-300 group-hover:scale-105" />
                <div class="pointer-events-none absolute inset-0 rounded-xl ring-1 ring-zinc-900/10 ring-inset dark:ring-white/10"></div>
            </a>
        @endforeach
    </div>
</div>
