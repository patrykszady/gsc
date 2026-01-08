

<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['range' => false]));

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

foreach (array_filter((['range' => false]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
$classes = Flux::classes()
    ->add('flex flex-col justify-center w-full [:where(&)]:min-h-4 isolate select-none [&[disabled]]:opacity-50 touch-none')
    ;

$trackClasses = Flux::classes()
    ->add('shrink-0 relative [:where(&)]:h-1.5 bg-zinc-200 rounded-full dark:bg-white/10 select-none')
    ->add($attributes->pluck('track:class'))
    ;

$indicatorWrapperClasses = Flux::classes()
    ->add('relative w-full h-full rounded-full overflow-hidden select-none')
    ;

$indicatorClasses = Flux::classes()
    ->add('absolute inset-y-0 bg-accent')
    ;

$thumbClasses = Flux::classes()
    ->add('absolute top-1/2 [:where(&)]:size-4 rounded-full bg-white ring ring-black/15 shadow-[0px_1px_2px_0px_rgba(0,0,0,.05),0px_2px_4px_0px_rgba(0,0,0,.1)] select-none -translate-y-1/2 -translate-x-1/2 dark:ring-black/30 rtl:translate-x-1/2 has-focus-visible:outline-2 has-focus-visible:outline-[-webkit-focus-ring-color]')
    ->add($attributes->pluck('thumb:class'))
    ;
?>

<?php if (isset($component)) { $__componentOriginal33e2911d6f1e72999cb4ebd3c5d00431 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal33e2911d6f1e72999cb4ebd3c5d00431 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::with-field','data' => ['attributes' => $attributes]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::with-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['attributes' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($attributes)]); ?>
    <ui-slider 
        <?php echo e($attributes->class($classes)); ?>

        <?php if($range): ?> range <?php endif; ?>
        data-flux-control
        data-flux-slider
        tabindex="-1"
        data-flux-aria-range-start="<?php echo e(__('start range')); ?>"
        data-flux-aria-range-end="<?php echo e(__('end range')); ?>"
    >
        <div class="h-full flex flex-col justify-center" data-flux-slider-track>
            <div data-flux-slider-track class="<?php echo e($trackClasses); ?>">
                <div class="<?php echo e($indicatorWrapperClasses); ?>">
                    <div data-flux-slider-indicator class="<?php echo e($indicatorClasses); ?>" wire:ignore></div>
                </div>
                
                <div data-flux-slider-thumb class="<?php echo e($thumbClasses); ?>" wire:ignore>
                    <input type="range" class="sr-only" <?php echo e($attributes->only(['min', 'max', 'step'])); ?> />
                </div>

                <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($range): ?>
                    <div data-flux-slider-thumb class="<?php echo e($thumbClasses); ?>" wire:ignore>
                        <input type="range" class="sr-only" <?php echo e($attributes->only(['min', 'max', 'step'])); ?> />
                    </div>
                <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
            </div>
            
            
            <?php if ($slot->isNotEmpty()): ?>
                <div class="relative grid *:col-start-1 *:row-start-1 select-none cursor-default">
                    <?php echo e($slot); ?>

                </div>
            <?php endif; ?>
        </div>
    </ui-slider>
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
<?php /**PATH /home/patryk/web/gsc/vendor/livewire/flux-pro/stubs/resources/views/flux/slider/index.blade.php ENDPATH**/ ?>