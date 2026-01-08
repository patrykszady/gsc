<?php foreach (([ 'variant' ]) as $__key => $__value) {
    $__consumeVariable = is_string($__key) ? $__key : $__value;
    $$__consumeVariable = is_string($__key) ? $__env->getConsumableComponentData($__key, $__value) : $__env->getConsumableComponentData($__value);
} ?>

<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'size' => null,
    'variant' => null,
    'scrollable' => false,
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
    'size' => null,
    'variant' => null,
    'scrollable' => false,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
if ($variant === 'pills') {
    $classes = Flux::classes()
        ->add('flex gap-4 h-8')
        ;
} elseif ($variant === 'segmented') {
    $classes = Flux::classes()
        ->add('inline-flex p-1')
        ->add($scrollable ? '' : 'rounded-lg bg-zinc-800/5 dark:bg-white/10')
        ->add($size === 'sm' ? 'h-[calc(2rem+2px)] py-[3px] px-[3px]' : 'h-10 p-1')
        ;
} else {
    $classes = Flux::classes()
        ->add('flex gap-4 h-10 border-b')
        ->add($scrollable ? 'border-transparent' : 'border-zinc-800/10 dark:border-white/20')
        ;
}

$scrollableFade = $attributes->pluck('scrollable:fade', false);
$scrollableScrollbar = $attributes->pluck('scrollable:scrollbar', null);

$wrapperClasses = Flux::classes()
    ->add('relative')
    ->add($variant === 'segmented' ? 'rounded-lg bg-zinc-800/5 dark:bg-white/10' : '');

$borderClasses = Flux::classes()
    ->add('absolute inset-x-0 bottom-0 h-px')
    ->add($variant === null ? 'bg-zinc-800/10 dark:bg-white/20' : '')
    ;

$scrollAreaClasses = Flux::classes()
    ->add('relative flex overflow-auto')
    ->add($scrollableFade ? [
        '[--flux-scroll-percentage:0%]', // This is controlled by JavaScript...
        'mask-r-from-[max(calc(100%-6rem),var(--flux-scroll-percentage))]',
        'rtl:mask-r-from-100% rtl:mask-l-from-[max(calc(100%-6rem),var(--flux-scroll-percentage))]',
    ] : '')
    ->add($scrollableScrollbar === 'hide' ? 'flux-no-scrollbar' : '')
    ->add($variant == 'segmented' ? 'rounded-lg' : '')
    ;
?>

<?php if ($scrollable): ?>
    <div class="<?php echo e($wrapperClasses); ?>">
        <div class="<?php echo e($borderClasses); ?>"></div>

        <ui-tabs-scroll-area class="<?php echo e($scrollAreaClasses); ?>">
            <div class="min-w-full flex-none">
                <ui-tabs <?php echo e($attributes->class($classes)); ?> data-flux-tabs>
                    <?php echo e($slot); ?>

                </ui-tabs>
            </div>
        </ui-tabs-scroll-area>
    </div>
<?php else: ?>
    <ui-tabs <?php echo e($attributes->class($classes)); ?> data-flux-tabs>
        <?php echo e($slot); ?>

    </ui-tabs>
<?php endif; ?>
<?php /**PATH /home/patryk/web/gsc/vendor/livewire/flux-pro/stubs/resources/views/flux/tabs.blade.php ENDPATH**/ ?>