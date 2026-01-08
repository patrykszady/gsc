<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'placeholder' => null,
    'invalid' => null,
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
    'placeholder' => null,
    'invalid' => null,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
$classes = Flux::classes()
    ->add('min-w-12 shrink flex-1 outline-none ms-1')
    ->add('placeholder-zinc-400 dark:placeholder-zinc-400 disabled:placeholder-zinc-400/70 dark:disabled:placeholder-zinc-500')
    ->add('data-invalid:text-red-500 dark:data-invalid:text-red-400');

$name = $attributes->whereStartsWith('wire:model')->first();

$invalid ??= ($name && $errors->has($name));

$loading = $attributes->whereStartsWith('wire:model.live')->isNotEmpty();

if ($loading) {
    $attributes = $attributes->merge(['wire:loading.attr' => 'data-flux-loading']);
}
?>

<input
    type="text"
    <?php echo e($attributes->class($classes)); ?>

    <?php if($invalid): ?> aria-invalid="true" data-invalid <?php endif; ?>
    placeholder="<?php echo e($placeholder); ?>"
    data-placeholder="<?php echo e($placeholder); ?>"
    data-flux-pillbox-input
><?php /**PATH /home/patryk/web/gsc/vendor/livewire/flux-pro/stubs/resources/views/flux/pillbox/input.blade.php ENDPATH**/ ?>