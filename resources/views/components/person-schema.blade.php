@blaze(memo: true)

@php
// Person schema for E-E-A-T (founders / authors).
// Emitted on home, about, and other top-level pages.
$people = [
    [
        '@context' => 'https://schema.org',
        '@type' => 'Person',
        '@id' => 'https://gs.construction/#person-gregory',
        'name' => 'Gregory',
        'givenName' => 'Gregory',
        'jobTitle' => 'Founder & Master Remodeling Contractor',
        'description' => 'Co-founder of GS Construction. A carpenter at heart who built his reputation on custom cabinet installations in New York City, then spent years as a construction foreman in the Chicago area — where he built the deep trade network behind every GS project.',
        'image' => [
            '@type' => 'ImageObject',
            'url' => asset('images/greg-patryk.jpg'),
            'contentUrl' => asset('images/greg-patryk.jpg'),
            'width' => 1200,
            'height' => 800,
        ],
        'worksFor' => ['@id' => 'https://gs.construction/#business'],
        'affiliation' => ['@id' => 'https://gs.construction/#business'],
        'knowsAbout' => [
            'Kitchen Remodeling',
            'Bathroom Remodeling',
            'Home Remodeling',
            'Basement Finishing',
            'Custom Cabinetry',
            'Custom Cabinet Installation',
            'Tile Installation',
            'Construction Crew Management',
        ],
        'knowsLanguage' => ['English', 'Polish'],
        'workLocation' => [
            '@type' => 'Place',
            'name' => 'Chicago Suburbs, IL',
            'address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => 'Arlington Heights',
                'addressRegion' => 'IL',
                'addressCountry' => 'US',
            ],
        ],
    ],
    [
        '@context' => 'https://schema.org',
        '@type' => 'Person',
        '@id' => 'https://gs.construction/#person-patryk',
        'name' => 'Patryk',
        'givenName' => 'Patryk',
        'jobTitle' => 'Co-Founder & Project Manager',
        'description' => 'Co-founder of GS Construction. Started working alongside his father Gregory on New York City cabinet installations and has worked with him for over two decades. Leads project logistics, design, planning, and client relationships — keeping remodels moving through the setbacks every construction project brings.',
        'image' => [
            '@type' => 'ImageObject',
            'url' => asset('images/greg-patryk.jpg'),
            'contentUrl' => asset('images/greg-patryk.jpg'),
            'width' => 1200,
            'height' => 800,
        ],
        'worksFor' => ['@id' => 'https://gs.construction/#business'],
        'affiliation' => ['@id' => 'https://gs.construction/#business'],
        'knowsAbout' => [
            'Kitchen Design',
            'Bathroom Design',
            'Project Management',
            'Construction Logistics',
            'Construction Scheduling',
            'Home Renovation Planning',
        ],
        'knowsLanguage' => ['English', 'Polish'],
        'workLocation' => [
            '@type' => 'Place',
            'name' => 'Chicago Suburbs, IL',
            'address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => 'Arlington Heights',
                'addressRegion' => 'IL',
                'addressCountry' => 'US',
            ],
        ],
    ],
];
@endphp

@foreach($people as $person)
<script type="application/ld+json">
{!! json_encode($person, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) !!}
</script>
@endforeach
