<?php

/**
 * J. Peterson Design — business identity.
 *
 * __replace is mandatory here: merging would inherit GS Construction's phone,
 * email and legal name onto Jenn's site. Placeholders until she supplies real
 * contact details.
 */
return [
    '__replace' => true,

    'name' => 'J. Peterson Design',
    'display_name' => 'J. Peterson Design',
    'legal_name' => 'J. Peterson Design',
    'also_known_as' => '',

    'phone' => '(847) 000-0000',
    'phone_href' => '8470000000',
    'email' => 'hello@jpeterson-design.com',

    'city' => 'Chicago',
    'state' => 'IL',

    'owners' => 'Jenn Peterson',

    // Markets moved to config/sites/jpeterson/markets.php — the single source
    // for the three metros, their pages, and their labels.
    'ai_description' => 'J. Peterson Design: interior design studio serving Chicago and the North Shore.',
];
