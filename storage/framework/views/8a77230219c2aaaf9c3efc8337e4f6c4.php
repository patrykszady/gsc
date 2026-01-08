<?php foreach ((['axis' => 'x']) as $__key => $__value) {
    $__consumeVariable = is_string($__key) ? $__key : $__value;
    $$__consumeVariable = is_string($__key) ? $__env->getConsumableComponentData($__key, $__value) : $__env->getConsumableComponentData($__value);
} ?>

<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($axis === 'x'): ?>
    <template name="grid-line">
        <line <?php echo e($attributes->merge([
            'type' => 'horizontal',
            'class' => 'text-zinc-200/50 dark:text-white/15',
            'stroke' => 'currentColor',
            'stroke-width' => '1',
        ])); ?>></line>
    </template>
<?php else: ?>
    <template name="grid-line">
        <line <?php echo e($attributes->merge([
            'type' => 'vertical',
            'class' => 'text-zinc-200/50 dark:text-white/15',
            'stroke' => 'currentColor',
            'stroke-width' => '1',
        ])); ?>></line>
    </template>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php /**PATH /home/patryk/web/gsc/vendor/livewire/flux-pro/stubs/resources/views/flux/chart/axis/grid.blade.php ENDPATH**/ ?>