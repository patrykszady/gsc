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
            'title'        => 'GS Construction & Remodeling', // Short suffix to keep total under 70 chars
            'titleBefore'  => false, // Put defaults.title after page title
            'description'  => 'Professional kitchen, bathroom, and home remodeling services. Family-owned business serving the Chicagoland area with over 40 years of combined experience.',
            'separator'    => ' | ',
            'keywords'     => ['kitchen remodeling', 'bathroom remodeling', 'home remodeling', 'remodeling contractors', 'Chicago remodeling', 'Chicagoland contractors', 'kitchen renovation', 'bathroom renovation', 'home renovation'],
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
            'title'       => 'GS Construction & Remodeling',
            'description' => 'Professional kitchen, bathroom, and home remodeling services in the Chicagoland area.',
            'url'         => null, // Use Url::current()
            'type'        => 'website',
            'site_name'   => 'GS Construction & Remodeling',
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
            'title'       => 'GS Construction & Remodeling',
            'description' => 'Professional kitchen, bathroom, and home remodeling services in the Chicagoland area.',
            'url'         => 'current',
            'type'        => 'WebPage',
            'images'      => [],
        ],
    ],
];
