<?php

/**
 * SS Systems — navigation.
 *
 * ss claims nothing in config/sites.php 'exclusive_paths', so every one of
 * gs.construction's content paths 404s here. Declaring the list explicitly —
 * even though themes/ss currently renders no header — is what stops this site
 * silently inheriting gsc's eleven links the day it grows one, and it lets
 * sites:check tell "no menu on purpose" apart from "nobody has looked at this
 * yet".
 *
 * The home page is a single scrolling document, so these are in-page anchors.
 */
return [
    'links' => [
        ['label' => 'Platforms', 'href' => '/#platforms'],
        ['label' => 'Capabilities', 'href' => '/#capabilities'],
        ['label' => 'Contact', 'href' => '/contact', 'cta' => true],
    ],
];
