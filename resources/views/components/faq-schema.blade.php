@blaze(memo: true)
@props(['faqs' => []])

@if(count($faqs) > 0)
@php
$faqItems = [];

foreach ($faqs as $faq) {
    $faqItems[] = [
        '@type' => 'Question',
        'name' => $faq['question'],
        'acceptedAnswer' => [
            '@type' => 'Answer',
            'text' => $faq['answer'],
        ],
    ];
}

$schema = [
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'inLanguage' => 'en-US',
    'isPartOf' => ['@id' => 'https://gs.construction/#website'],
    'about' => ['@id' => 'https://gs.construction/#business'],
    'speakable' => [
        '@type' => 'SpeakableSpecification',
        'cssSelector' => ['dt', 'dd'],
    ],
    'mainEntity' => $faqItems,
];
@endphp

<script type="application/ld+json">
{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
</script>
@endif
