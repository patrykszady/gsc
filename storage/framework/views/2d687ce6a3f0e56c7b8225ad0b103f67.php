<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'placeholder' => null,
    'unavailable' => null,
    'clearable' => null,
    'dropdown' => null,
    'type' => 'button',
    'invalid' => null,
    'value' => null,
    'name' => null,
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
    'placeholder' => null,
    'unavailable' => null,
    'clearable' => null,
    'dropdown' => null,
    'type' => 'button',
    'invalid' => null,
    'value' => null,
    'name' => null,
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
// We only want to show the name attribute if it has been set manually
// but not if it has been set from the `wire:model` attribute...
$showName = isset($name);
if (! isset($name)) {
    $name = $attributes->whereStartsWith('wire:model')->first();
}

// Support adding the .self modifier to the wire:model directive...
if (($wireModel = $attributes->wire('model')) && $wireModel->directive && ! $wireModel->hasModifier('self')) {
    unset($attributes[$wireModel->directive]);

    $wireModel->directive .= '.self';

    $attributes = $attributes->merge([$wireModel->directive => $wireModel->value]);
}

$placeholder ??= __('Select a time');

// Mark it invalid if the property or any of it's nested attributes have errors...
$invalid ??= ($name && ($errors->has($name) || $errors->has($name . '.*')));

$classes = Flux::classes()
    ->add('block min-w-0')
    // The below reverts styles added by Tailwind Forms plugin...
    ->add('border-0 p-0 bg-transparent')
    ;

$optionsClasses = Flux::classes()
    ->add('[:where(&)]:min-w-48 [:where(&)]:max-h-[20rem] p-[.3125rem] scroll-py-[.3125rem]')
    ->add('rounded-lg shadow-xs')
    ->add('border border-zinc-200 dark:border-zinc-600')
    ->add('bg-white dark:bg-zinc-700')
    ;

// Add support for `$value` being an array, if for example it's coming from
// the `old()` helper or if a user prefers to pass data in as an array...
if (is_array($value)) {
    $value = collect($value)->join(',');
}

if (isset($unavailable)) {
    $unavailable = collect($unavailable)->join(',');
}

if (isset($dropdown) && $dropdown === false) {
    $dropdown = 'false';
}
?>

<?php if (isset($component)) { $__componentOriginal33e2911d6f1e72999cb4ebd3c5d00431 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal33e2911d6f1e72999cb4ebd3c5d00431 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::with-field','data' => ['attributes' => $attributes,'name' => $name]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::with-field'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['attributes' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($attributes),'name' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($name)]); ?>
    <ui-time-picker
        <?php echo e($attributes->class($classes)); ?>

        data-flux-control
        data-flux-time-picker
        <?php if(isset($dropdown)): ?> dropdown="<?php echo e($dropdown); ?>" <?php endif; ?>
        <?php if($unavailable): ?> unavailable="<?php echo e($unavailable); ?>" <?php endif; ?>
        <?php if($showName): ?> name="<?php echo e($name); ?>" <?php endif; ?>
        <?php if(isset($value)): ?> value="<?php echo e($value); ?>" <?php endif; ?>
        <?php echo e($attributes); ?>

    >
        <ui-time-picker-trigger>
        <?php if ($type === 'input'): ?>
            <?php if (isset($component)) { $__componentOriginal9f5d410cbcaf063f7000f21fe5f47b73 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal9f5d410cbcaf063f7000f21fe5f47b73 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::time-picker.input','data' => ['invalid' => $invalid,'size' => $size,'clearable' => $clearable,'dropdown' => $dropdown]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::time-picker.input'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['invalid' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($invalid),'size' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($size),'clearable' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($clearable),'dropdown' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($dropdown)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal9f5d410cbcaf063f7000f21fe5f47b73)): ?>
