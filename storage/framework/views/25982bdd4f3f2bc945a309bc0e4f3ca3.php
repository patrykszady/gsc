<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'selectedSuffix' => null,
    'placeholder' => null,
    'searchable' => null,
    'clearable' => null,
    'invalid' => null,
    'trigger' => null,
    'empty' => null,
    'clear' => null,
    'close' => null,
    'name' => null,
    'size' => null,
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
    'selectedSuffix' => null,
    'placeholder' => null,
    'searchable' => null,
    'clearable' => null,
    'invalid' => null,
    'trigger' => null,
    'empty' => null,
    'clear' => null,
    'close' => null,
    'name' => null,
    'size' => null,
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
// We only want to show the name attribute on the checkbox if it has been set
// manually, but not if it has been set from the wire:model attribute...
$showName = isset($name);

if (! isset($name)) {
    $name = $attributes->whereStartsWith('wire:model')->first();
}

if ($searchable) {
    throw new \Exception('Comboboxes do not support the searchable prop.');
}

$invalid ??= ($name && $errors->has($name));

$class = Flux::classes()
    ->add('w-full')
    // The below reverts styles added by Tailwind Forms plugin
    ->add('border-0 p-0 bg-transparent')
    ;
?>

<ui-pillbox
    clear="<?php echo e($clear ?? 'close esc select'); ?>"
    <?php if($close): ?> close="<?php echo e($close); ?>" <?php endif; ?>
    <?php echo e($attributes->class($class)->merge(['filter' => true])); ?>

    <?php if($showName): ?> name="<?php echo e($name); ?>" <?php endif; ?>
    data-flux-control
    data-flux-pillbox
>
    <?php if ($trigger): ?> <?php echo e($trigger); ?> <?php else: ?>
        <?php if (isset($component)) { $__componentOriginal3e279580d1390dd13eec6c9e61353af4 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal3e279580d1390dd13eec6c9e61353af4 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::pillbox.trigger','data' => ['class' => 'cursor-text','placeholder' => $placeholder,'invalid' => $invalid,'size' => $size,'clearable' => $clearable,'suffix' => $selectedSuffix]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::pillbox.trigger'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => 'cursor-text','placeholder' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($placeholder),'invalid' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($invalid),'size' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($size),'clearable' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($clearable),'suffix' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($selectedSuffix)]); ?>
            <?php if (isset($component)) { $__componentOriginal76c76801c2fb5dcb4a0eab3c079e4007 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal76c76801c2fb5dcb4a0eab3c079e4007 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::pillbox.selected','data' => ['size' => $size,'suffix' => $selectedSuffix]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::pillbox.selected'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['size' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($size),'suffix' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($selectedSuffix)]); ?>
                 <?php $__env->slot('input', null, []); ?> 
                    <?php if ($input): ?> <?php echo e($input); ?> <?php else: ?>
                        <?php if (isset($component)) { $__componentOriginale26128cf385504a64a967f81a6836d52 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginale26128cf385504a64a967f81a6836d52 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::pillbox.input','data' => ['placeholder' => $placeholder]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::pillbox.input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['placeholder' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($placeholder)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginale26128cf385504a64a967f81a6836d52)): ?>
<?php $attributes = $__attributesOriginale26128cf385504a64a967f81a6836d52; ?>
<?php unset($__attributesOriginale26128cf385504a64a967f81a6836d52); ?>
<?php endif; ?>
<?php if (isset($__componentOriginale26128cf385504a64a967f81a6836d52)): ?>
<?php $component = $__componentOriginale26128cf385504a64a967f81a6836d52; ?>
<?php unset($__componentOriginale26128cf385504a64a967f81a6836d52); ?>
<?php endif; ?>
                    <?php endif; ?>
                 <?php $__env->endSlot(); ?>
             <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal76c76801c2fb5dcb4a0eab3c079e4007)): ?>
