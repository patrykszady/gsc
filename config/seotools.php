<?php
/**
 * @see https://github.com/artesaos/seotools
 */

return [
    'inertia' => env('SEO_TOOLS_INERTIA', false),
    'meta' => [
        /*
         * The default configurations to be used by the meta generator.
         */
        'defaults'       => [
            'title'        => 'GS Construction', // Short suffix to keep total under 70 chars
            'titleBefore'  => false, // Put defaults.title after page title
            'description'  => 'Kitchen remodeling & bathroom renovations in Chicago suburbs. Family-owned contractors with 40+ years experience. Arlington Heights, Palatine, Barrington & more.',
            'separator'    => ' | ',
            'keywords'     => [
                // Primary service keywords
                'kitchen remodeling', 'bathroom remodeling', 'home remodeling',
                // "In location" search patterns
                'kitchen remodeling in palatine', 'bathroom renovations in arlington heights',
                'kitchen remodel chicago suburbs', 'bathroom remodel near me',
                // Contractor keywords
                'remodeling contractors', 'kitchen contractors', 'bathroom contractors',
                // Location keywords
                'chicago suburbs remodeling', 'palatine remodeling', 'arlington heights remodeling',
                'barrington kitchen remodel', 'buffalo grove bathroom renovation',
            ],
            'canonical'    => 'current', // Use Url::current() for canonical
            'robots'       => 'index, follow',
        ],
        /*
         * Webmaster tags are always added.
         */
        'webmaster_tags' => [
            'google'    => null, // Add Google Search Console verification code if you have one
            'bing'      => null,
            'alexa'     => null,
            'pinterest' => null,
            'yandex'    => null,
            'norton'    => null,
        ],

        'add_notranslate_class' => false,
    ],
    'opengraph' => [
        /*
         * The default configurations to be used by the opengraph generator.
         */
        'defaults' => [
            'title'       => 'GS Construction',
            'description' => 'Kitchen remodeling & bathroom renovations in Chicago suburbs. Family-owned contractors with 40+ years experience.',
            'url'         => null, // Use Url::current()
            'type'        => 'website',
            'site_name'   => 'GS Construction',
            'images'      => [],
        ],
    ],
    'twitter' => [
        /*
         * The default values to be used by the twitter cards generator.
         */
        'defaults' => [
            'card'        => 'summary_large_image',
            'site'        => '@gsconstruction',
        ],
    ],
    'json-ld' => [
        /*
         * The default configurations to be used by the json-ld generator.
         */
        'defaults' => [
            'title'       => 'GS Construction',
            'description' => 'Kitchen remodeling & bathroom renovations in Chicago suburbs. Family-owned contractors with 40+ years experience.',
            'url'         => 'current',
            'type'        => 'WebPage',
            'images'      => [],
        ],
    ],
];
