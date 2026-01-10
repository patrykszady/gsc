<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'href' => null,
    'variant' => 'primary', // primary, secondary
    'size' => 'md', // sm, md, lg
    'onDark' => false, // force light text for dark backgrounds (like hero sliders)
]));

foreach ($attributes->all() as $__key => $__value) {
    if (in_array($__key, $__propNames)) {
        $$__key = $$__key ?? $__value;
    } else {
        $__newAttributes[$__key] = $__value;
    }
}

$attributes = new \Illuminate\View\ComponentAttributeBag($__newAttributes);

unset($__propNames);
unset($__newAttributes);

foreach (array_filter(([
    'href' => null,
    'variant' => 'primary', // primary, secondary
    'size' => 'md', // sm, md, lg
    'onDark' => false, // force light text for dark backgrounds (like hero sliders)
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    $baseClasses = 'inline-flex items-center justify-center rounded-lg font-semibold tracking-wide transition';
    
    $sizes = [
        'sm' => 'px-4 py-2 text-sm',
        'md' => 'px-5 py-2.5 text-sm',
        'lg' => 'px-6 py-3 text-base',
    ];
    
    $variants = [
        'primary' => 'uppercase bg-sky-500 text-white shadow-lg hover:bg-sky-600',
        'secondary' => $onDark 
            ? 'capitalize border border-transparent text-white hover:border-white'
            : 'capitalize border border-transparent text-zinc-700 hover:border-zinc-400 dark:text-white dark:hover:border-white',
        'white' => 'uppercase bg-white text-sky-600 shadow-lg hover:bg-sky-50 hover:shadow-xl',
        'white-secondary' => 'capitalize border border-transparent text-white hover:border-white',
    ];
    
    $sizeClasses = $sizes[$size] ?? $sizes['md'];
    $variantClasses = $variants[$variant] ?? $variants['primary'];
    
    // Auto-detect tracking location from current route
    $trackLocation = request()->route()?->getName() ?? Str::slug(request()->path());
?>

<a 
    <?php if($href): ?> href="<?php echo e($href); ?>" wire:navigate <?php endif; ?>
    @click="trackCTA($el.textContent.trim(), '<?php echo e($trackLocation); ?>')"
    <?php echo e($attributes->merge(['class' => $baseClasses . ' ' . $sizeClasses . ' ' . $variantClasses])); ?>

>
    <?php echo e($slot); ?>

    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($variant === 'secondary'): ?>
    <span class="ml-2">&rarr;</span>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</a>
<?php /**PATH /home/patryk/web/gsc/resources/views/components/buttons/cta.blade.php ENDPATH**/ ?>