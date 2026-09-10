<?php

/**
 * J. Peterson Design social profiles.
 *
 * Overlays config/socials.php: keys listed here replace the shared defaults,
 * keys omitted are inherited. Placeholder URLs until Jenn supplies the real
 * profiles — these must come from her, not be scraped from the current site.
 */
return [
    // Replace, do not merge: inheriting GS Construction's Google/Yelp/Angi
    // review URLs would point Jenn's footer and schema at another business.
    '__replace' => true,

    'instagram' => [
        'url' => 'https://www.instagram.com/jpetersondesign/',
        'label' => 'Instagram',
        'icon' => 'images/socials/instagram.svg',
        'review' => false,
    ],
    'houzz' => [
        'url' => 'https://www.houzz.com/professionals/kitchen-and-bath-designers/j-peterson-design-llc-pfvwus-pf~402700139',
        'label' => 'Houzz',
        'icon' => 'images/socials/houzz.svg',
        'review' => true,
    ],
];
