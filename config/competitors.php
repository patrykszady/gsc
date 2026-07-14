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
    'last_verified' => '2026-07-13',

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
        ['key' => 'self_perform',       'label' => 'Who does the work',          'us' => 'Long-standing, vetted trade partners — licensed where required, insured, scheduled and supervised daily by the owners, covered by one GS warranty',
            'them_default' => 'Ask who actually performs each trade, who supervises them on site, and whose warranty covers the work.',
            'why' => 'Who actually holds the tools — and who supervises them — drives quality and accountability on site.'],
        ['key' => 'experience',         'label' => 'Combined experience',        'us' => '40+ years',
            'them_default' => 'Verify directly.'],
        ['key' => 'service_area',       'label' => 'Primary service area',       'us' => 'North Shore & Northwest Chicago suburbs (Winnetka, Wilmette, Glenview, Arlington Heights, Palatine, Barrington, etc.)',
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
            'slug' => 'kitchen-bath-mart',
            'name' => 'Kitchen & Bath Mart',
            'website' => 'https://www.kitchenandbathmart.net',
            'location' => 'Niles and Palatine, IL',
            'focus' => 'Kitchen and bath remodeling',
            'comparison_note' => 'Kitchen & Bath Mart is a long-running kitchen and bath remodeling brand in the Northwest suburbs with locations in Niles and Palatine. GS Construction compares as an owner-led remodeling team where Greg and Patryk remain your direct point of contact, with flexible design options and transparent, itemized scope pricing.',
            'them' => [
                'project_types' => 'Kitchen and bathroom remodeling',
                'service_area' => 'Niles, Palatine, and surrounding Chicago suburbs',
            ],
            'also_known_as' => ['kitchen and bath mart', 'kitchen bath mart'],
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
        [
            'slug' => 'prestige-kitchen-bath',
            'name' => 'Prestige Kitchen & Bath',
            'website' => 'https://prestigekitchenbath.com',
            'location' => 'Arlington Heights, IL',
            'focus' => 'Showroom-based kitchen and bathroom design and remodeling',
            'comparison_note' => 'Prestige Kitchen & Bath is a family-owned, showroom-based kitchen and bath specialist in Arlington Heights with an in-house design team and free 3D kitchen drawings, celebrating 25 years. Its published scope is kitchens and bathrooms. GS Construction covers the full remodel spectrum — kitchens, baths, basements, additions, and whole-home — with the owners, Greg and Patryk, as your direct contact and typical project price ranges published on-site so you can budget before booking a visit.',
            'them' => [
                'project_types' => 'Kitchen and bathroom remodeling (showroom-led)',
            ],
            'also_known_as' => ['prestige kitchen and bath', 'prestige kitchen bath', 'prestige kitchen and bath arlington heights'],
        ],
        [
            'slug' => 'patrick-a-finn',
            'name' => 'Patrick A. Finn, Ltd.',
            'website' => 'https://www.patrickafinn.com',
            'location' => 'Palatine, IL',
            'focus' => 'Upscale design-build remodeling and custom homes',
            'comparison_note' => 'Patrick A. Finn is an award-winning Palatine design-build firm in business since 1991, with flagship work in the upscale whole-house segment; its process begins with a discovery phone call before an in-home meeting, and exact pricing follows a completed design. GS Construction publishes typical project ranges up front, offers a free in-home estimate with an itemized scope, and stays flexible — bring your own designer or your own materials and work directly with the owners throughout.',
            'them' => [
                'design_model' => 'In-house design-build; pricing follows a completed design agreement.',
            ],
            'also_known_as' => ['patrick finn', 'patrick a finn', 'patrick a finn remodeling'],
        ],
        [
            'slug' => 'advance-design-studio',
            'name' => 'Advance Design Studio',
            'website' => 'https://www.advancedesignstudio.com',
            'location' => 'Gilberts, IL',
            'focus' => 'Design-build remodeling with a showroom, serving the far-northwest suburbs',
            'comparison_note' => 'Advance Design Studio is an established design-build firm (since 1992) with a full-service showroom in Gilberts, centered on far-northwest towns like Barrington, Crystal Lake, and Algonquin. GS Construction is headquartered in Prospect Heights and works the Northwest suburbs and North Shore, with an owner-led model: Greg and Patryk run every project, pricing stays itemized and transparent, and you are free to bring your own designer or materials.',
            'them' => [
                'service_area' => 'Far-northwest suburbs: Barrington, Crystal Lake, Algonquin, and nearby towns',
            ],
            'also_known_as' => ['advance design', 'advance design studio gilberts', 'common sense remodeling'],
        ],
        [
            'slug' => 'regency-home-remodeling',
            'name' => 'Regency Home Remodeling',
            'website' => 'https://www.regencyhomeremodeling.com',
            'location' => 'North Chicago, IL',
            'focus' => 'Kitchen, bathroom, and countertop remodeling',
            'comparison_note' => 'Regency Home Remodeling is a kitchen-and-bath specialist known for its fixed-price "Regency Exact Price" quote and a large photo archive of completed projects across 50+ Chicago suburbs. Its published services center on kitchens, bathrooms, and countertops. GS Construction is a full general remodeler — additions, basements, and whole-home included — with itemized transparent pricing, published typical project ranges, and the owners supervising every job.',
            'them' => [
                'project_types' => 'Kitchen, bathroom, and countertop remodeling',
            ],
            'also_known_as' => ['regency remodeling', 'regency exact price', 'regency home remodeling chicago'],
        ],
        [
            'slug' => 'sunny-remodeling',
            'name' => 'Sunny Construction & Remodeling',
            'website' => 'https://sunnyremodeling.com',
            'location' => 'Schaumburg, IL',
            'focus' => 'Kitchen, bathroom, basement, and whole-house remodeling',
            'comparison_note' => 'Sunny Construction & Remodeling is a Schaumburg-based remodeler citing 15+ years in business and 750+ completed projects, serving 60+ North and Northwest suburbs. GS Construction serves much of the same territory with a father-son owner team as your single point of contact from first call to walkthrough, a live client portal for schedule, change orders, and balances, and typical project price ranges published openly on-site.',
            'them' => [
                'service_area' => '60+ North and Northwest Chicago suburbs',
            ],
            'also_known_as' => ['sunny remodeling', 'sunny construction', 'sunny construction and remodeling'],
        ],
        [
            'slug' => 'lamantia-design-remodeling',
            'name' => 'LaMantia Design & Remodeling',
            'website' => 'https://www.lamantia.com',
            'location' => 'Hinsdale, IL',
            'focus' => 'Design-build luxury remodeling with a showroom',
            'comparison_note' => 'LaMantia Design & Remodeling is a Hinsdale design-build firm celebrating 53 years, with a 6,000 sq ft showroom, in-house architects and designers, a 9-step process, and a 5-year construction warranty, serving mostly the western suburbs. GS Construction is rooted in the Northwest suburbs with a leaner owner-led model: work directly with Greg and Patryk, keep control of design and material choices, and see exactly what you pay for in an itemized scope.',
            'them' => [
                'design_model' => 'In-house architects and designers with a showroom-led 9-step process.',
                'service_area' => 'Hinsdale and the western Chicago suburbs',
            ],
            'also_known_as' => ['lamantia', 'la mantia remodeling', 'lamantia design and remodeling'],
        ],
        [
            'slug' => 'synergy-builders',
            'name' => 'Synergy Builders',
            'website' => 'https://www.synergyhomeremodel.com',
            'location' => 'West Chicago, IL',
            'focus' => 'Design-build remodeling with a showroom',
            'comparison_note' => 'Synergy Builders is a design-build firm founded in 2002 with a full-feature showroom in West Chicago and a five-stage Explore-to-Enjoy process, serving the west, northwest, and north suburbs roughly as far north as Highland Park. GS Construction\'s daily territory is the Northwest suburbs and North Shore, where the owners themselves run each project, estimates are free with an itemized scope, and typical project price ranges are published on-site.',
            'them' => [
                'service_area' => 'West, northwest, and north Chicago suburbs',
            ],
            'also_known_as' => ['synergy builders', 'synergy home builders', 'synergy home remodel'],
        ],
        [
            'slug' => 'senkus-build',
            'name' => 'Senkus Build',
            'website' => 'https://senkusbuild.com',
            'location' => 'Lake Zurich, IL',
            'focus' => 'Bathroom and kitchen remodeling',
            'comparison_note' => 'Senkus Build is a newer Lake Zurich remodeler — founded roughly five years ago per its site — focused on bathroom and kitchen work in far-northwest towns like Barrington, Crystal Lake, and McHenry. GS Construction, founded in 2015, brings 40+ years of combined hands-on experience, 5-star reviews across Google, Houzz, Yelp, and Angi, and full general-contractor scope including basements, additions, and whole-home remodels.',
            'them' => [
                'project_types' => 'Bathroom and kitchen remodeling',
                'service_area' => 'Lake Zurich, Barrington, Crystal Lake, McHenry, and nearby far-northwest towns',
            ],
            'also_known_as' => ['senkus build', 'senkusbuild', 'senkus construction'],
        ],
        [
            'slug' => 'assembly-squad-remodeling',
            'name' => 'Assembly Squad Remodeling',
            'website' => 'https://assemblyserviceil.com',
            'location' => 'Chicago, IL',
            'focus' => 'Design-build kitchen, bath, and condo remodeling',
            'comparison_note' => 'Assembly Squad Remodeling is a downtown-Chicago design-build contractor (since 2013) with a Lincoln Park design studio, specializing in city condo and high-rise work with HOA approvals in 300+ buildings. GS Construction is the suburban counterpart: headquartered in Prospect Heights, owner-led by a father-son team, and focused on single-family homes across the Northwest suburbs and North Shore.',
            'them' => [
                'service_area' => 'Chicago city neighborhoods, condos, and high-rises',
                'project_types' => 'Kitchen, bath, and condo/high-rise remodeling',
            ],
            'also_known_as' => ['assembly squad', 'assembly squad remodeling llc', 'assembly service il'],
        ],
        [
            'slug' => 'maya-construction-group',
            'name' => 'Maya Construction Group',
            'website' => 'https://mayaconstructioninc.com',
            'location' => 'Chicago, IL',
            'focus' => 'General contracting and home remodeling',
            'comparison_note' => 'Maya Construction Group is a Chicago-based general contractor in business since 1998, citing 700+ completed jobs across city neighborhoods and surrounding suburbs. Its identity and address are firmly in the city; GS Construction lives in the suburbs it serves — Prospect Heights headquarters, roughly 90 suburbs across Cook, Lake, and DuPage counties — with an owner-led team supervising every job, published typical project ranges, and a written workmanship warranty.',
            'them' => [
                'service_area' => 'Chicago city neighborhoods plus nearby suburbs',
            ],
            'also_known_as' => ['maya construction', 'maya construction group', 'maya construction chicago'],
        ],
        [
            'slug' => 'ecobuild-plus',
            'name' => 'EcoBuild Plus',
            'website' => 'https://ecobuildplus.com',
            'location' => 'Mount Prospect, IL',
            'focus' => 'Design-build remodeling, new construction, and commercial work',
            'comparison_note' => 'EcoBuild Plus is a Mount Prospect design-build generalist covering residential remodeling, new home construction, and commercial projects across Chicago and 24+ suburbs, with financing programs through partner banks. GS Construction focuses exclusively on residential remodeling in the same Northwest-suburb territory — kitchens, baths, basements, additions, and whole-home — with the owners, Greg and Patryk, personally running every project rather than a broader multi-segment construction operation.',
            'them' => [
                'project_types' => 'Residential remodeling, new construction, and commercial projects',
            ],
            'also_known_as' => ['ecobuild', 'eco build plus'],
        ],
        [
            'slug' => 'thomas-meyer-renovations',
            'name' => 'Thomas Meyer Renovations',
            'website' => 'https://thomasmeyerrenovations.com',
            'location' => 'Palatine, IL',
            'focus' => 'Countertops, tile, flooring, and kitchen/bath remodeling',
            'comparison_note' => 'Thomas Meyer Renovations is a Palatine-based countertop and tile specialist — a certified Caesarstone and Silestone fabricator — that also takes on kitchen, bath, and basement remodels by assigning and managing outside work crews. GS Construction is a full general remodeler whose long-standing, vetted trade partners are scheduled and supervised daily by the owners, covered by one GS warranty, with reviews cited across Google, Houzz, Yelp, and Angi.',
            'them' => [
                'project_types' => 'Countertops, tile, and flooring plus kitchen/bath/basement remodels',
            ],
            'also_known_as' => ['thomas meyer renovations', 'tom meyer renovations', 'thomas meyer remodeling'],
        ],
    ],
];
