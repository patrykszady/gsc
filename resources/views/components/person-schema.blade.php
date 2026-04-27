@blaze(memo: true)

@php
// Person schema for E-E-A-T (founders / authors).
// Emitted on home, about, and other top-level pages.
$people = [
    [
        '@context' => 'https://schema.org',
        '@type' => 'Person',
        '@id' => 'https://gs.construction/#person-greg',
        'name' => 'Greg',
        'givenName' => 'Greg',
        'jobTitle' => 'Founder & Master Remodeling Contractor',
        'description' => 'Co-founder of GS Construction with 40+ years of combined experience in kitchen, bathroom, and whole-home remodeling in the Chicago suburbs.',
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
            'Tile Installation',
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
        'description' => 'Co-founder of GS Construction. Manages design, planning, and client relationships for kitchen and bathroom remodeling projects across the Chicago suburbs.',
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
