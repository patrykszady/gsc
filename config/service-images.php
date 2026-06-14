<?php

/**
 * Curated, self-hosted stock imagery for service types that do not yet have
 * real completed-project photos (basement finishing & home additions).
 *
 * These are free-to-use Pexels photos (Pexels License — no attribution
 * required) that were hand-picked to genuinely depict the work and to read
 * like real Chicagoland jobs, NOT random/unrelated stock. They are surfaced
 * across the service hero sliders, the services overview grid, and the
 * areas-served service pages until real basement/addition projects exist.
 *
 * IMPORTANT: these are labelled as "representative" (see App\Support\ServiceImages)
 * so we never imply a stock photo is one of our own completed jobs. As soon as
 * a published basement/addition project with a cover image exists, the real
 * photo automatically takes priority over everything here.
 *
 * To swap in real photos: drop files in public/images/services/ and update the
 * 'src' paths below (the alt text too). Keep 3 per type for slider variety.
 */
return [
    'basement' => [
        'label' => 'finished basement',
        'images' => [
            ['src' => 'images/services/basement-1.jpg', 'alt' => 'Finished basement with a game room, exposed brick feature wall, hardwood-look floors and a full bathroom'],
            ['src' => 'images/services/basement-2.jpg', 'alt' => 'Finished basement family room with a leather sofa, recessed lighting, carpet and built-in desk'],
            ['src' => 'images/services/basement-3.jpg', 'alt' => 'Finished lower-level rec room with wood-stove fireplace, paneling and daylight windows'],
        ],
    ],
    'addition' => [
        'label' => 'home addition',
        'images' => [
            ['src' => 'images/services/addition-1.jpg', 'alt' => 'Bright four-season sunroom addition with a wall of windows, tile floor and ceiling fan'],
            ['src' => 'images/services/addition-2.jpg', 'alt' => 'Home addition under construction — open-concept wood framing with the crew on site'],
            ['src' => 'images/services/addition-3.jpg', 'alt' => 'Rear home addition under construction with new masonry walls and windows going in'],
        ],
    ],
];
