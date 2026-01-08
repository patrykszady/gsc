
<?php if (isset($component)) { $__componentOriginal9a4024e92dff253fb598605aa475378c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal9a4024e92dff253fb598605aa475378c = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::select.option.empty','data' => ['attributes' => $attributes]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('select.option.empty'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['attributes' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($attributes)]); ?>
    <?php echo e($slot); ?>

 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal9a4024e92dff253fb598605aa475378c)): ?>
<?php $attributes = $__attributesOriginal9a4024e92dff253fb598605aa475378c; ?>
<?php unset($__attributesOriginal9a4024e92dff253fb598605aa475378c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal9a4024e92dff253fb598605aa475378c)): ?>
<?php $component = $__componentOriginal9a4024e92dff253fb598605aa475378c; ?>
<?php unset($__componentOriginal9a4024e92dff253fb598605aa475378c); ?>
<?php endif; ?><?php /**PATH /home/patryk/web/gsc/vendor/livewire/flux-pro/stubs/resources/views/flux/select/empty.blade.php ENDPATH**/ ?>