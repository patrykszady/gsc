<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Business identity
    |--------------------------------------------------------------------------
    | Single source of truth for the name, phone, email and address rendered in
    | shared UI (footer, layouts, schema) and in SEO output.
    |
    | This existed nowhere before multi-tenancy — identity was hardcoded as
    | literals across ~96 files. Anything in SHARED code (layouts, footer, nav,
    | schema, SeoService) must read from here, or a second tenant renders GS
    | Construction's phone number and email on its own site.
    |
    | Per-site overrides live in config/sites/{slug}/brand.php and MUST set
    | '__replace' => true — inheriting another business's contact details is
    | worse than having none.
    */

    'name' => 'GS Construction',
    'display_name' => 'GS Construction & Remodeling',
    'legal_name' => 'GS Construction & Remodeling, Inc.',
    'also_known_as' => 'Greg & Son Construction Company',

    'phone' => '(224) 735-4200',
    'phone_href' => '2247354200',
    'email' => 'crew@gs.construction',

    'city' => 'Prospect Heights',
    'state' => 'IL',

    'owners' => 'Greg & Patryk Szady',

    // The ai-content-description meta tag.
    //
    // :reviews and :cities are substituted at render time from CompanyStats —
    // config is loaded (and cached) before the database is usable, so the
    // figures cannot be resolved here. They were hardcoded at "53+ five-star
    // reviews" and "89+ cities" against actuals of 70+ and 70+; this meta tag
    // is what search and AI crawlers quote, so a stale number here is the one
    // that travels furthest.
    'ai_description' => 'GS Construction & Remodeling: Kitchen, bathroom, and home remodeling services in Chicago suburbs. Family-owned, 40+ years experience, :reviews five-star reviews. Serving :cities cities in Chicagoland. (224) 735-4200.',

    /*
    |--------------------------------------------------------------------------
    | Business profiles & citations
    |--------------------------------------------------------------------------
    | Directory and review profiles we maintain. The backlinks intelligence
    | checks each one still links to the site (DataForSEO's crawler never
    | sees Yelp/BBB/Houzz pages, so these are verified by us directly).
    */
    'profiles' => [
        'BBB' => 'https://www.bbb.org/us/il/prospect-heights/profile/general-contractor/gs-construction-remodeling-inc-0654-88701450',
        'Houzz' => 'https://www.houzz.com/professionals/kitchen-and-bath-remodelers/gs-construction-pfvwus-pf~1225706575',
        'Yelp' => 'https://www.yelp.com/biz/gs-construction-prospect-heights',
        'BuildZoom' => 'https://www.buildzoom.com/contractor/gs-construction-remodeling-prospect-heights-il',
        'Nextdoor' => 'https://nextdoor.com/pages/gs-construction-remodeling-prospect-heights-il/',
        'MapQuest' => 'https://www.mapquest.com/us/illinois/gs-construction-743191322',
    ],
];
