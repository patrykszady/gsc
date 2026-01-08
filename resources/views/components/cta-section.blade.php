@props([
    'heading' => 'Ready to Transform Your Home?',
    'description' => 'Let\'s discuss your project. Schedule a free consultation and see why Chicagoland homeowners trust GS Construction.',
    'primaryText' => 'Schedule Free Consultation',
    'primaryHref' => '/contact',
    'secondaryText' => 'View Our Work',
    'secondaryHref' => '/projects',
    'variant' => 'default', // 'default' or 'blue'
])

@php
    $isBlue = $variant === 'blue';
    $containerClasses = $isBlue 
        ? 'bg-sky-600 py-16 sm:py-24' 
        : 'relative isolate px-6 py-12 mt-8 sm:mt-12 lg:px-8';
    $headingClasses = $isBlue
        ? 'font-heading text-3xl font-bold tracking-tight text-white sm:text-4xl'
        : 'font-heading text-4xl font-bold tracking-tight text-zinc-900 sm:text-5xl dark:text-white';
    $descriptionClasses = $isBlue
        ? 'mx-auto mt-4 max-w-2xl text-lg text-white'
        : 'mx-auto mt-6 max-w-xl text-lg/8 text-zinc-600 dark:text-zinc-300';
    $buttonVariants = $isBlue
        ? ['primary' => 'white', 'secondary' => 'white-secondary']
        : ['primary' => 'primary', 'secondary' => 'secondary'];
@endphp

<!-- CTA section -->
<div class="{{ $containerClasses }}">
    @if(!$isBlue)
    <div aria-hidden="true" class="absolute inset-x-0 top-1/2 -z-10 -translate-y-1/2 transform-gpu overflow-hidden opacity-20 blur-3xl">
        <div style="clip-path: polygon(74.1% 44.1%, 100% 61.6%, 97.5% 26.9%, 85.5% 0.1%, 80.7% 2%, 72.5% 32.5%, 60.2% 62.4%, 52.4% 68.1%, 47.5% 58.3%, 45.2% 34.5%, 27.5% 76.7%, 0.1% 64.9%, 17.9% 100%, 27.6% 76.8%, 76.1% 97.7%, 74.1% 44.1%)" class="ml-[max(50%,38rem)] aspect-[1313/771] w-[82.0625rem] bg-linear-to-tr from-sky-300 to-sky-600"></div>
    </div>
    @endif
    <div class="{{ $isBlue ? 'mx-auto max-w-7xl px-4 sm:px-6 lg:px-8' : 'mx-auto max-w-2xl' }} text-center">
        <h2 class="{{ $headingClasses }}">
            {{ $heading }}
        </h2>
        <p class="{{ $descriptionClasses }}">
            {{ $description }}
        </p>
        <div class="{{ $isBlue ? 'mt-8 flex flex-col sm:flex-row gap-4 justify-center' : 'mt-10 flex items-center justify-center gap-x-6' }}">
            <x-buttons.cta :href="$primaryHref" :variant="$buttonVariants['primary']" size="lg">
                {{ $primaryText }}
            </x-buttons.cta>
            <x-buttons.cta :href="$secondaryHref" :variant="$buttonVariants['secondary']" size="lg">
                {{ $secondaryText }}
            </x-buttons.cta>
        </div>
    </div>
</div>
