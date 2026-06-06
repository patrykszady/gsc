<?php

/**
 * Competitor comparison page configuration.
 *
 * Public, factual, SEO-safe comparison content for "alternative to / vs"
 * intent searches. Do not include defamatory or unverifiable claims.
 * Keep "competitor" entries to neutral facts (publicly visible categories,
 * service area, etc.) and let the "us" column carry the marketing.
 */

return [

    'enabled' => env('COMPETITOR_PAGES_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Last verified
    |--------------------------------------------------------------------------
    | Date the competitor facts on these pages were last reviewed. Surfaced as
    | an "Information verified {date}" line to reinforce the comparison is
    | maintained, not stale. Update when you re-check competitor details.
    */
    'last_verified' => '2026-06-05',

    /*
    |--------------------------------------------------------------------------
    | Universal Comparison Criteria
    |--------------------------------------------------------------------------
    | These are the rows shown in the comparison table on every per-competitor
    | page. The "us" value is filled from this file; the "them" value is
    | overridable per-competitor (defaults to "Varies — verify directly").
    | "why" (optional) is a short homeowner-facing note on why the row matters.
    */
    'criteria' => [
        ['key' => 'ownership',          'label' => 'Ownership',                  'us' => 'Family-owned, father-son team (Greg & Patryk Szady)',
            'why' => 'Owner-operators are personally accountable for your project — not a sales rep who moves on after signing.'],
        ['key' => 'point_of_contact',   'label' => 'Your point of contact',      'us' => 'Patryk & Greg Szady — the owners — are your single point of contact from the first call to the final walkthrough',
            'them_default' => 'Larger firms often hand you to a different coordinator at each phase; ask who your day-to-day contact is and whether it changes.',
            'why' => 'Every hand-off between coordinators is a chance for details to get lost and mistakes to creep in.'],
        ['key' => 'design_model',       'label' => 'Design approach',            'us' => 'We build your project and collaborate with the independent designer or architect you choose — or you can be your own designer: we send you to our trusted material sources, follow your requirements, and install the materials you purchase. Your design, your decisions — we are flexible',
            'them_default' => 'Many firms steer you into in-house design or subcontract it; ask whether you can bring your own designer/architect.',
            'why' => 'A flexible design model means you keep control of the look and the budget instead of being funneled into one in-house package.'],
        ['key' => 'pricing',            'label' => 'Pricing transparency',       'us' => 'Itemized, transparent pricing — labor is not marked up through layers of middlemen',
            'them_default' => 'Some firms subcontract the trades and add a labor markup on top; ask for an itemized scope and who actually performs the work.',
            'why' => 'An itemized scope lets you compare apples-to-apples and see exactly what you are paying for.'],
        ['key' => 'self_perform',       'label' => 'Who does the work',          'us' => 'Owner-led crew self-performs most trades; specialists are vetted and supervised by us',
            'them_default' => 'Ask how much is self-performed vs. handed to subcontractors, and who supervises them on site.',
            'why' => 'Who actually holds the tools — and who supervises them — drives quality and accountability on site.'],
        ['key' => 'experience',         'label' => 'Combined experience',        'us' => '40+ years',
            'them_default' => 'Verify directly.'],
        ['key' => 'service_area',       'label' => 'Primary service area',       'us' => 'Northwest Chicago suburbs (Arlington Heights, Palatine, Schaumburg, Barrington, etc.)',
            'why' => 'A contractor who works your area daily knows local permitting, inspectors, and supply houses.'],
        ['key' => 'project_types',      'label' => 'Project types',              'us' => 'Kitchen, bathroom, and whole-home remodeling, additions, exteriors, basements, and mudrooms'],
        ['key' => 'permits',            'label' => 'Permit handling',            'us' => 'We pull permits and coordinate inspections',
            'why' => 'Unpermitted work can stall a future home sale and void insurance — confirm who is responsible.'],
        ['key' => 'communication',      'label' => 'Project communication',      'us' => 'Daily — your private client portal to track your schedule (past and upcoming), current change orders, and up-to-date balances — plus a direct line to the owners and weekly progress updates',
            'them_default' => 'Ask whether you get a real-time client portal for your schedule, change orders, and balances, or just occasional email/phone updates.',
            'why' => 'A live portal means you always know the schedule, what changed, and what you owe — no waiting for a callback.'],
        ['key' => 'photo_proof',        'label' => 'Photo proof',                'us' => 'Hundreds of in-progress and completed project photos on-site'],
        ['key' => 'public_reviews',     'label' => 'Public reviews',             'us' => 'Verified reviews on Google, Houzz, Yelp, and Angi',
            'why' => 'Reviews across multiple independent platforms are harder to game than testimonials on a company\'s own site.'],
        ['key' => 'estimate',           'label' => 'Estimates',                  'us' => 'Free in-home estimate with itemized scope'],
        ['key' => 'licensed_insured',   'label' => 'Licensed & insured',         'us' => 'Yes',
            'why' => 'Proper licensing and insurance protect you if something goes wrong on the job.'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Competitors
    |--------------------------------------------------------------------------
    | slug         : URL slug used at /compare/{slug}
    | name         : Display name (used carefully — do not put in title tag if
    |                their trademark protection is strict; default templates
    |                use "alternative to" framing, which is SEO-safe).
    | website      : Their public website (rendered as external link with
    |                rel=noopener nofollow).
    | location     : Public, neutral location string.
    | focus        : Public, neutral specialty.
    | them         : Optional per-row overrides keyed by criteria.key.
    | comparison_note : Unique 2-4 sentence factual blurb shown on the page so
    |                each /compare/{slug} page has genuinely distinct content
    |                (avoids thin/duplicate-content SEO penalties). Keep neutral.
    | noindex      : Optional bool. When true the page sends robots noindex —
    |                use as a safety valve for entries that don't yet have
    |                unique copy you're comfortable indexing.
    | also_known_as: Extra brand variants for query tracking.
    */
    'competitors' => [
        [
            'slug' => 'kitchen-village',
            'name' => 'Kitchen Village',
            'website' => 'https://kitchenvillage.com',
            'location' => 'Chicago area',
            'focus' => 'Kitchen and bath showroom',
            'comparison_note' => 'Kitchen Village is a kitchen and bath showroom focused on cabinetry and fixture selection, so the build itself often runs through their preferred installers. GS Construction is an owner-led general remodeler: Greg and Patryk handle the full project, pull permits, and coordinate every trade — whether you bring your own designer or shop your own materials.',
            'them' => [
                'project_types' => 'Showroom-led kitchen and bath',
            ],
            'also_known_as' => ['kitchenvillage', 'kitchen village chicago'],
        ],
        [
            'slug' => '4ever-remodeling',
            'name' => '4Ever Remodeling',
            'website' => 'https://4everremodeling.com',
            'location' => 'Chicago area',
            'focus' => 'Full-service remodeling',
            'comparison_note' => '4Ever Remodeling is a full-service Chicago remodeler. The difference with GS Construction comes down to who you work with day to day: the owners, Greg and Patryk Szady, run your project from first call to final walkthrough instead of handing it to rotating coordinators, and labor pricing stays transparent with no middleman markup.',
            'also_known_as' => ['4ever remodeling', '4everremodeling', 'four ever remodeling'],
        ],
        [
            'slug' => 'airoom',
            'name' => 'Airoom',
            'website' => 'https://www.airoom.com',
            'location' => 'Chicago suburbs',
            'focus' => 'Design-build additions and remodels',
            'comparison_note' => 'Airoom is one of the largest design-build firms in the Chicago suburbs, with an in-house architecture and design department that typically leads the entire process. GS Construction takes a leaner, owner-led approach: bring your own designer or architect (or be your own), buy your own materials from our trusted material sources, and work directly with the owners rather than a large project team.',
            'also_known_as' => ['airoom architects', 'airoom builders'],
        ],
        [
            'slug' => 'normandy-remodeling',
            'name' => 'Normandy Remodeling',
            'website' => 'https://www.normandyremodeling.com',
            'location' => 'Chicago suburbs',
            'focus' => 'Design-build remodeling',
            'comparison_note' => 'Normandy Remodeling is an established design-build company with a large showroom and in-house design staff that guides clients through a set process. With GS Construction you keep more control: collaborate with the independent designer or architect you choose, follow live schedule, change-order, and balance updates in our Daily portal, and work directly with Greg and Patryk on every phase.',
            'also_known_as' => ['normandy builders', 'normandy design'],
        ],
        [
            'slug' => '123-remodeling',
            'name' => '123 Remodeling',
            'website' => 'https://123remodeling.com',
            'location' => 'Chicago, IL (offices in Chicago and Northfield)',
            'focus' => 'Design-build kitchen, bathroom, condo, and basement remodeling',
            'comparison_note' => '123 Remodeling runs an in-house design-build team out of Chicago and Northfield offices. GS Construction serves the Northwest suburbs with an owner-led crew that self-performs most trades, and stays flexible on design — whether you want a designer, want to design it yourself, or want to supply your own materials from our trusted material sources.',
            'them' => [
                'service_area' => 'Chicago and North Shore suburbs',
                'design_model' => 'In-house design-build team of interior designers and architectural staff.',
                'point_of_contact' => 'A personal project manager oversees the build; ask whether your contact changes between phases.',
            ],
            'also_known_as' => ['123 remodeling inc', '123 remodeling chicago'],
        ],
        [
            'slug' => 'pickell-builders',
            'name' => 'Orren Pickell Building Group',
            'website' => 'https://www.pickellbuilders.com',
            'location' => 'Wilmette, IL and Chicago North Shore',
            'focus' => 'Luxury custom homes and high-end design-build remodeling',
            'comparison_note' => 'Orren Pickell Building Group focuses on luxury custom homes and high-end remodels across a wide region that includes Southern Wisconsin and Harbor Country, Michigan. GS Construction is a Northwest-suburbs remodeling specialist — kitchens, baths, whole-home, additions, basements, and exteriors — with itemized, transparent pricing and the owners on site rather than a large luxury-build organization.',
            'them' => [
                'service_area' => 'Chicagoland, Southern Wisconsin, and Harbor Country Michigan',
                'project_types' => 'Luxury custom homes plus remodeling and additions',
                'design_model' => 'In-house design/build — their designers and architects handle the full process.',
            ],
            'also_known_as' => ['orren pickell', 'pickell building group', 'pickell builders'],
        ],
        [
            'slug' => 'skor-construction',
            'name' => 'Skor Construction',
            'website' => 'https://skorconstruction.com',
            'location' => 'Palatine, IL',
            'focus' => 'Design-build remodeling, additions, kitchens, baths, and basements',
            'comparison_note' => 'Skor Construction is a Palatine design-build firm that runs concept, architecture, and construction in-house. GS Construction works the same Northwest-suburb communities but keeps the model flexible: bring your own designer or architect, supply your own materials from our trusted material sources, and talk directly to Greg and Patryk throughout the build.',
            'them' => [
                'service_area' => "Chicago's North Shore and Northwest suburbs",
                'design_model' => 'In-house design-build team handles concept, architecture, and construction.',
            ],
            'also_known_as' => ['skor construction design build', 'build with skor'],
        ],
        [
            'slug' => 'chi-renovation',
            'name' => 'Chi Renovation and Design',
            'website' => 'https://www.chirenovation.com',
            'location' => 'Chicago, IL',
            'focus' => 'Design-build interior remodeling and architectural design',
            'comparison_note' => 'Chi Renovation and Design pairs in-house architectural and interior design with construction for Chicago and near-north projects. GS Construction concentrates on the Northwest suburbs and lets you choose how design happens — your designer, our recommendations, or your own plans — with owner-led crews doing the work and clear, itemized pricing.',
            'them' => [
                'service_area' => 'Chicago and near-north suburbs',
                'design_model' => 'In-house design-build with an architect and engineer on retainer and certified interior design staff.',
            ],
            'also_known_as' => ['chi renovation', 'chirenovation', 'chi ren'],
        ],
        [
            'slug' => 'ohi-remodeling',
            'name' => 'OHi (Our Home Improvement)',
            'website' => 'https://www.contactohi.com',
            'location' => 'Elk Grove Village, IL',
            'focus' => 'Design-build kitchen, bath, basement, and additions with an in-house showroom',
            'comparison_note' => 'OHi (Our Home Improvement) uses an in-house showroom and design team, with a design retainer up front and a separate project manager taking over during construction. GS Construction keeps it simpler: the owners, Greg and Patryk, are your single point of contact from first call to final walkthrough, and you are free to bring your own designer or buy your own materials.',
            'them' => [
                'service_area' => 'Chicago Northwest suburbs',
                'design_model' => 'In-house designers and a showroom; a design retainer and deposit are required before plans begin.',
                'point_of_contact' => 'A designer leads early phases; a separate project manager becomes your main contact during construction.',
            ],
            'also_known_as' => ['ohi', 'our home improvement', 'contact ohi'],
        ],
        [
            'slug' => 'modern-builders-design',
            'name' => 'Modern Builders & Design',
            'website' => 'https://www.modernbuildersdesign.com',
            'location' => 'Round Lake, IL',
            'focus' => 'General contractor offering remodeling, painting, and epoxy flooring',
            'comparison_note' => 'Modern Builders & Design is a Round Lake general contractor that also offers painting and epoxy flooring. GS Construction focuses specifically on remodeling craftsmanship — kitchens, baths, whole-home, additions, basements, and exteriors — across the Northwest suburbs, with 40+ years of combined experience and the owners running every project.',
            'them' => [
                'service_area' => 'Barrington, Round Lake, and surrounding Chicago suburbs',
                'project_types' => 'Kitchen, bathroom, and basement remodeling plus painting and flooring',
            ],
            'also_known_as' => ['modern builders and design', 'modern builders design'],
        ],
    ],
];
