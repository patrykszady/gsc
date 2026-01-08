
<?php if (isset($component)) { $__componentOriginal2ead801b64690036a5a6ec36fa27042c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal2ead801b64690036a5a6ec36fa27042c = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::pillbox.option.empty','data' => ['attributes' => $attributes]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::pillbox.option.empty'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['attributes' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($attributes)]); ?>
    <?php echo e($slot); ?>

 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal2ead801b64690036a5a6ec36fa27042c)): ?>
<?php $attributes = $__attributesOriginal2ead801b64690036a5a6ec36fa27042c; ?>
<?php unset($__attributesOriginal2ead801b64690036a5a6ec36fa27042c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal2ead801b64690036a5a6ec36fa27042c)): ?>
<?php $component = $__componentOriginal2ead801b64690036a5a6ec36fa27042c; ?>
<?php unset($__componentOriginal2ead801b64690036a5a6ec36fa27042c); ?>
<?php endif; ?><?php /**PATH /home/patryk/web/gsc/vendor/livewire/flux-pro/stubs/resources/views/flux/pillbox/empty.blade.php ENDPATH**/ ?>