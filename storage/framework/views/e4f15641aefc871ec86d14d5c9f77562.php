

<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'placeholder' => null,
    'suffix' => null,
    'size' => null,
    'max' => null,
    'input' => null
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
    'suffix' => null,
    'size' => null,
    'max' => null,
    'input' => null
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
        ->add('truncate flex gap-2 text-start flex-1 text-zinc-700')
        ->add('[[disabled]_&]:text-zinc-500 dark:text-zinc-300 dark:[[disabled]_&]:text-zinc-400');

    $optionClasses = Flux::classes()
        ->add('px-2 flex text-zinc-700 dark:text-zinc-200 bg-zinc-400/15 dark:bg-zinc-400/40')
        ->add('cursor-default') // Combobox trigger sets cursor-text, so we need to reset it here...
        ->add(match($size) {
            default => 'rounded-md py-1 text-base sm:text-sm leading-4',
            'sm' => 'rounded-sm py-[calc(0.125rem+1px)] text-sm leading-4',
        });

    $removeClasses = Flux::classes()
        ->add('px-1 -me-2 text-zinc-400 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200')
        ->add(match($size) {
            default => 'py-[calc(0.25rem-1px)] -my-[calc(0.25rem-1px)]',
            'sm' => 'py-[calc(0.25rem-2px)] -my-[calc(0.25rem-2px)]',
        });
?>

<ui-selected <?php echo e($attributes->class($classes)); ?>>
    <?php if ($placeholder): ?>
        <div class="contents" wire:ignore x-ignore>
            <template name="placeholder">
                <span class="ms-1 text-zinc-400 [[disabled]_&]:text-zinc-400/70 dark:text-zinc-400 dark:[[disabled]_&]:text-zinc-500" data-flux-pillbox-placeholder>
                    <?php echo e($placeholder); ?>

                </span>
            </template>
        </div>
    <?php endif; ?>

    <template name="option">
        <div <?php echo e($attributes->class($optionClasses)); ?>>
            <div class="font-medium"><slot name="text"></slot></div>

            <ui-selected-remove <?php echo e($attributes->class($removeClasses)); ?>>
                <?php if (isset($component)) { $__componentOriginal155e76c41fe51242bc25d269fabf82f5 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal155e76c41fe51242bc25d269fabf82f5 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::icon.x-mark','data' => ['variant' => 'micro','class' => $size === 'xs' ? 'size-3' : '']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::icon.x-mark'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['variant' => 'micro','class' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($size === 'xs' ? 'size-3' : '')]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal155e76c41fe51242bc25d269fabf82f5)): ?>
<?php $attributes = $__attributesOriginal155e76c41fe51242bc25d269fabf82f5; ?>
<?php unset($__attributesOriginal155e76c41fe51242bc25d269fabf82f5); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal155e76c41fe51242bc25d269fabf82f5)): ?>
<?php $component = $__componentOriginal155e76c41fe51242bc25d269fabf82f5; ?>
<?php unset($__componentOriginal155e76c41fe51242bc25d269fabf82f5); ?>
<?php endif; ?>
            </ui-selected-remove>
        </div>
    </template>

    <div class="flex flex-wrap gap-1 grow">
        <div class="contents" wire:ignore x-ignore>
            <template name="options">
                <div class="contents">
                    <slot></slot>
                </div>
            </template>
        </div>
        
        <?php echo e($input); ?>

    </div>
</ui-selected><?php /**PATH /home/patryk/web/gsc/vendor/livewire/flux-pro/stubs/resources/views/flux/pillbox/selected.blade.php ENDPATH**/ ?>