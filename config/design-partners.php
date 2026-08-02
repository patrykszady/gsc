<?php

/**
 * Design professionals GS Construction builds for (/design-partners).
 *
 * Unlike config/trades.php — which deliberately does NOT name partner
 * companies — this page names the studios and links out to them. That is the
 * point of it: homeowners arriving with a designer already engaged want to
 * know we work with designers, and designers evaluating us want to see we
 * credit them.
 *
 * Grouped by discipline. A group with no named firms still renders: it
 * explains the discipline and links to the matching /trades page, which
 * already covers when you need one and who hires them. That keeps this page
 * honest — it never implies we have partners we have not named — and means
 * adding a firm later is a config edit, not a template change.
 *
 * CONTENT RULE (docs/legal/): every blurb below is written by us and states
 * only plain, checkable facts — firm name, where they are based, what kind of
 * work they do. Nothing is copied from the firms' own websites, and no claim
 * is made about a relationship beyond "we have built their designs".
 * Before this goes live, each named firm should confirm the wording and that
 * they are happy to be listed at all.
 *
 * Names and locations verified from each firm's own site on 2026-08-01. Note
 * zlivinginteriorsllc.com now trades as "ZA Interiors CO." — the old name
 * survives only in their email domain, so the URL and the name differ.
 */

return [

    'enabled' => true,

    'intro' => 'Plenty of our clients arrive with a designer, architect or engineer already on board, and '
        . 'some of the best projects we build start on someone else\'s drawing board. These are the '
        . 'professionals we work alongside. If you are working with one of them, you are in good hands — '
        . 'and if you are still looking, we are happy to make an introduction.',

    'groups' => [
        [
            'key' => 'interior-design',
            'heading' => 'Interior design',
            'blurb' => 'Layout, finishes, cabinetry and furnishings — the decisions that make a finished '
                . 'room feel considered rather than assembled. We build to their drawings and coordinate '
                . 'with them directly through the job.',
            'trade_slug' => 'interior-designers',
            'partners' => [
                [
                    'name' => 'J. Peterson Design',
                    'url' => 'https://www.jpeterson-design.com/',
                    'location' => 'Chicago area',
                    'discipline' => 'Full-service interior design',
                    'blurb' => 'A sister team — Jennifer Peterson and Jill Kearns — running a full-service '
                        . 'interior design studio. Whole-home work, furnishings and finishes, taken from '
                        . 'concept through installation.',
                ],
                [
                    'name' => 'YR Studio',
                    'url' => 'https://www.yr-studio.com/',
                    'location' => 'Wilmette, IL',
                    'discipline' => 'Interior architecture',
                    'blurb' => 'A North Shore studio working in interior architecture — the structural and '
                        . 'spatial side of a remodel, where walls, light and circulation get decided before '
                        . 'any finish is chosen.',
                ],
                [
                    'name' => 'ZA Interiors CO.',
                    'url' => 'https://www.zlivinginteriorsllc.com/',
                    'location' => 'Chicago, IL',
                    'discipline' => 'Interior design',
                    'blurb' => 'A Chicago interior design studio offering full-service design, single-room '
                        . 'projects and creative direction.',
                ],
            ],
        ],
        [
            'key' => 'architecture',
            'heading' => 'Architects',
            'blurb' => 'Illinois-licensed architects for additions, structural changes and the sealed '
                . 'permit drawings most Chicago-suburb villages require. Many clients come to us with plans '
                . 'already drawn; otherwise we bring in an architect we have delivered permitted projects '
                . 'with and coordinate the drawings as part of your project.',
            'trade_slug' => 'architects',
            // TODO: name the architecture firms once each has agreed to be
            // listed. Until then the group links to /trades/architects rather
            // than implying partners we have not named.
            'partners' => [],
        ],
        [
            'key' => 'structural-engineering',
            'heading' => 'Structural engineers',
            'blurb' => 'Illinois-licensed structural engineers for beam sizing, load calculations and the '
                . 'sealed letter your village wants before a load-bearing wall comes down. Knowing a wall '
                . 'carries load and proving what replaces it are different jobs.',
            'trade_slug' => 'structural-engineers',
            // TODO: name the engineering firms once each has agreed to be listed.
            'partners' => [],
        ],
    ],

];
