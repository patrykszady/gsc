

<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'icon' => 'document',
    'invalid' => false,
    'actions' => null,
    'heading' => null,
    'inline' => false,
    'image' => null,
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
    'icon' => 'document',
    'invalid' => false,
    'actions' => null,
    'heading' => null,
    'inline' => false,
    'image' => null,
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
    ->add('cursor-default')
    ->add('overflow-hidden') // Overflow hidden is here to prevent the button from growing when selected text is too long.
    ->add('flex items-start')
    ->add('shadow-xs')
    ->add('bg-white dark:bg-white/10 dark:disabled:bg-white/[7%]')
    // Make the placeholder match the text color of standard input placeholders...
    ->add('disabled:shadow-none')
    ->add('min-h-10 text-base sm:text-sm rounded-lg block w-full')
    ->add($invalid
        ? 'border border-red-500'
        : 'border border-zinc-200 border-b-zinc-300/80 dark:border-white/10'
    )
    ;

$figureWrapperClasses = Flux::classes()
    ->add('p-[calc(0.75rem-1px)] flex items-baseline')
    ->add('[&:has([data-slot=image])]:p-[calc(0.5rem-1px)]')
    ;

$imageWrapperClasses = Flux::classes()
    ->add('relative mr-1 size-11 rounded-sm overflow-hidden')
    ->add([
        'after:absolute after:inset-0 after:inset-ring-[1px] after:inset-ring-black/7 dark:after:inset-ring-white/10',
        'after:rounded-sm',
    ])
    ;

if ($size) {
    if ($size < 1024) {
        $text = round($size) . ' B';
    } elseif ($size < 1024 * 1024) {
        $text = round($size / 1024) . ' KB';
    } elseif ($size < 1024 * 1024 * 1024) {
        $text = round($size / 1024 / 1024) . ' MB';
    } else {
        $text = round($size / 1024 / 1024 / 1024) . ' GB';
    }
}

$iconVariant = $text ? 'solid' : 'micro';
?>

<div <?php echo e($attributes->class($classes)); ?> data-flux-file-item>
    <div class="<?php echo e($figureWrapperClasses); ?>">
        <?php if (isset($component)) { $__componentOriginalc7d5f44bf2a2d803ed0b55f72f1f82e2 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc7d5f44bf2a2d803ed0b55f72f1f82e2 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::icon.index','data' => ['name' => ''.e($icon).'','variant' => ''.e($iconVariant).'','class' => 'text-zinc-400 [&:has(+[data-slot=image])]:hidden']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::icon'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['name' => ''.e($icon).'','variant' => ''.e($iconVariant).'','class' => 'text-zinc-400 [&:has(+[data-slot=image])]:hidden']); ?>
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

        <?php if ($image): ?>
            <div class="<?php echo e($imageWrapperClasses); ?>" data-slot="image">
                <img class="h-full w-full object-cover" src="<?php echo e($image); ?>" alt="">
            </div>
        <?php endif; ?>
    </div>

    <div class="flex-1 overflow-hidden py-[calc(0.75rem-3px)] me-3 flex flex-col justify-center gap-1" data-slot="content">
        <?php if ($heading): ?>
            <div class="text-sm font-medium text-zinc-500 dark:text-white/80 whitespace-nowrap overflow-hidden text-ellipsis"><?php echo e($heading); ?></div>
        <?php endif; ?>

        <?php if ($text): ?>
            <div class="text-xs text-zinc-500"><?php echo e($text); ?></div>
        <?php endif; ?>
    </div>

    <?php if ($actions): ?>
        <div <?php echo e($actions->attributes->class([
            'p-[calc(0.25rem-1px)]',
            'flex-shrink-0 self-start flex h-full items-center gap-2'
        ])); ?> data-slot="actions">
            <?php echo e($actions); ?>

        </div>
    <?php endif; ?>
</div><?php /**PATH /home/patryk/web/gsc/vendor/livewire/flux-pro/stubs/resources/views/flux/file-item/index.blade.php ENDPATH**/ ?>