<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames(([
    'image' => null,           // ProjectImage model
    'src' => null,             // Direct URL (if no model)
    'thumb' => null,           // Direct thumbnail URL (if no model)
    'size' => 'medium',        // Thumbnail size for ProjectImage
    'alt' => '',
    'class' => '',
    'eager' => false,          // fetchpriority="high" and no lazy loading
    'aspectRatio' => null,     // e.g., '4/3', '16/9', 'square'
    'width' => null,
    'height' => null,
    'rounded' => null,         // e.g., 'lg', '2xl', 'full'
    'objectFit' => 'cover',    // cover, contain, fill, etc.
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
    'image' => null,           // ProjectImage model
    'src' => null,             // Direct URL (if no model)
    'thumb' => null,           // Direct thumbnail URL (if no model)
    'size' => 'medium',        // Thumbnail size for ProjectImage
    'alt' => '',
    'class' => '',
    'eager' => false,          // fetchpriority="high" and no lazy loading
    'aspectRatio' => null,     // e.g., '4/3', '16/9', 'square'
    'width' => null,
    'height' => null,
    'rounded' => null,         // e.g., 'lg', '2xl', 'full'
    'objectFit' => 'cover',    // cover, contain, fill, etc.
]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
    // Determine URLs from either ProjectImage model or direct props
    if ($image) {
        $fullUrl = $image->getWebpThumbnailUrl($size) ?? $image->getThumbnailUrl($size);
        $thumbUrl = $image->getWebpThumbnailUrl('thumb') ?? $image->getThumbnailUrl('thumb') ?? $fullUrl;
        $altText = $alt ?: $image->seo_alt_text;
    } else {
        $fullUrl = $src;
        $thumbUrl = $thumb ?? $src;
        $altText = $alt;
    }
    
    // Build aspect ratio class
    $aspectClass = match($aspectRatio) {
        'square' => 'aspect-square',
        '4/3' => 'aspect-[4/3]',
        '3/4' => 'aspect-[3/4]',
        '16/9' => 'aspect-video',
        '3/2' => 'aspect-[3/2]',
        '2/3' => 'aspect-[2/3]',
        default => $aspectRatio ? "aspect-[$aspectRatio]" : '',
    };
    
    // Build rounded class
    $roundedClass = $rounded ? "rounded-$rounded" : '';
    
    // Object fit class
    $objectClass = "object-$objectFit";
    
    // Loading strategy
    $loading = $eager ? 'eager' : 'lazy';
    $fetchpriority = $eager ? 'high' : 'auto';
    
    // Unique ID for this image instance
    $imageId = 'lqip-' . md5($fullUrl . microtime());
?>

<?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if BLOCK]><![endif]--><?php endif; ?><?php if($fullUrl): ?>
<div 
    x-data="{
        loaded: false,
        thumbLoaded: false,
        wasCached: false,
        showBlur: false,
        init() {
            // Check if browser already has the image cached (complete)
            const fullImg = this.$refs.fullImg;
            if (fullImg?.complete && fullImg?.naturalWidth > 0) {
                this.wasCached = true;
                this.loaded = true;
            } else {
                this.showBlur = true;
            }
        },
        onLoad() {
            this.loaded = true;
            window.imageCache?.set('<?php echo e($fullUrl); ?>', '<?php echo e($fullUrl); ?>');
        }
    }"
    <?php echo e($attributes->merge(['class' => "relative overflow-hidden bg-zinc-200 dark:bg-zinc-700 $aspectClass $roundedClass $class"])); ?>

>
    
    <img
        x-cloak
        x-ref="thumbImg"
        x-show="showBlur && !loaded"
        src="<?php echo e($thumbUrl); ?>"
        alt=""
        aria-hidden="true"
        class="absolute inset-0 h-full w-full <?php echo e($objectClass); ?> blur-xl scale-110"
        :class="thumbLoaded ? 'opacity-100' : 'opacity-0'"
        @load="thumbLoaded = true"
    />
    
    
    <img
        x-ref="fullImg"
        src="<?php echo e($fullUrl); ?>"
        alt="<?php echo e($altText); ?>"
        loading="<?php echo e($loading); ?>"
        fetchpriority="<?php echo e($fetchpriority); ?>"
        decoding="async"
        <?php if($width): ?> width="<?php echo e($width); ?>" <?php endif; ?>
        <?php if($height): ?> height="<?php echo e($height); ?>" <?php endif; ?>
        class="absolute inset-0 h-full w-full <?php echo e($objectClass); ?>"
        :class="wasCached ? 'opacity-100' : (loaded ? 'opacity-100 transition-opacity duration-300' : 'opacity-0')"
        @load="onLoad()"
    />
</div>
<?php endif; ?><?php if(\Livewire\Mechanisms\ExtendBlade\ExtendBlade::isRenderingLivewireComponent()): ?><!--[if ENDBLOCK]><![endif]--><?php endif; ?>
<?php /**PATH /home/patryk/web/gsc/resources/views/components/lqip-image.blade.php ENDPATH**/ ?>