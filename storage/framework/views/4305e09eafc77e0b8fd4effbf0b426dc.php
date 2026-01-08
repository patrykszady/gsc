<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'image' => null,
    'size' => 'medium',
    'alt' => null,
    'class' => '',
    'eager' => false,
    'width' => null,
    'height' => null,
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
    'image' => null,
    'size' => 'medium',
    'alt' => null,
    'class' => '',
    'eager' => false,
    'width' => null,
    'height' => null,
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($image): ?>
<?php
    $fallbackUrl = $image->getThumbnailUrl($size);
    $webpUrl = $image->getWebpThumbnailUrl($size);
    $altText = $alt ?? $image->seo_alt_text;
    $loading = $eager ? 'eager' : 'lazy';
    $fetchpriority = $eager ? 'high' : 'auto';
?>

<picture>
    <?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($webpUrl): ?>
        <source srcset="<?php echo e($webpUrl); ?>" type="image/webp">
    <?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
    <img 
        src="<?php echo e($fallbackUrl); ?>" 
        alt="<?php echo e($altText); ?>"
        loading="<?php echo e($loading); ?>"
        fetchpriority="<?php echo e($fetchpriority); ?>"
        decoding="async"
        <?php if($width): ?> width="<?php echo e($width); ?>" <?php endif; ?>
        <?php if($height): ?> height="<?php echo e($height); ?>" <?php endif; ?>
        <?php echo e($attributes->merge(['class' => $class])); ?>

    >
</picture>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php /**PATH /home/patryk/web/gsc/resources/views/components/responsive-image.blade.php ENDPATH**/ ?>