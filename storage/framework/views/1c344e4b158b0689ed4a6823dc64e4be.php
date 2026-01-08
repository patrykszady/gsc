

<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'filterable' => null,
    'loading' => null,
    'label' => null,
    'value' => null,
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
    'filterable' => null,
    'loading' => null,
    'label' => null,
    'value' => null,
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
    ->add('group/option overflow-hidden data-hidden:hidden group flex items-center px-2 py-1.5 w-full focus:outline-hidden')
    ->add('rounded-md')
    ->add('text-start text-sm font-medium select-none')
    ->add('text-zinc-800 data-active:bg-zinc-100 [&[disabled]]:text-zinc-400 dark:text-white dark:data-active:bg-zinc-600 dark:[&[disabled]]:text-zinc-400')
    ;

$livewireAction = $attributes->whereStartsWith('wire:click')->isNotEmpty();
$alpineAction = $attributes->whereStartsWith('x-on:click')->isNotEmpty();

$loading ??= $loading ?? $livewireAction;

if ($loading) {
    $attributes = $attributes->merge(['wire:loading.attr' => 'data-flux-loading']);
}
?>

<ui-option
    <?php if($value !== null): ?> value="<?php echo e($value); ?>" <?php endif; ?>
    <?php if($value): ?> wire:key="<?php echo e($value); ?>" <?php endif; ?>
    <?php if($filterable === false): ?> filter="manual" <?php endif; ?>
    <?php if($livewireAction || $alpineAction): ?> action <?php endif; ?>
    <?php echo e($attributes->class($classes)); ?>

    data-flux-listbox-option
>
    <div class="w-6 shrink-0 [ui-selected_&]:hidden">
        <?php if (isset($component)) { $__componentOriginal671d3fadccaec635349703a55d028d9b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal671d3fadccaec635349703a55d028d9b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::pillbox.indicator','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::pillbox.indicator'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal671d3fadccaec635349703a55d028d9b)): ?>
<?php $attributes = $__attributesOriginal671d3fadccaec635349703a55d028d9b; ?>
<?php unset($__attributesOriginal671d3fadccaec635349703a55d028d9b); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal671d3fadccaec635349703a55d028d9b)): ?>
<?php $component = $__componentOriginal671d3fadccaec635349703a55d028d9b; ?>
<?php unset($__componentOriginal671d3fadccaec635349703a55d028d9b); ?>
<?php endif; ?>
    </div>

    <?php echo e($label ?? $slot); ?>


    <?php if ($loading): ?>
        <?php if (isset($component)) { $__componentOriginalb06f0c5905a9427a630c5e299af7ce46 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb06f0c5905a9427a630c5e299af7ce46 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::icon.loading','data' => ['class' => 'hidden [[data-flux-loading]>&]:block ms-auto text-zinc-400 [[data-flux-menu-item]:hover_&]:text-current','variant' => 'micro']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::icon.loading'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'hidden [[data-flux-loading]>&]:block ms-auto text-zinc-400 [[data-flux-menu-item]:hover_&]:text-current','variant' => 'micro']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb06f0c5905a9427a630c5e299af7ce46)): ?>
<?php $attributes = $__attributesOriginalb06f0c5905a9427a630c5e299af7ce46; ?>
<?php unset($__attributesOriginalb06f0c5905a9427a630c5e299af7ce46); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb06f0c5905a9427a630c5e299af7ce46)): ?>
<?php $component = $__componentOriginalb06f0c5905a9427a630c5e299af7ce46; ?>
<?php unset($__componentOriginalb06f0c5905a9427a630c5e299af7ce46); ?>
<?php endif; ?>
    <?php endif; ?>
</ui-option><?php /**PATH /home/patryk/web/gsc/vendor/livewire/flux-pro/stubs/resources/views/flux/pillbox/option.blade.php ENDPATH**/ ?>