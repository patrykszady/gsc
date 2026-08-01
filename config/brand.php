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

    // Verbatim original of the ai-content-description meta tag, kept so
    // gs.construction's output is unchanged by the move to config.
    'ai_description' => 'GS Construction & Remodeling: Kitchen, bathroom, and home remodeling services in Chicago suburbs. Family-owned, 40+ years experience, 53+ five-star reviews. Serving 89+ cities in Chicagoland. (224) 735-4200.',

];
