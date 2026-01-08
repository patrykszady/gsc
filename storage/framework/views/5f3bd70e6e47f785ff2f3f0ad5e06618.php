<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'placeholder' => null,
    'searchable' => null,
    'clearable' => null,
    'multiple' => null,
    'invalid' => null,
    'empty' => null,
    'input' => null,
    'size' => null,
    'name' => null,
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
    'searchable' => null,
    'clearable' => null,
    'multiple' => null,
    'invalid' => null,
    'empty' => null,
    'input' => null,
    'size' => null,
    'name' => null,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
// We only want to show the name attribute on the checkbox if it has been set
// manually, but not if it has been set from the wire:model attribute...
$showName = isset($name);

if (! isset($name)) {
    $name = $attributes->whereStartsWith('wire:model')->first();
}

if ($searchable) {
    throw new \Exception('Comboboxes do not support the searchable prop.');
}

if ($multiple) {
    throw new \Exception('Comboboxes do not support the multiple prop.');
}

$invalid ??= ($name && $errors->has($name));

$class = Flux::classes()
    ->add('w-full');
?>

<ui-select autocomplete="strict" clear="esc" <?php echo e($attributes->class($class)->merge(['filter' => true])); ?> <?php if($showName): ?> name="<?php echo e($name); ?>" <?php endif; ?> data-flux-control data-flux-select>
    <?php if ($input): ?> <?php echo e($input); ?> <?php else: ?>
        <?php if (isset($component)) { $__componentOriginalb706732dd7405484b9a4fff11c3c206e = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalb706732dd7405484b9a4fff11c3c206e = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::select.input','data' => ['placeholder' => $placeholder,'invalid' => $invalid,'size' => $size,'clearable' => $clearable]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::select.input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['placeholder' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($placeholder),'invalid' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($invalid),'size' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($size),'clearable' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($clearable)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalb706732dd7405484b9a4fff11c3c206e)): ?>
<?php $attributes = $__attributesOriginalb706732dd7405484b9a4fff11c3c206e; ?>
<?php unset($__attributesOriginalb706732dd7405484b9a4fff11c3c206e); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalb706732dd7405484b9a4fff11c3c206e)): ?>
<?php $component = $__componentOriginalb706732dd7405484b9a4fff11c3c206e; ?>
<?php unset($__componentOriginalb706732dd7405484b9a4fff11c3c206e); ?>
<?php endif; ?>
    <?php endif; ?>

    <?php if (isset($component)) { $__componentOriginal7813a4341689867c6f488cb21095c2df = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal7813a4341689867c6f488cb21095c2df = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::select.options','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::select.options'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
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

     <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal7813a4341689867c6f488cb21095c2df)): ?>
<?php $attributes = $__attributesOriginal7813a4341689867c6f488cb21095c2df; ?>
<?php unset($__attributesOriginal7813a4341689867c6f488cb21095c2df); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal7813a4341689867c6f488cb21095c2df)): ?>
<?php $component = $__componentOriginal7813a4341689867c6f488cb21095c2df; ?>
<?php unset($__componentOriginal7813a4341689867c6f488cb21095c2df); ?>
<?php endif; ?>
</ui-select>
<?php /**PATH /home/patryk/web/gsc/vendor/livewire/flux-pro/stubs/resources/views/flux/select/variants/combobox.blade.php ENDPATH**/ ?>