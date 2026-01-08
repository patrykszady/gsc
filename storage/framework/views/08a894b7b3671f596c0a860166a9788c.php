<?php foreach (([ 'placeholder', 'variant' ]) as $__key => $__value) {
    $__consumeVariable = is_string($__key) ? $__key : $__value;
    $$__consumeVariable = is_string($__key) ? $__env->getConsumableComponentData($__key, $__value) : $__env->getConsumableComponentData($__value);
} ?>

<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'placeholder' => null,
    'clearable' => null,
    'invalid' => false,
    'suffix' => null,
    'size' => null,
    'max' => null,
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
    'clearable' => null,
    'invalid' => false,
    'suffix' => null,
    'size' => null,
    'max' => null,
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
    ->add('group/listbox-button cursor-default')
    ->add('overflow-hidden') // Overflow hidden is here to prevent the button from growing when selected text is too long.
    ->add('flex items-center')
    ->add('shadow-xs')
    ->add('bg-white dark:bg-white/10 dark:disabled:bg-white/[7%]')
    // Make the placeholder match the text color of standard input placeholders...
    ->add('disabled:shadow-none')
    ->add(match($size) {
        default => 'min-h-10 text-base sm:text-sm rounded-lg ps-[calc(0.5rem-1px)] pe-3 py-[calc(0.5rem-1px)] block w-full',
        'sm' => 'min-h-6 text-sm rounded-md ps-[calc(0.25rem)] pe-2 py-[calc(0.25rem)] block w-full',
    })
    ->add($invalid
        ? 'border border-red-500'
        : 'border border-zinc-200 border-b-zinc-300/80 dark:border-white/10'
    )
    ->add('in-[data-target]:text-start')
    ->add($variant === 'combobox' ? 'has-focus-visible:outline-default' : '')
    ;
?>

<ui-pillbox-trigger <?php echo e($attributes->class($classes)); ?> <?php if($invalid): ?> data-invalid <?php endif; ?> data-flux-group-target data-flux-pillbox-trigger>
    <?php if ($slot->isNotEmpty()): ?>
        <?php echo e($slot); ?>

    <?php else: ?>
        <?php if (isset($component)) { $__componentOriginal76c76801c2fb5dcb4a0eab3c079e4007 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal76c76801c2fb5dcb4a0eab3c079e4007 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::pillbox.selected','data' => ['placeholder' => $placeholder,'max' => $max,'suffix' => $suffix,'size' => $size]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::pillbox.selected'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['placeholder' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($placeholder),'max' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($max),'suffix' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($suffix),'size' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($size)]); ?>
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
    <?php endif; ?>

    <?php if ($clearable): ?>
        <?php if (isset($component)) { $__componentOriginalc04b147acd0e65cc1a77f86fb0e81580 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::button.index','data' => ['as' => 'div','class' => 'self-start cursor-pointer -my-1 ms-2 -me-2 [[data-flux-pillbox-trigger]:has([data-flux-pillbox-placeholder])_&]:hidden [[data-flux-pillbox][disabled]:has([data-selected])_&]:hidden','variant' => 'subtle','size' => $size === 'sm' ? 'xs' : 'sm','square' => true,'tabindex' => '-1','ariaLabel' => 'Clear selected','xOn:click.prevent.stop' => 'let select = $el.closest(\'ui-pillbox\'); select.value = select.hasAttribute(\'multiple\') ? [] : null; select.dispatchEvent(new Event(\'change\', { bubbles: false })); select.dispatchEvent(new Event(\'input\', { bubbles: false }))']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['as' => 'div','class' => 'self-start cursor-pointer -my-1 ms-2 -me-2 [[data-flux-pillbox-trigger]:has([data-flux-pillbox-placeholder])_&]:hidden [[data-flux-pillbox][disabled]:has([data-selected])_&]:hidden','variant' => 'subtle','size' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($size === 'sm' ? 'xs' : 'sm'),'square' => true,'tabindex' => '-1','aria-label' => 'Clear selected','x-on:click.prevent.stop' => 'let select = $el.closest(\'ui-pillbox\'); select.value = select.hasAttribute(\'multiple\') ? [] : null; select.dispatchEvent(new Event(\'change\', { bubbles: false })); select.dispatchEvent(new Event(\'input\', { bubbles: false }))']); ?>
            <?php if (isset($component)) { $__componentOriginal155e76c41fe51242bc25d269fabf82f5 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal155e76c41fe51242bc25d269fabf82f5 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::icon.x-mark','data' => ['variant' => 'micro']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::icon.x-mark'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['variant' => 'micro']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal155e76c41fe51242bc25d269fabf82f5)): ?>
<?php $attributes = $__attributesOriginal155e76c41fe51242bc25d269fabf82f5; ?>
<?php unset($__attributesOriginal155e76c41fe51242bc25d269fabf82f5); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal155e76c41fe51242bc25d269fabf82f5)): ?>
<?php $component = $__componentOriginal155e76c41fe51242bc25d269fabf82f5; ?>
<?php unset($__componentOriginal155e76c41fe51242bc25d269fabf82f5); ?>
<?php endif; ?>
         <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580)): ?>
