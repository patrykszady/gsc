<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['value']));

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

foreach (array_filter((['value']), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    $classes = Flux::classes()
        ->add('relative w-px min-h-4 min-w-4 flex flex-col justify-center items-center text-xs font-medium text-zinc-400 data-active:text-zinc-500 dark:text-white/70 dark:data-active:text-white whitespace-nowrap -translate-x-1/2')
        ->add('mt-2 has-data-flux-slider-tick-line:mt-1')
    ;

    $tickLineClasses = Flux::classes()
        ->add('h-1 w-px bg-black/25 dark:bg-white/25')
    ;
?>

<div <?php echo e($attributes->class($classes)); ?> data-flux-slider-tick data-value="<?php echo e($value); ?>" size="sm" variant="subtle">
    <?php if ($slot->isNotEmpty()): ?>
        <?php echo e($slot); ?>

    <?php else: ?>
        <span data-flux-slider-tick-line class="<?php echo e($tickLineClasses); ?>"></span>
    <?php endif; ?>
</div><?php /**PATH /home/patryk/web/gsc/vendor/livewire/flux-pro/stubs/resources/views/flux/slider/tick.blade.php ENDPATH**/ ?>