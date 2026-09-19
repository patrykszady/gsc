<?php

return [

    /*
    | Candidate towns for new service-area pages come from the bundled Census
    | catalog (resources/data/chicagoland-towns.json, places within 45 miles
    | of the office). This is how far out the admin dropdown offers them.
    */
    'candidate_radius_miles' => (float) env('AREAS_CANDIDATE_RADIUS_MILES', 35),

    /*
    | Half-height in degrees of the tighter box towns:import draws around a
    | market centre for its neighbourhood/suburb/quarter pass, AND the same
    | box App\Support\Areas\MajorCityMarkets checks a point against to decide
    | whether it is "inside a major-city market" — the rule that gates both
    | searching for a neighbourhood (TownCatalog::candidates) and adding one
    | (AreaMapController::createFromMap). One number, read by both, so an
    | import and the addability check it backs can never drift apart.
    */
    'neighbourhood_radius_degrees' => (float) env('AREAS_NEIGHBOURHOOD_RADIUS_DEGREES', 0.20),

];
