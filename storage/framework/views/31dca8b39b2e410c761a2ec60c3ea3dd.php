

<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'kbd' => '⌘B',
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
    'kbd' => '⌘B',
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php if (isset($component)) { $__componentOriginalf5109f209df079b3a83484e1e6310749 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalf5109f209df079b3a83484e1e6310749 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::tooltip.index','data' => ['content' => ''.e(__('Bold')).'','kbd' => $kbd,'class' => 'contents']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::tooltip'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['content' => ''.e(__('Bold')).'','kbd' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($kbd),'class' => 'contents']); ?>
    <?php if (isset($component)) { $__componentOriginalc48e24ba6d9aef61755be556d7231136 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginalc48e24ba6d9aef61755be556d7231136 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::editor.button','data' => ['dataEditor' => 'bold']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::editor.button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['data-editor' => 'bold']); ?>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-5" aria-hidden="true"> <path fill-rule="evenodd" d="M4 3a1 1 0 0 1 1-1h6a4.5 4.5 0 0 1 3.274 7.587A4.75 4.75 0 0 1 11.25 18H5a1 1 0 0 1-1-1V3Zm2.5 5.5v-4H11a2 2 0 1 1 0 4H6.5Zm0 2.5v4.5h4.75a2.25 2.25 0 0 0 0-4.5H6.5Z" clip-rule="evenodd" /> </svg>
     <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalc48e24ba6d9aef61755be556d7231136)): ?>
<?php $attributes = $__attributesOriginalc48e24ba6d9aef61755be556d7231136; ?>
<?php unset($__attributesOriginalc48e24ba6d9aef61755be556d7231136); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalc48e24ba6d9aef61755be556d7231136)): ?>
<?php $component = $__componentOriginalc48e24ba6d9aef61755be556d7231136; ?>
<?php unset($__componentOriginalc48e24ba6d9aef61755be556d7231136); ?>
<?php endif; ?>
 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginalf5109f209df079b3a83484e1e6310749)): ?>
<?php $attributes = $__attributesOriginalf5109f209df079b3a83484e1e6310749; ?>
<?php unset($__attributesOriginalf5109f209df079b3a83484e1e6310749); ?>
<?php endif; ?>
<?php if (isset($__componentOriginalf5109f209df079b3a83484e1e6310749)): ?>
<?php $component = $__componentOriginalf5109f209df079b3a83484e1e6310749; ?>
<?php unset($__componentOriginalf5109f209df079b3a83484e1e6310749); ?>
<?php endif; ?>
<?php /**PATH /home/patryk/web/gsc/vendor/livewire/flux-pro/stubs/resources/views/flux/editor/bold.blade.php ENDPATH**/ ?>