<?php

/**
 * Catalogue of social platforms the app supports.
 *
 * This is the ROSTER, not anyone's profiles. It exists so the admin can offer
 * an empty field for every platform a tenant *could* set up — a form built
 * only from platforms already configured is a chicken-and-egg trap, since a
 * new tenant could never add its first Facebook URL.
 *
 * Values live in config/socials.php (shared defaults) and
 * config/sites/{slug}/socials.php (per tenant). NOTHING here may contain a
 * real profile URL, handle or listing ID: the placeholders render in every
 * tenant's admin, and the previous hardcoded ones exposed gs.construction's
 * live Yelp, Houzz and Angi identifiers inside J. Peterson Design's.
 *
 * 'review' marks platforms whose URL is used as a review-collection target,
 * matching the flag in config/socials.php.
 */
return [
    'instagram' => [
        'label' => 'Instagram',
        'icon' => 'images/socials/instagram.svg',
        'placeholder' => 'https://www.instagram.com/yourhandle/',
    ],
    'google' => [
        'label' => 'Google',
        'icon' => 'images/socials/google.svg',
        'placeholder' => 'https://www.google.com/maps/place/?q=place_id:YOUR_PLACE_ID',
    ],
    'facebook' => [
        'label' => 'Facebook',
        'icon' => 'images/socials/facebook.svg',
        'placeholder' => 'https://www.facebook.com/yourpage',
    ],
    'yelp' => [
        'label' => 'Yelp',
        'icon' => 'images/socials/yelp.svg',
        'placeholder' => 'https://www.yelp.com/biz/your-business',
    ],
    'houzz' => [
        'label' => 'Houzz',
        'icon' => 'images/socials/houzz.svg',
        'placeholder' => 'https://www.houzz.com/pro/yourprofile',
    ],
    'angi' => [
        'label' => 'Angi',
        'icon' => 'images/socials/angi.svg',
        'placeholder' => 'https://www.angi.com/companylist/us/your-listing.htm',
    ],
];