<?php $attributes = $__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580; ?>
<?php unset($__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalc04b147acd0e65cc1a77f86fb0e81580)): ?>
<?php $component = $__componentOriginalc04b147acd0e65cc1a77f86fb0e81580; ?>
<?php unset($__componentOriginalc04b147acd0e65cc1a77f86fb0e81580); ?>
<?php endif; ?>
    <?php endif; ?>

    <?php if($variant == 'combobox'): ?>
        <?php if (isset($component)) { $__componentOriginalc04b147acd0e65cc1a77f86fb0e81580 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::button.index','data' => ['size' => 'sm','square' => true,'variant' => 'subtle','tabindex' => '-1','class' => 'self-start -me-2 -my-1 [[disabled]_&]:pointer-events-none']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['size' => 'sm','square' => true,'variant' => 'subtle','tabindex' => '-1','class' => 'self-start -me-2 -my-1 [[disabled]_&]:pointer-events-none']); ?>
            <?php if (isset($component)) { $__componentOriginalcc1305822472ccf8aa9a0b8dc7a9cf8c = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalcc1305822472ccf8aa9a0b8dc7a9cf8c = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::icon.chevron-up-down','data' => ['variant' => 'mini','class' => 'text-zinc-400/75 [[data-flux-input]:hover_&]:text-zinc-800 [[disabled]_&]:text-zinc-200! dark:text-white/60 dark:[[data-flux-input]:hover_&]:text-white dark:[[disabled]_&]:text-white/40!']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::icon.chevron-up-down'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['variant' => 'mini','class' => 'text-zinc-400/75 [[data-flux-input]:hover_&]:text-zinc-800 [[disabled]_&]:text-zinc-200! dark:text-white/60 dark:[[data-flux-input]:hover_&]:text-white dark:[[disabled]_&]:text-white/40!']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalcc1305822472ccf8aa9a0b8dc7a9cf8c)): ?>
<?php $attributes = $__attributesOriginalcc1305822472ccf8aa9a0b8dc7a9cf8c; ?>
<?php unset($__attributesOriginalcc1305822472ccf8aa9a0b8dc7a9cf8c); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalcc1305822472ccf8aa9a0b8dc7a9cf8c)): ?>
<?php $component = $__componentOriginalcc1305822472ccf8aa9a0b8dc7a9cf8c; ?>
<?php unset($__componentOriginalcc1305822472ccf8aa9a0b8dc7a9cf8c); ?>
<?php endif; ?>
         <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580)): ?>
<?php $attributes = $__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580; ?>
<?php unset($__attributesOriginalc04b147acd0e65cc1a77f86fb0e81580); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalc04b147acd0e65cc1a77f86fb0e81580)): ?>
<?php $component = $__componentOriginalc04b147acd0e65cc1a77f86fb0e81580; ?>
<?php unset($__componentOriginalc04b147acd0e65cc1a77f86fb0e81580); ?>
<?php endif; ?>
    <?php else: ?>
        <?php if (isset($component)) { $__componentOriginal298ff21bbc41cebb188cbb18c6c11bc0 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal298ff21bbc41cebb188cbb18c6c11bc0 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::icon.chevron-down','data' => ['variant' => 'mini','class' => 'self-start '.e($size === 'sm' ? 'mt-0.25 mb-0.25' : 'mt-0.5').' ms-2 -me-1 pointer-events-none text-zinc-300 [[data-flux-pillbox-trigger]:hover_&]:text-zinc-800 [[disabled]_&]:text-zinc-200! dark:text-white/60 dark:[[data-flux-pillbox-trigger]:hover_&]:text-white dark:[[disabled]_&]:text-white/40!']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::icon.chevron-down'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['variant' => 'mini','class' => 'self-start '.e($size === 'sm' ? 'mt-0.25 mb-0.25' : 'mt-0.5').' ms-2 -me-1 pointer-events-none text-zinc-300 [[data-flux-pillbox-trigger]:hover_&]:text-zinc-800 [[disabled]_&]:text-zinc-200! dark:text-white/60 dark:[[data-flux-pillbox-trigger]:hover_&]:text-white dark:[[disabled]_&]:text-white/40!']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal298ff21bbc41cebb188cbb18c6c11bc0)): ?>
<?php $attributes = $__attributesOriginal298ff21bbc41cebb188cbb18c6c11bc0; ?>
<?php unset($__attributesOriginal298ff21bbc41cebb188cbb18c6c11bc0); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal298ff21bbc41cebb188cbb18c6c11bc0)): ?>
<?php $component = $__componentOriginal298ff21bbc41cebb188cbb18c6c11bc0; ?>
<?php unset($__componentOriginal298ff21bbc41cebb188cbb18c6c11bc0); ?>
<?php endif; ?>
    <?php endif; ?>
</ui-pillbox-trigger><?php /**PATH /home/patryk/web/gsc/vendor/livewire/flux-pro/stubs/resources/views/flux/pillbox/trigger.blade.php ENDPATH**/ ?>