{{--
    The decorative sky gradient blobs behind sections.

    Was pasted verbatim into five files, and the copies had drifted the way
    copies do: testimonials-grid still carried Tailwind v3's bg-gradient-to-tr
    while the rest had moved to v4's bg-linear-to-tr; only projects-grid had
    the pointer-events-none fix (without it the huge invisible blob div can sit
    over content and eat clicks); cta-section ran opacity-20 against 30
    everywhere else. This component keeps every fix for every caller.

    Props
      top            bool   — also render the second, rotated top blob.
      centerOpacity  string — cta-section runs lighter so text on the band
                              stays readable.

    Parent must be `relative` (all five call sites already are).
--}}

@props([
    'top' => true,
    'centerOpacity' => 'opacity-30',
])

<div aria-hidden="true" class="pointer-events-none absolute inset-x-0 top-1/2 -z-10 -translate-y-1/2 transform-gpu overflow-hidden {{ $centerOpacity }} blur-3xl">
    <div style="clip-path: polygon(74.1% 44.1%, 100% 61.6%, 97.5% 26.9%, 85.5% 0.1%, 80.7% 2%, 72.5% 32.5%, 60.2% 62.4%, 52.4% 68.1%, 47.5% 58.3%, 45.2% 34.5%, 27.5% 76.7%, 0.1% 64.9%, 17.9% 100%, 27.6% 76.8%, 76.1% 97.7%, 74.1% 44.1%)" class="ml-[max(50%,38rem)] aspect-[1313/771] w-[82.0625rem] bg-linear-to-tr from-sky-300 to-sky-600"></div>
</div>

@if($top)
    <div aria-hidden="true" class="pointer-events-none absolute inset-x-0 top-0 -z-10 flex transform-gpu overflow-hidden pt-32 opacity-25 blur-3xl sm:pt-40 xl:justify-end">
        <div style="clip-path: polygon(74.1% 44.1%, 100% 61.6%, 97.5% 26.9%, 85.5% 0.1%, 80.7% 2%, 72.5% 32.5%, 60.2% 62.4%, 52.4% 68.1%, 47.5% 58.3%, 45.2% 34.5%, 27.5% 76.7%, 0.1% 64.9%, 17.9% 100%, 27.6% 76.8%, 76.1% 97.7%, 74.1% 44.1%)" class="ml-[-22rem] aspect-[1313/771] w-[82.0625rem] flex-none origin-top-right rotate-[30deg] bg-linear-to-tr from-sky-300 to-sky-600 xl:mr-[calc(50%-12rem)] xl:ml-0"></div>
    </div>
@endif
