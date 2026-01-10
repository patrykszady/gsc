<?php $attributes ??= new \Illuminate\View\ComponentAttributeBag;

$__newAttributes = [];
$__propNames = \Illuminate\View\ComponentAttributeBag::extractPropNames((['items' => []]));

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

foreach (array_filter((['items' => []]), 'is_string', ARRAY_FILTER_USE_KEY) as $__key => $__value) {
    $$__key = $$__key ?? $__value;
}

$__defined_vars = get_defined_vars();

foreach ($attributes->all() as $__key => $__value) {
    if (array_key_exists($__key, $__defined_vars)) unset($$__key);
}

unset($__defined_vars, $__key, $__value); ?>

<?php
$breadcrumbItems = [];

// Always start with Home
$breadcrumbItems[] = [
    '@type' => 'ListItem',
    'position' => 1,
    'name' => 'Home',
    'item' => url('/'),
];

// Add passed items (filter out nulls)
$position = 2;
foreach (array_filter($items) as $item) {
    if (!$item) continue;
    
    $listItem = [
        '@type' => 'ListItem',
        'position' => $position,
        'name' => $item['name'],
    ];
    
    // Only add item URL if it's not the current page (last item)
    if (isset($item['url'])) {
        $listItem['item'] = $item['url'];
    }
    
    $breadcrumbItems[] = $listItem;
    $position++;
}

$schema = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => $breadcrumbItems,
];
?>

<script type="application/ld+json">
<?php echo json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); ?>

</script>
<?php /**PATH /home/patryk/web/gsc/resources/views/components/breadcrumb-schema.blade.php ENDPATH**/ ?>