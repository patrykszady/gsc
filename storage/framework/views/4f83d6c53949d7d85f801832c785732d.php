<?php extract((new \Illuminate\Support\Collection($attributes->getAttributes()))->mapWithKeys(function ($value, $key) { return [Illuminate\Support\Str::camel(str_replace([':', '.'], ' ', $key)) => $value]; })->all(), EXTR_SKIP); ?>
<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['class']));

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

foreach (array_filter((['class']), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>
<?php if (isset($component)) { $__componentOriginal38126c41455cb7dfc6e92cb2038ce3a8 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal38126c41455cb7dfc6e92cb2038ce3a8 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.icons.social.facebook','data' => ['class' => $class]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('icons.social.facebook'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['class' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($class)]); ?>

<?php echo e($slot ?? ""); ?>

 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal38126c41455cb7dfc6e92cb2038ce3a8)): ?>
<?php $attributes = $__attributesOriginal38126c41455cb7dfc6e92cb2038ce3a8; ?>
<?php unset($__attributesOriginal38126c41455cb7dfc6e92cb2038ce3a8); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal38126c41455cb7dfc6e92cb2038ce3a8)): ?>
<?php $component = $__componentOriginal38126c41455cb7dfc6e92cb2038ce3a8; ?>
<?php unset($__componentOriginal38126c41455cb7dfc6e92cb2038ce3a8); ?>
<?php endif; ?><?php /**PATH /home/patryk/web/gsc/storage/framework/views/0ad5093c0b3529e36551f122273c604d.blade.php ENDPATH**/ ?>