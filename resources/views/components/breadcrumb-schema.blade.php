@props(['items' => []])

@php
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
@endphp

<script type="application/ld+json">
{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
</script>