<?php $attributes = $__attributesOriginal76c76801c2fb5dcb4a0eab3c079e4007; ?>
<?php unset($__attributesOriginal76c76801c2fb5dcb4a0eab3c079e4007); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal76c76801c2fb5dcb4a0eab3c079e4007)): ?>
<?php $component = $__componentOriginal76c76801c2fb5dcb4a0eab3c079e4007; ?>
<?php unset($__componentOriginal76c76801c2fb5dcb4a0eab3c079e4007); ?>
<?php endif; ?>
         <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal3e279580d1390dd13eec6c9e61353af4)): ?>
<?php $attributes = $__attributesOriginal3e279580d1390dd13eec6c9e61353af4; ?>
<?php unset($__attributesOriginal3e279580d1390dd13eec6c9e61353af4); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal3e279580d1390dd13eec6c9e61353af4)): ?>
<?php $component = $__componentOriginal3e279580d1390dd13eec6c9e61353af4; ?>
<?php unset($__componentOriginal3e279580d1390dd13eec6c9e61353af4); ?>
<?php endif; ?>
    <?php endif; ?>

    <?php if (isset($component)) { $__componentOriginalda84017f6c0b3b5c5e0808669b37f7b4 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalda84017f6c0b3b5c5e0808669b37f7b4 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::pillbox.options','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::pillbox.options'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?>
        <?php if ($empty): ?>
            <?php if (is_string($empty)): ?>
                <?php if (isset($component)) { $__componentOriginal2ead801b64690036a5a6ec36fa27042c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal2ead801b64690036a5a6ec36fa27042c = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::pillbox.option.empty','data' => []] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::pillbox.option.empty'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes([]); ?><?php echo __($empty); ?> <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal2ead801b64690036a5a6ec36fa27042c)): ?>
<?php $attributes = $__attributesOriginal2ead801b64690036a5a6ec36fa27042c; ?>
<?php unset($__attributesOriginal2ead801b64690036a5a6ec36fa27042c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal2ead801b64690036a5a6ec36fa27042c)): ?>
<?php $component = $__componentOriginal2ead801b64690036a5a6ec36fa27042c; ?>
<?php unset($__componentOriginal2ead801b64690036a5a6ec36fa27042c); ?>
<?php endif; ?>
            <?php else: ?>
                <?php echo e($empty); ?>

            <?php endif; ?>
        <?php else: ?>
            <?php if (isset($component)) { $__componentOriginal2ead801b64690036a5a6ec36fa27042c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal2ead801b64690036a5a6ec36fa27042c = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::pillbox.option.empty','data' => ['whenLoading' => ''.__('Loading...').'']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::pillbox.option.empty'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['when-loading' => ''.__('Loading...').'']); ?>
                <?php echo __('No results found'); ?>

             <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal2ead801b64690036a5a6ec36fa27042c)): ?>
<?php $attributes = $__attributesOriginal2ead801b64690036a5a6ec36fa27042c; ?>
<?php unset($__attributesOriginal2ead801b64690036a5a6ec36fa27042c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal2ead801b64690036a5a6ec36fa27042c)): ?>
<?php $component = $__componentOriginal2ead801b64690036a5a6ec36fa27042c; ?>
<?php unset($__componentOriginal2ead801b64690036a5a6ec36fa27042c); ?>
<?php endif; ?>
        <?php endif; ?>

        <?php echo e($slot); ?>

     <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalda84017f6c0b3b5c5e0808669b37f7b4)): ?>
<?php $attributes = $__attributesOriginalda84017f6c0b3b5c5e0808669b37f7b4; ?>
<?php unset($__attributesOriginalda84017f6c0b3b5c5e0808669b37f7b4); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalda84017f6c0b3b5c5e0808669b37f7b4)): ?>
<?php $component = $__componentOriginalda84017f6c0b3b5c5e0808669b37f7b4; ?>
<?php unset($__componentOriginalda84017f6c0b3b5c5e0808669b37f7b4); ?>
<?php endif; ?>
</ui-pillbox><?php /**PATH /home/patryk/web/gsc/vendor/livewire/flux-pro/stubs/resources/views/flux/pillbox/variants/combobox.blade.php ENDPATH**/ ?>