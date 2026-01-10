<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'area' => null,
    'showSubtitle' => false,
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
    'area' => null,
    'showSubtitle' => false,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<div class="mx-auto max-w-2xl text-center">
    <p class="text-sm font-semibold uppercase tracking-widest text-sky-600 dark:text-sky-400">Testimonials</p>
    <h2 class="mt-2 font-heading text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-zinc-100">
        Your Neighbours<?php echo e($area ? ' in ' . $area->city : ''); ?> Love Us
    </h2>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($showSubtitle): ?>
    <p class="mt-2 text-lg text-zinc-600 dark:text-zinc-300">
        Real reviews from homeowners. Ask us for live phone references.
    </p>
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
</div>
<?php /**PATH /home/patryk/web/gsc/resources/views/components/testimonials-header.blade.php ENDPATH**/ ?>