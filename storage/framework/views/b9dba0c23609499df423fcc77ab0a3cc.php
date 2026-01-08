<?php foreach (([ 'searchable' ]) as $__key => $__value) {
    $__consumeVariable = is_string($__key) ? $__key : $__value;
    $$__consumeVariable = is_string($__key) ? $__env->getConsumableComponentData($__key, $__value) : $__env->getConsumableComponentData($__value);
} ?>

<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'searchable' => null,
    'search' => null,
    'empty' => null,
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
    'searchable' => null,
    'search' => null,
    'empty' => null,
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
    ->add('[:where(&)]:min-w-48 [:where(&)]:max-h-[20rem] p-[.3125rem] scroll-py-[.3125rem]')
    ->add('rounded-lg shadow-xs')
    ->add('border border-zinc-200 dark:border-zinc-600')
    ->add('bg-white dark:bg-zinc-700')
    ;

// Searchable can also be a slot...
if (is_object($searchable)) $search = $searchable;
?>

<?php if (! $searchable): ?>
    <ui-options popover="manual" <?php echo e($attributes->class($classes)); ?> data-flux-options>
        <?php echo e($slot); ?>

    </ui-options>
<?php else: ?>
    <div popover="manual" class="[:where(&)]:min-w-48 [&:popover-open]:flex [&:popover-open]:flex-col rounded-lg shadow-xs border border-zinc-200 dark:border-zinc-600 bg-white dark:bg-zinc-700 p-[.3125rem]" data-flux-options>
        <?php if ($search): ?> <?php echo e($search); ?> <?php else: ?>
            <?php if (isset($component)) { $__componentOriginal9b83fc0eac1b36ad00145c63dd7fca36 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal9b83fc0eac1b36ad00145c63dd7fca36 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::select.search','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::select.search'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal9b83fc0eac1b36ad00145c63dd7fca36)): ?>
<?php $attributes = $__attributesOriginal9b83fc0eac1b36ad00145c63dd7fca36; ?>
<?php unset($__attributesOriginal9b83fc0eac1b36ad00145c63dd7fca36); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal9b83fc0eac1b36ad00145c63dd7fca36)): ?>
<?php $component = $__componentOriginal9b83fc0eac1b36ad00145c63dd7fca36; ?>
<?php unset($__componentOriginal9b83fc0eac1b36ad00145c63dd7fca36); ?>
<?php endif; ?>
        <?php endif; ?>

        <ui-options class="max-h-[20rem] overflow-y-auto -me-[.3125rem] -mt-[.3125rem] pt-[.3125rem] pe-[.3125rem] -mb-[.3125rem] pb-[.3125rem] scroll-py-[.3125rem]">
            <?php if ($empty): ?>
                <?php if (is_string($empty)): ?>
                    <?php if (isset($component)) { $__componentOriginaldfa284edfb9b1400ff1a3e7e4e8c6e10 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginaldfa284edfb9b1400ff1a3e7e4e8c6e10 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::select.option.empty','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::select.option.empty'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?><?php echo __($empty); ?> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginaldfa284edfb9b1400ff1a3e7e4e8c6e10)): ?>
<?php $attributes = $__attributesOriginaldfa284edfb9b1400ff1a3e7e4e8c6e10; ?>
<?php unset($__attributesOriginaldfa284edfb9b1400ff1a3e7e4e8c6e10); ?>
<?php endif; ?>
<?php if (isset($__componentOriginaldfa284edfb9b1400ff1a3e7e4e8c6e10)): ?>
<?php $component = $__componentOriginaldfa284edfb9b1400ff1a3e7e4e8c6e10; ?>
<?php unset($__componentOriginaldfa284edfb9b1400ff1a3e7e4e8c6e10); ?>
<?php endif; ?>
                <?php else: ?>
                    <?php echo e($empty); ?>

                <?php endif; ?>
            <?php else: ?>
                <?php if (isset($component)) { $__componentOriginaldfa284edfb9b1400ff1a3e7e4e8c6e10 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginaldfa284edfb9b1400ff1a3e7e4e8c6e10 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::select.option.empty','data' => ['whenLoading' => ''.__('Loading...').'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::select.option.empty'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['when-loading' => ''.__('Loading...').'']); ?>
                    <?php echo __('No results found'); ?>

                 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginaldfa284edfb9b1400ff1a3e7e4e8c6e10)): ?>
<?php $attributes = $__attributesOriginaldfa284edfb9b1400ff1a3e7e4e8c6e10; ?>
<?php unset($__attributesOriginaldfa284edfb9b1400ff1a3e7e4e8c6e10); ?>
<?php endif; ?>
<?php if (isset($__componentOriginaldfa284edfb9b1400ff1a3e7e4e8c6e10)): ?>
<?php $component = $__componentOriginaldfa284edfb9b1400ff1a3e7e4e8c6e10; ?>
<?php unset($__componentOriginaldfa284edfb9b1400ff1a3e7e4e8c6e10); ?>
<?php endif; ?>
            <?php endif; ?>

            <?php echo e($slot); ?>

        </ui-options>
    </div>
<?php endif; ?>
<?php /**PATH /home/patryk/web/gsc/vendor/livewire/flux-pro/stubs/resources/views/flux/select/options.blade.php ENDPATH**/ ?>