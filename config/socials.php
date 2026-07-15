<?php

return [
    'instagram' => [
        'url' => 'https://www.instagram.com/gs.construction.co/',
        'label' => 'Instagram',
        'icon' => 'images/socials/instagram.svg',
    ],
    'google' => [
        // Canonical Google Maps place URL (built from the verified GBP place_id)
        // rather than a search query. A stable place URL is a far stronger
        // `sameAs` entity signal — it resolves Google's Knowledge Graph to THIS
        // business, which helps branded search ("gs construction") and disambiguates
        // us from other GS Constructions. Also used for the Google social link.
        'url' => 'https://www.google.com/maps/place/?q=place_id:' . env('GOOGLE_BUSINESS_PROFILE_PLACE_ID', 'ChIJ1VmJQHm5D4gRZDQlQNkLz2A'),
        'label' => 'Google',
        'icon' => 'images/socials/google.svg',
    ],
    'facebook' => [
        'url' => 'https://www.facebook.com/gs.construction.chi',
        'label' => 'Facebook',
        'icon' => 'images/socials/facebook.svg',
    ],
    'yelp' => [
        'url' => 'https://www.yelp.com/biz/gs-construction-prospect-heights',
        'label' => 'Yelp',
        'icon' => 'images/socials/yelp.svg',
    ],
    'houzz' => [
        'url' => 'https://www.houzz.com/professionals/kitchen-and-bath-remodelers/gs-construction-pfvwus-pf~1225706575',
        'label' => 'Houzz',
        'icon' => 'images/socials/houzz.svg',
    ],
    'angi' => [
        'url' => 'https://www.angi.com/companylist/us/il/chicagoland/gs-construction-and-remodeling-reviews-11400361.htm',
        'label' => 'Angi',
        'icon' => 'images/socials/angi.svg',
    ],
];