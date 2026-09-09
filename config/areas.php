<?php

return [

    /*
    | Candidate towns for new service-area pages come from the bundled Census
    | catalog (resources/data/chicagoland-towns.json, places within 45 miles
    | of the office). This is how far out the admin dropdown offers them.
    */
    'candidate_radius_miles' => (float) env('AREAS_CANDIDATE_RADIUS_MILES', 35),

];