<?php $attributes = $__attributesOriginal9f5d410cbcaf063f7000f21fe5f47b73; ?>
<?php unset($__attributesOriginal9f5d410cbcaf063f7000f21fe5f47b73); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal9f5d410cbcaf063f7000f21fe5f47b73)): ?>
<?php $component = $__componentOriginal9f5d410cbcaf063f7000f21fe5f47b73; ?>
<?php unset($__componentOriginal9f5d410cbcaf063f7000f21fe5f47b73); ?>
<?php endif; ?>
        <?php else: ?>
            <?php if (isset($component)) { $__componentOriginal9a01a70d7900091dd2f18c85f4be77a2 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal9a01a70d7900091dd2f18c85f4be77a2 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::time-picker.button','data' => ['placeholder' => $placeholder,'invalid' => $invalid,'size' => $size,'clearable' => $clearable]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::time-picker.button'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['placeholder' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($placeholder),'invalid' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($invalid),'size' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($size),'clearable' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute($clearable)]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal9a01a70d7900091dd2f18c85f4be77a2)): ?>
<?php $attributes = $__attributesOriginal9a01a70d7900091dd2f18c85f4be77a2; ?>
<?php unset($__attributesOriginal9a01a70d7900091dd2f18c85f4be77a2); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal9a01a70d7900091dd2f18c85f4be77a2)): ?>
<?php $component = $__componentOriginal9a01a70d7900091dd2f18c85f4be77a2; ?>
<?php unset($__componentOriginal9a01a70d7900091dd2f18c85f4be77a2); ?>
<?php endif; ?>
        <?php endif; ?>
        </ui-time-picker-trigger>

        <ui-time-picker-options popover="manual" tabindex="-1" wire:ignore class="<?php echo e($optionsClasses); ?>">
            <template name="option">
                <button type="button" tabindex="-1" class="w-full px-1 py-1.5 rounded-lg flex items-center justify-start gap-2 text-sm text-zinc-800 dark:text-white data-active:bg-zinc-100 dark:data-active:bg-zinc-600 disabled:text-zinc-400 disabled:pointer-events-none disabled:cursor-default [[readonly]_&]:pointer-events-none [[readonly]_&]:cursor-default [[readonly]_&]:bg-transparent">
                    <div class="w-6 shrink-0" data-checked>
                        <?php if (isset($component)) { $__componentOriginal9c2dfd6cb98f4df18e26d1694500af11 = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal9c2dfd6cb98f4df18e26d1694500af11 = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'e60dd9d2c3a62d619c9acb38f20d5aa5::icon.check','data' => ['variant' => 'mini','class' => 'hidden [ui-time-picker-options>[data-selected]_&]:block']] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? $attributes->all() : [])); ?>
<?php $component->withName('flux::icon.check'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag): ?>
<?php $attributes = $attributes->except(\Illuminate\View\AnonymousComponent::ignoredParameterNames()); ?>
<?php endif; ?>
<?php $component->withAttributes(['variant' => 'mini','class' => 'hidden [ui-time-picker-options>[data-selected]_&]:block']); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal9c2dfd6cb98f4df18e26d1694500af11)): ?>
<?php $attributes = $__attributesOriginal9c2dfd6cb98f4df18e26d1694500af11; ?>
<?php unset($__attributesOriginal9c2dfd6cb98f4df18e26d1694500af11); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal9c2dfd6cb98f4df18e26d1694500af11)): ?>
<?php $component = $__componentOriginal9c2dfd6cb98f4df18e26d1694500af11; ?>
<?php unset($__componentOriginal9c2dfd6cb98f4df18e26d1694500af11); ?>
<?php endif; ?>
                    </div>
                    
                    <div dir="ltr" class="tabular-nums">
                        <slot></slot>
                    </div>
                </button>
            </template>
        </ui-time-picker-options>
    </ui-time-picker>
 <?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal33e2911d6f1e72999cb4ebd3c5d00431)): ?>
<?php $attributes = $__attributesOriginal33e2911d6f1e72999cb4ebd3c5d00431; ?>
<?php unset($__attributesOriginal33e2911d6f1e72999cb4ebd3c5d00431); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal33e2911d6f1e72999cb4ebd3c5d00431)): ?>
<?php $component = $__componentOriginal33e2911d6f1e72999cb4ebd3c5d00431; ?>
<?php unset($__componentOriginal33e2911d6f1e72999cb4ebd3c5d00431); ?>
<?php endif; ?><?php /**PATH /home/patryk/web/gsc/vendor/livewire/flux-pro/stubs/resources/views/flux/time-picker/index.blade.php ENDPATH**/ ?>