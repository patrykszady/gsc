@blaze
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
        ? 'bg-sky-600 py-10 sm:py-12' 
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
    <x-decor-blobs :top="false" centerOpacity="opacity-20" />
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
