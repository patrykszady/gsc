<?php

/**
 * J. Peterson Design — the six stages of the design process.
 *
 * Single source of truth. The home page teaser and /process both read this,
 * so the two can never drift — which they already had, with home showing
 * placeholder blurbs while /process carried the real descriptions.
 *
 * Each stage has two lengths on purpose:
 *   summary — one line, for the six-up teaser grid on the home page
 *   body    — the full description, for /process
 * Home is a teaser and must stay scannable; truncating the long text with CSS
 * would cut mid-sentence and read as broken.
 *
 * Stage NAMES are the studio's own, reused with Jenn's authorisation (see
 * docs/sites/jpeterson.md). The descriptions are written for this site. Jenn
 * approves the final wording before launch.
 *
 * No shared config/process.php exists, so SiteConfig injects this wholesale
 * and it reaches views as config('process.steps') — same mechanism as nav.php.
 */
return [
    'steps' => [
        [
            'name' => 'Design consultation',
            'summary' => 'Scope, priorities, budget and timeline — settled in the room itself.',
            'body' => 'We meet to walk the space and talk through the scope of the project — what you want to change, what has to stay, how the room needs to work, and what the budget and timeline realistically allow. Questions are answered here rather than deferred.',
        ],
        [
            'name' => 'Presentation of your design',
            'summary' => 'The proposal, with the materials it would take to build it.',
            'body' => 'Your design comes back to you as a considered proposal, with the materials recommended to build it. You see the intent for the space and what it will take to get there before anything is committed.',
        ],
        [
            'name' => 'Construction documents',
            'summary' => 'The drawings your builder works from and your trades price against.',
            'body' => 'Drawings are produced for installation and for bidding — the set your builder works from and your trades price against. Getting this right is what keeps a project from being re-decided on site.',
        ],
        [
            'name' => 'Material selections',
            'summary' => 'Cabinetry, countertops, lighting and tile — specified and sourced.',
            'body' => 'Cabinetry, countertops, lighting and tile are chosen together, so the finished room fits how you actually live and the way you want it to look. Selections are specified and sourced, not left as a mood board.',
        ],
        [
            'name' => 'Jobsite inspections',
            'summary' => 'Site visits through the build, so the space goes in as drawn.',
            'body' => 'We visit through construction. Seeing the work as it goes in is what catches a problem while it is still cheap to fix, and it is how the finished space ends up matching the drawings.',
        ],
        [
            'name' => 'Finishing touches',
            'summary' => 'Furniture, window treatments, styling and the final walkthrough.',
            'body' => 'Optional, and often where a project stops looking new and starts looking yours: furniture selections, window treatments and accessorising, through to the final walkthrough.',
        ],
    ],
];
