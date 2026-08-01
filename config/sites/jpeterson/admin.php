<?php

/**
 * J. Peterson Design — admin UI.
 *
 * The accent ramp is the logo teal (#4e9da2) rebuilt at the same hue and
 * saturation across Tailwind's sky lightness curve, so every admin accent
 * utility picks it up through var(--color-sky-N).
 *
 * The 400–600 steps are deliberately a shade darker than the public
 * brand ramp: the admin sets white labels on -500 and -600 fills, and the
 * logo teal itself is only 3.15:1 against white. These values were measured,
 * and every pairing the admin actually uses clears WCAG AA:
 *
 *   white on 500  4.52    800 on 100   8.20    700 on 50        7.22
 *   white on 600  5.94    200 on 900   8.25    400 on zinc-900  7.49
 *
 * No logo: Jenn has not supplied a mark for use here, and GS Construction's
 * would be worse than none. null makes the sidebar render the name alone.
 */
return [
    'accent' => [
        50 => '#f3f9f9',
        100 => '#e2f0f1',
        200 => '#c4e1e3',
        300 => '#9fced1',
        400 => '#6db4b9',
        500 => '#408085',
        600 => '#366c70',
        700 => '#2d5a5d',
        800 => '#254b4d',
        900 => '#1f3f41',
        950 => '#162b2d',
    ],

    'logo' => null,
    'logo_dark' => null,
];
