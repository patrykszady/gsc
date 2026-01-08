

<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'icon' => 'cloud-arrow-up',
    'withProgress' => false,
    'inline' => false,
    'heading' => null,
    'text' => null,
    'size' => null,
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
    'icon' => 'cloud-arrow-up',
    'withProgress' => false,
    'inline' => false,
    'heading' => null,
    'text' => null,
    'size' => null,
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
    ->add('w-full')
    ->add($inline ? 'p-4 ps-4 sm:ps-5 pe-6 sm:pe-8' : 'py-5 px-6 sm:py-10 sm:px-16')
    ->add($inline ? 'flex items-center' : 'flex flex-col items-center justify-center')
    ->add('rounded-lg border-dashed border-zinc-200 dark:border-white/10')
    ->add($inline ? 'border-1' : 'border-2')
    ->add('bg-zinc-50 dark:bg-white/10 transition-colors')
    ->add('in-data-dragging:bg-zinc-100 in-data-dragging:border-zinc-300')
    ->add('dark:in-data-dragging:bg-white/15 dark:in-data-dragging:border-white/20')
    ->add('[[disabled]_&]:opacity-75 [[disabled]_&]:pointer-events-none')
    ;

$iconClasses = Flux::classes()
    ->add('text-zinc-400 dark:text-white/60 transition')
    ->add('[[disabled]:hover_&]:text-zinc-400 dark:[[disabled]:hover_&]:text-white/60')
    ->add('in-data-dragging:text-zinc-800 dark:in-data-dragging:text-white')
    ->add('[[data-flux-file-upload-trigger]:hover_&]:text-zinc-800 dark:[[data-flux-file-upload-trigger]:hover_&]:text-white')
    ->add($withProgress ? '' : 'in-data-loading:opacity-0')
    ;

$loadingClasses = Flux::classes()
    ->add('absolute inset-0 text-zinc-800 dark:text-white transition')
    ->add($withProgress ? 'opacity-0' : 'opacity-0 in-data-loading:opacity-100')
    ;
?>

<div <?php echo e($attributes->class($classes)); ?> data-flux-file-upload-dropzone>
    <div class="relative <?php echo e($inline ? 'me-4' : 'mb-4'); ?>">
        <?php if (isset($component)) { $__componentOriginalc7d5f44bf2a2d803ed0b55f72f1f82e2 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc7d5f44bf2a2d803ed0b55f72f1f82e2 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::icon.index','data' => ['name' => ''.e($icon).'','variant' => 'solid','class' => ''.e($iconClasses).'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::icon'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => ''.e($icon).'','variant' => 'solid','class' => ''.e($iconClasses).'']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalc7d5f44bf2a2d803ed0b55f72f1f82e2)): ?>
<?php $attributes = $__attributesOriginalc7d5f44bf2a2d803ed0b55f72f1f82e2; ?>
<?php unset($__attributesOriginalc7d5f44bf2a2d803ed0b55f72f1f82e2); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalc7d5f44bf2a2d803ed0b55f72f1f82e2)): ?>
<?php $component = $__componentOriginalc7d5f44bf2a2d803ed0b55f72f1f82e2; ?>
<?php unset($__componentOriginalc7d5f44bf2a2d803ed0b55f72f1f82e2); ?>
<?php endif; ?>

        <?php if (isset($component)) { $__componentOriginalc7d5f44bf2a2d803ed0b55f72f1f82e2 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc7d5f44bf2a2d803ed0b55f72f1f82e2 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::icon.index','data' => ['name' => 'loading','variant' => 'solid','class' => ''.e($loadingClasses).'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::icon'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => 'loading','variant' => 'solid','class' => ''.e($loadingClasses).'']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalc7d5f44bf2a2d803ed0b55f72f1f82e2)): ?>
<?php $attributes = $__attributesOriginalc7d5f44bf2a2d803ed0b55f72f1f82e2; ?>
<?php unset($__attributesOriginalc7d5f44bf2a2d803ed0b55f72f1f82e2); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalc7d5f44bf2a2d803ed0b55f72f1f82e2)): ?>
<?php $component = $__componentOriginalc7d5f44bf2a2d803ed0b55f72f1f82e2; ?>
<?php unset($__componentOriginalc7d5f44bf2a2d803ed0b55f72f1f82e2); ?>
<?php endif; ?>
    </div>

    <div class="flex flex-col <?php echo e($inline ? 'gap-1' : 'items-center gap-2'); ?>">
        <?php if ($heading) : ?>
            <div class="text-sm font-medium text-zinc-800 dark:text-white cursor-default [[disabled]_&]:opacity-75">
                <?php echo e($heading); ?>

            </div>
        <?php endif; ?>

        <?php if ($text) : ?>
            <div class="relative text-zinc-500 dark:text-white/60 cursor-default <?php echo e($inline ? 'text-xs' : 'text-sm'); ?>">
                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($withProgress): ?>
                    <div class="not-in-data-loading:opacity-0 absolute inset-x-0 top-0 flex gap-3 items-center">
                        <div class="flex-1 h-1 rounded-full bg-zinc-200 dark:bg-white/10">
                            <div class="h-full rounded-full bg-zinc-500 dark:bg-white" style="width: var(--flux-file-upload-progress)"></div>
                        </div>

                        <div class="text-zinc-500 dark:text-white/70 tabular-nums font-medium after:content-[var(--flux-file-upload-progress-as-string)]"></div>
                    </div>

                    <span class="in-data-loading:opacity-0"><?php echo e($text); ?></span>
                <?php else: ?>
                    <?php echo e($text); ?>

                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php /**PATH /home/patryk/web/gsc/vendor/livewire/flux-pro/stubs/resources/views/flux/file-upload/dropzone/index.blade.php ENDPATH**/ ?>