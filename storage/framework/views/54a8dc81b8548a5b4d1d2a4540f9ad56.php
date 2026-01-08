<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'name' => $attributes->whereStartsWith('wire:model')->first(),
    'actionsTrailing' => null,
    'actionsLeading' => null,
    'variant' => null,
    'invalid' => null,
    'footer' => null,
    'header' => null,
    'input' => null,
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
    'name' => $attributes->whereStartsWith('wire:model')->first(),
    'actionsTrailing' => null,
    'actionsLeading' => null,
    'variant' => null,
    'invalid' => null,
    'footer' => null,
    'header' => null,
    'input' => null,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
$invalid ??= ($name && $errors->has($name));

$classes = Flux::classes()
    ->add('w-full p-2')
    ->add('grid grid-cols-[auto_1fr_1fr_auto]')
    ->add('shadow-xs [&:has([disabled])]:shadow-none border')
    ->add('bg-white dark:bg-white/10 dark:[&:has([disabled])]:bg-white/[7%]')
    ->add(match ($variant) {
        'input' => 'rounded-lg',
        default => 'rounded-2xl [&_[data-flux-button]]:rounded-lg',
    })
    ->add($invalid ? 'border-red-500' : 'border-zinc-200 border-b-zinc-300/80 dark:border-white/10')
    ;

$textareaClasses = Flux::classes()
    ->add('block w-full resize-none px-2 py-1.5')
    ->add('outline-none!')
    ->add('text-base sm:text-sm text-zinc-700 [[disabled]_&]:text-zinc-500 placeholder-zinc-400 [[disabled]_&]:placeholder-zinc-400/70 dark:text-zinc-300 dark:[[disabled]_&]:text-zinc-400 dark:placeholder-zinc-400 dark:[[disabled]_&]:placeholder-zinc-500')
    ;

// Support adding the .self modifier to the wire:model directive...
if (($wireModel = $attributes->wire('model')) && $wireModel->directive && ! $wireModel->hasModifier('self')) {
    unset($attributes[$wireModel->directive]);

    $wireModel->directive .= '.self';

    $attributes = $attributes->merge([$wireModel->directive => $wireModel->value]);
}
?>

<?php if (isset($component)) { $__componentOriginal33e2911d6f1e72999cb4ebd3c5d00431 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal33e2911d6f1e72999cb4ebd3c5d00431 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::with-field','data' => ['attributes' => $attributes,'name' => $name]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::with-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['attributes' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($attributes),'name' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($name)]); ?>
    <ui-composer <?php echo e($attributes->class($classes)); ?> data-flux-composer>
        <?php if ($header): ?>
            <div <?php echo e($header->attributes->class('col-span-3 flex items-center gap-1 mb-2')); ?>>
                <?php echo e($header); ?>

            </div>
        <?php endif; ?>

        <div class="col-span-4 [[inline]_&]:col-span-2 [[inline]_&]:col-start-2">
            <?php if ($input): ?>
                <?php echo e($input); ?>

            <?php else: ?>
                <textarea class="<?php echo e($textareaClasses); ?>"></textarea>
            <?php endif; ?>
        </div>

        <?php if ($actionsLeading): ?>
            <div <?php echo e($actionsLeading->attributes->class('col-span-2 [[inline]_&]:col-span-1 [[inline]_&]:col-start-1 [[inline]_&]:row-start-1 flex items-start gap-1')); ?>>
                <?php echo e($actionsLeading); ?>

            </div>
        <?php endif; ?>

        <?php if ($actionsTrailing): ?>
            <div <?php echo e($actionsTrailing->attributes->class('col-span-2 [[inline]_&]:col-span-1 flex items-start justify-end gap-1')); ?>>
                <?php echo e($actionsTrailing); ?>

            </div>
        <?php endif; ?>

        <?php if ($footer): ?>
            <div <?php echo e($footer->attributes->class('col-span-4 flex items-center gap-1')); ?>>
                <?php echo e($footer); ?>

            </div>
        <?php endif; ?>
    </ui-composer>
 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal33e2911d6f1e72999cb4ebd3c5d00431)): ?>
<?php $attributes = $__attributesOriginal33e2911d6f1e72999cb4ebd3c5d00431; ?>
<?php unset($__attributesOriginal33e2911d6f1e72999cb4ebd3c5d00431); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal33e2911d6f1e72999cb4ebd3c5d00431)): ?>
<?php $component = $__componentOriginal33e2911d6f1e72999cb4ebd3c5d00431; ?>
<?php unset($__componentOriginal33e2911d6f1e72999cb4ebd3c5d00431); ?>
<?php endif; ?>
<?php /**PATH /home/patryk/web/gsc/vendor/livewire/flux-pro/stubs/resources/views/flux/composer/index.blade.php ENDPATH**/ ?>