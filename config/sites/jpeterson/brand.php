<?php

/**
 * J. Peterson Design — business identity.
 *
 * __replace is mandatory here: merging would inherit GS Construction's phone,
 * email and legal name onto Jenn's site.
 *
 * The contact details are the studio's own, as its previous site carried them
 * (supplied by Jenn and Jill, 2026-09-11). There is no hello@ address: the two
 * people are jenn@ and jill@, and the studio's default line is Jennifer's.
 */
return [
    '__replace' => true,

    'name' => 'J. Peterson Design',
    'display_name' => 'J. Peterson Design',
    // Manifest / browser-chrome colours from her palette (cream page, teal accent).
    'theme_color' => '#408085',
    'background_color' => '#faf8f5',
    'legal_name' => 'J. Peterson Design',
    'also_known_as' => '',
    // Every name the studio trades or is searched under.
    'other_names' => ['Jennifer Peterson Design', 'JPD', 'J. Peterson Design, LLC'],

    // The studio's default line is Jennifer's, the founder.
    'phone' => '(847) 809-7344',
    'phone_href' => '8478097344',
    'email' => 'jenn@jpeterson-design.com',

    // Where the contact form delivers (App\Support\LeadInbox). Jennifer today;
    // add jill@jpeterson-design.com, comma-separated, to send to both.
    'lead_email' => 'jenn@jpeterson-design.com, jill@jpeterson-design.com',
    'reply_signature' => 'J. Peterson Design',

    // The two designers a visitor actually reaches, and the market slugs each
    // one leads — the roster the contact blocks use once they are built.
    'people' => [
        [
            'name' => 'Jennifer Peterson',
            'first_name' => 'Jenn',
            'email' => 'jenn@jpeterson-design.com',
            'phone' => '(847) 809-7344',
            'phone_href' => '8478097344',
            'markets' => ['chicago', 'southwest-michigan'],
        ],
        [
            'name' => 'Jill Kearns',
            'first_name' => 'Jill',
            'email' => 'jill@jpeterson-design.com',
            'phone' => '(404) 626-6952',
            'phone_href' => '4046266952',
            'markets' => ['atlanta'],
        ],
    ],

    'city' => 'Chicago',
    'state' => 'IL',

    'owners' => 'Jenn Peterson',

    // Markets moved to config/sites/jpeterson/markets.php — the single source
    // for the three metros, their pages, and their labels.
    'ai_description' => 'J. Peterson Design: interior design studio serving Chicago and the North Shore.',
];
