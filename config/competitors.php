<?php

/**
 * Competitor comparison page configuration.
 *
 * Public, factual, SEO-safe comparison content for "alternative to / vs"
 * intent searches. Do not include defamatory or unverifiable claims.
 * Keep "competitor" entries to neutral facts (publicly visible categories,
 * service area, etc.) and let the "us" column carry the marketing.
 *
 * RULE, per counsel following the 2026-08 cease-and-desist:
 * the "them" column may state only facts quoted verbatim from that company's
 * own public site and recorded in `them_sources`. Anything else — including a
 * general industry caution such as "some firms add a labor markup" — reads as
 * an assertion about the named company once it renders under their heading,
 * and must stay as the neutral VARIES line below. Keep the page positive and
 * focused on us rather than on them.
 */

/** Neutral placeholder for any row we cannot support with a citation. */
$varies = 'Varies — verify directly with the company.';

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
    'last_verified' => '2026-07-16',

    /*
    |--------------------------------------------------------------------------
    | Universal Comparison Criteria
    |--------------------------------------------------------------------------
    | These are the rows shown in the comparison table on every per-competitor
    | page. The "us" value is filled from this file; the "them" value is
    | overridable per-competitor (defaults to "Varies — verify directly").
    | "why" (optional) is a short homeowner-facing note on why the row matters.
    */
    /*
    |--------------------------------------------------------------------------
    | Rows that may not be asserted without a citation
    |--------------------------------------------------------------------------
    | Counsel flagged these as the rows where an unsupported statement reads as
    | a claim AGAINST the named company rather than a neutral fact about them.
    | For these keys the "them" value is used only when `them_sources` carries a
    | verbatim quote from that company's own public site; otherwise the page
    | falls back to the neutral "Varies" line. Enforced in CompareCompetitorPage
    | rather than by hand-editing entries, so an uncited claim cannot reach the
    | page by being pasted back in later.
    |
    | Deliberately NOT listed: experience, service_area, project_types, permits,
    | estimate, licensed_insured, photo_proof. Those are neutral descriptive
    | facts ("in business since 1990"), not characterisations, and counsel did
    | not ask for them to be removed.
    */
    'requires_citation' => [
        'ownership',
        'point_of_contact',
        'design_model',
        'pricing',
        'self_perform',
        'communication',
        'public_reviews',
    ],

    'criteria' => [
        ['key' => 'ownership',          'label' => 'Ownership',                  'us' => 'Family-owned, father-son team (Greg & Patryk Szady)',
            'why' => 'Owner-operators are personally accountable for your project — not a sales rep who moves on after signing.'],
        ['key' => 'point_of_contact',   'label' => 'Your point of contact',      'us' => 'Greg & Patryk Szady — the owners — are your single point of contact from the first call to the final walkthrough',
            'them_default' => $varies,
            'why' => 'Every hand-off between coordinators is a chance for details to get lost and mistakes to creep in.'],
        ['key' => 'design_model',       'label' => 'Design approach',            'us' => 'We build your project and collaborate with the independent designer or architect you choose — or we can connect you with our trusted architects, engineers, or designers — or be your own designer: we send you to our trusted material sources, follow your requirements, and install the materials you purchase. Your design, your decisions — we are flexible',
            'them_default' => $varies,
            'why' => 'A flexible design model means you keep control of the look and the budget instead of being funneled into one in-house package.'],
        ['key' => 'pricing',            'label' => 'Pricing transparency',       'us' => 'Itemized, transparent pricing — labor is not marked up through layers of middlemen',
            'them_default' => $varies,
            'why' => 'An itemized scope lets you compare apples-to-apples and see exactly what you are paying for.'],
        ['key' => 'self_perform',       'label' => 'Who does the work',          'us' => 'Long-standing, vetted trade partners — licensed where required, insured, scheduled and supervised daily by the owners, covered by one GS warranty',
            'them_default' => $varies,
            'why' => 'Who actually holds the tools — and who supervises them — drives quality and accountability on site.'],
        ['key' => 'experience',         'label' => 'Combined experience',        'us' => '40+ years',
            'them_default' => 'Verify directly.'],
        ['key' => 'service_area',       'label' => 'Primary service area',       'us' => 'North Shore & Northwest Chicago suburbs (Winnetka, Wilmette, Glenview, Arlington Heights, Palatine, Barrington, etc.)',
            'why' => 'A contractor who works your area daily knows local permitting, inspectors, and supply houses.'],
        ['key' => 'project_types',      'label' => 'Project types',              'us' => 'Kitchen, bathroom, and whole-home remodeling, additions, exteriors, basements, and mudrooms'],
        ['key' => 'permits',            'label' => 'Permit handling',            'us' => 'We pull permits and coordinate inspections',
            'why' => 'Unpermitted work can stall a future home sale and void insurance — confirm who is responsible.'],
        ['key' => 'communication',      'label' => 'Project communication',      'us' => 'Daily — your private client portal to track your schedule (past and upcoming), current change orders, and up-to-date balances — plus a direct line to the owners and weekly progress updates',
            'them_default' => $varies,
            'why' => 'A live portal means you always know the schedule, what changed, and what you owe — no waiting for a callback.'],
        ['key' => 'photo_proof',        'label' => 'Photo proof',                'us' => 'Hundreds of in-progress and completed project photos on-site'],
        ['key' => 'public_reviews',     'label' => 'Public reviews',             'us' => 'Verified reviews on Google, Houzz, Yelp, and Angi',
            // Counsel's wording: point the reader at the company's own reviews
            // rather than characterising them for the reader.
            'them_default' => 'Varies — we advise reviewing the company\'s public reviews.',
            'why' => 'Reviews across multiple independent platforms are harder to game than testimonials on a company\'s own site.'],
        ['key' => 'estimate',           'label' => 'Estimates',                  'us' => 'Free in-home estimate with itemized scope'],
        ['key' => 'licensed_insured',   'label' => 'Licensed & insured',         'us' => 'Yes — GS Construction and every tradesman on your project is fully licensed, insured, bonded, and registered in each city and village we work in',
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
            'comparison_note' => 'GS Construction & Remodeling is an owner-led general remodeler: Greg and Patryk run the full project — kitchens, baths, basements, additions, and whole-home — pull the permits, and stay flexible whether you bring your own designer or shop your own materials. Pricing is itemized line by line, and every project ends with a written workmanship warranty.',
            'them' => [
                'project_types' => 'Kitchen and bathroom remodeling, laundry rooms, and built-in cabinets (showroom-led)',
                'service_area' => 'Northwest suburbs of Chicago',
                'experience' => 'In business since 1990',
            ],
            'also_known_as' => ['kitchenvillage', 'kitchen village chicago'],
        ],
        [
            'slug' => 'kitchen-bath-mart',
            'name' => 'Kitchen & Bath Mart',
            'website' => 'https://www.kitchenandbathmart.net',
            'location' => 'Niles and Palatine, IL',
            'focus' => 'Kitchen and bath remodeling',
            'comparison_note' => 'GS Construction & Remodeling is an owner-led team where Greg and Patryk stay your direct point of contact, with flexible design options — bring your own designer or materials — and transparent, itemized scope pricing. The work runs past kitchens and baths to basements, additions, exteriors, and whole-home remodels, so one accountable team can carry a house end to end.',
            'them' => [
                'project_types' => 'Kitchen and bathroom remodeling (showroom-led), plus cabinet refacing and laundry rooms',
                'service_area' => 'Greater Chicagoland, from Niles and Palatine showrooms',
                'experience' => 'In business since 1958',
                'design_model' => 'One-stop showroom design-build — in-house designers guide selections at their Niles and Palatine showrooms.',
            ],
            'also_known_as' => ['kitchen and bath mart', 'kitchen bath mart'],
        ],
        [
            'slug' => '4ever-remodeling',
            'name' => '4Ever Remodeling',
            'website' => 'https://4everremodeling.com',
            'location' => 'Chicago area',
            'focus' => 'Full-service remodeling',
            'comparison_note' => 'The GS Construction & Remodeling Advantage comes down to who you work with day to day: the owners, Greg and Patryk Szady, run your project from first call to final walkthrough instead of handing you between team roles, and labor pricing stays transparent with no middleman markup. You keep control of the design, every estimate is free and itemized, and a written workmanship warranty backs the finished work.',
            'them' => [
                'experience' => 'In business since 2011',
                'design_model' => 'In-house design-build: design studio selections and 3D renderings within a 7-step process.',
                'point_of_contact' => 'A dedicated project manager oversees the build; consultants and designers lead earlier phases.',
            ],
            // Per-claim citations, keyed by criteria key. Each quote is verbatim
            // from the competitor's own public site, captured 2026-07-29 and
            // documented in docs/legal/evidence-4ever-2026-07-29.md §3. Only add
            // an entry here once the quote has actually been verified on the
            // named page — a missing entry simply renders no citation mark.
            'them_sources' => [
                'experience' => [
                    'label' => 'About page',
                    'url' => 'https://4everremodeling.com/about/',
                    'quote' => 'Since 2011, 4Ever Remodeling has been a trusted name…',
                ],
                'design_model' => [
                    'label' => 'Homepage',
                    'url' => 'https://4everremodeling.com/',
                    'quote' => "With thousands of selections available at our design studio, it's easy to explore ideas and find the right fit for your style and budget",
                ],
                'point_of_contact' => [
                    'label' => 'Design-build process page',
                    'url' => 'https://4everremodeling.com/design-build-process/',
                    'quote' => 'Phase 5: Project Management — A dedicated project manager oversees your project, maintains consistent communication…',
                ],
            ],
            'also_known_as' => ['4ever remodeling', '4everremodeling', 'four ever remodeling'],
        ],
        [
            'slug' => 'airoom',
            'name' => 'Airoom',
            'website' => 'https://www.airoom.com',
            'location' => 'Chicago suburbs',
            'focus' => 'Design-build additions and remodels',
            'comparison_note' => 'The GS Construction & Remodeling Advantage is a leaner, owner-led approach: bring your own designer or architect (or be your own), buy your own materials from our trusted sources, and work directly with the owners on every phase. Labor is not marked up through layers of middlemen, and every estimate arrives itemized so the numbers can be checked line by line.',
            'them' => [
                'experience' => 'In business since 1958; claims 20,000+ projects',
                'design_model' => 'In-house design/build with its own architecture arm (Airoom Architects Corp).',
                'service_area' => 'Chicagoland, from Lincolnwood and Hinsdale locations',
                'project_types' => 'Kitchens, baths, additions, basements, custom homes, and condo/loft remodels',
            ],
            'also_known_as' => ['airoom architects', 'airoom builders'],
        ],
        [
            'slug' => 'normandy-remodeling',
            'name' => 'Normandy Remodeling',
            'website' => 'https://www.normandyremodeling.com',
            'location' => 'Chicago suburbs',
            'focus' => 'Design-build remodeling',
            'comparison_note' => 'With GS Construction & Remodeling you keep more control: collaborate with the independent designer or architect you choose, follow live schedule, change-order, and balance updates in our client portal, and work directly with Greg and Patryk on every phase. We pull the permits and coordinate inspections, so approvals never become your second job.',
            'them' => [
                'experience' => 'In business since 1979',
                'design_model' => 'In-house interior design, architecture, and construction under one roof; designs to a target budget.',
                'point_of_contact' => 'A designer leads the design phase; a dedicated Project Superintendent runs construction.',
                'service_area' => 'Chicagoland, from Hinsdale and Evanston design studios',
            ],
            'also_known_as' => ['normandy builders', 'normandy design'],
        ],
        [
            'slug' => '123-remodeling',
            'name' => '123 Remodeling',
            'website' => 'https://123remodeling.com',
            'location' => 'Chicago, IL (offices in Chicago and Northfield)',
            'focus' => 'Design-build kitchen, bathroom, condo, and basement remodeling',
            'comparison_note' => 'GS Construction & Remodeling serves the Northwest suburbs with an owner-led crew and stays flexible on design — whether you want a designer, want to design it yourself, or want to supply your own materials. The owners are your single point of contact from first call to final walkthrough, and itemized pricing means labor is never marked up through middlemen.',
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
            'comparison_note' => 'GS Construction & Remodeling is a Northwest-suburbs remodeling specialist — kitchens, baths, whole-home, additions, basements, and exteriors — with itemized, transparent pricing and the owners on site. Greg and Patryk personally schedule and supervise the vetted trade partners on your job, all of it under one GS warranty rather than split across separate contracts.',
            'them' => [
                'service_area' => '30+ communities across Illinois, Southern Wisconsin, and Harbor Country Michigan',
                'project_types' => 'Luxury custom homes plus remodeling, additions, and custom cabinetry',
                'design_model' => 'In-house design/build — their architects and cabinetry team handle the full process, via the named "Pickell Proven Process".',
                'pricing' => '"Open Book Pricing" — competitively bid trades with the margin disclosed.',
                'experience' => 'Cites 40 years of experience',
            ],
            'also_known_as' => ['orren pickell', 'pickell building group', 'pickell builders'],
        ],
        [
            'slug' => 'skor-construction',
            'name' => 'Skor Construction',
            'website' => 'https://skorconstruction.com',
            'location' => 'Palatine, IL',
            'focus' => 'Design-build remodeling, additions, kitchens, baths, and basements',
            'comparison_note' => 'GS Construction & Remodeling runs a live client portal and keeps the model flexible: bring your own designer or architect, supply your own materials, and talk directly to owners Greg and Patryk throughout the build. The portal carries your schedule, current change orders, and an up-to-date balance, so you are never waiting on a callback to know where things stand.',
            'them' => [
                'service_area' => "Chicago's North Shore and Northwest suburbs",
                'design_model' => 'In-house 5-step design-build: proposal, design schematics, and architectural plans when required.',
                'experience' => 'In business since 2009',
                'communication' => 'Project-management software for budget, material selections, and centralized communication.',
            ],
            'also_known_as' => ['skor construction design build', 'build with skor'],
        ],
        [
            'slug' => 'chi-renovation',
            'name' => 'Chi Renovation and Design',
            'website' => 'https://www.chirenovation.com',
            'location' => 'Chicago, IL',
            'focus' => 'Design-build interior remodeling and architectural design',
            'comparison_note' => 'GS Construction & Remodeling concentrates on the Northwest suburbs and North Shore and lets you choose how design happens — your designer, our recommendations, or your own plans — with the owners themselves as your contact and clear, itemized pricing. Because Greg and Patryk carry the project from estimate to walkthrough, the person who quoted your job is the person supervising it.',
            'them' => [
                'service_area' => 'Chicago; describes itself as a Chicago design-build studio',
                'design_model' => 'In-house design-build (6-step process) with an architect and engineer on retainer and certified interior design staff.',
                'point_of_contact' => 'Clients are paired with a designer plus a project manager.',
            ],
            'also_known_as' => ['chi renovation', 'chirenovation', 'chi ren'],
        ],
        [
            'slug' => 'ohi-remodeling',
            'name' => 'OHi (Our Home Improvement)',
            'website' => 'https://www.contactohi.com',
            'location' => 'Elk Grove Village, IL',
            'focus' => 'Design-build kitchen, bath, basement, and additions with an in-house showroom',
            'comparison_note' => 'The GS Construction & Remodeling Advantage is simplicity: the owners, Greg and Patryk, are your single point of contact from first call to final walkthrough, estimates are free and itemized, and you are free to bring your own designer or buy your own materials. There is no design retainer to start a conversation and no middlemen marking up labor.',
            'them' => [
                'service_area' => 'Northwest suburbs: Arlington Heights, Barrington, Buffalo Grove, Deerfield, Elk Grove Village, and nearby towns',
                'design_model' => 'In-house designers and a 1,500 sq ft showroom; a design retainer (5% of the high-end ballpark budget) begins formal plans.',
                'point_of_contact' => 'A designer leads early phases; a project manager becomes your main contact during construction.',
                'pricing' => 'Design retainer, then 30% deposit at sign-off; publishes investment guides; financing up to $100,000 via Acorn Finance.',
            ],
            'also_known_as' => ['ohi', 'our home improvement', 'contact ohi'],
        ],
        [
            'slug' => 'modern-builders-design',
            'name' => 'Modern Builders & Design',
            'website' => 'https://www.modernbuildersdesign.com',
            'location' => 'Round Lake, IL',
            'focus' => 'General contractor offering remodeling, painting, and epoxy flooring',
            'comparison_note' => 'GS Construction & Remodeling focuses specifically on remodeling craftsmanship — kitchens, baths, whole-home, additions, basements, and exteriors — across the Northwest suburbs, with 40+ years of combined experience and the owners running every project. Trade partners are long-standing and vetted, insured, and supervised daily by Greg and Patryk under one GS warranty.',
            'them' => [
                'service_area' => 'Barrington, South Barrington, Round Lake, Crystal Lake, Waukegan, and surrounding suburbs',
                'project_types' => 'Kitchen, bathroom, and basement remodeling plus painting, epoxy floor coatings, and flooring',
                'point_of_contact' => 'Project managers coordinate the contractors performing the work.',
            ],
            'also_known_as' => ['modern builders and design', 'modern builders design'],
        ],
        [
            'slug' => 'prestige-kitchen-bath',
            'name' => 'Prestige Kitchen & Bath',
            'website' => 'https://prestigekitchenbath.com',
            'location' => 'Arlington Heights, IL',
            'focus' => 'Showroom-based kitchen and bathroom design and remodeling',
            'comparison_note' => 'GS Construction & Remodeling covers the full remodel spectrum — kitchens, baths, basements, additions, and whole-home — with the owners, Greg and Patryk, as your direct contact and typical project price ranges published on-site so you can budget before booking a visit. Design stays flexible: your designer, one of our trusted partners, or your own selections installed by us.',
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
            'comparison_note' => 'GS Construction & Remodeling publishes typical project ranges up front, offers a free in-home estimate with an itemized scope, and stays flexible — bring your own designer or your own materials and work directly with the owners throughout. You do not need a finished design before you can get real numbers to plan around.',
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
            'comparison_note' => 'GS Construction & Remodeling is headquartered in Prospect Heights and works the Northwest suburbs and North Shore, with an owner-led model: Greg and Patryk run every project, pricing stays itemized, and you are free to bring your own designer or materials. Working the same towns daily means knowing the local permit offices and inspectors rather than learning them job by job.',
            'them' => [
                'service_area' => 'Barrington, Crystal Lake, and nearby towns',
            ],
            'also_known_as' => ['advance design', 'advance design studio gilberts', 'common sense remodeling'],
        ],
        [
            'slug' => 'regency-home-remodeling',
            'name' => 'Regency Home Remodeling',
            'website' => 'https://www.regencyhomeremodeling.com',
            'location' => 'North Chicago, IL',
            'focus' => 'Kitchen, bathroom, and countertop remodeling',
            'comparison_note' => 'GS Construction & Remodeling is a full general remodeler — additions, basements, and whole-home included — with itemized transparent pricing, published typical project ranges, and the owners supervising every job. An itemized scope lets you compare estimates line by line instead of weighing one lump sum against another.',
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
            'comparison_note' => 'GS Construction & Remodeling gives you a father-son owner team as your single point of contact from first call to walkthrough, a live client portal for schedule, change orders, and balances, and typical project price ranges published openly on-site. Greg and Patryk schedule and supervise vetted trade partners daily, all under one GS warranty.',
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
            'comparison_note' => 'GS Construction & Remodeling is rooted in the Northwest suburbs with a leaner owner-led model: work directly with Greg and Patryk, keep control of design and material choices, and see exactly what you pay for in an itemized scope. Kitchens, baths, basements, additions, exteriors, and whole-home remodels all sit with one accountable team under a single GS warranty.',
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
            'comparison_note' => 'GS Construction & Remodeling\'s daily territory is the Northwest suburbs and North Shore, where the owners themselves run each project, estimates are free with an itemized scope, and typical project price ranges are published on-site. Design can come from your own architect, one of our trusted partners, or your own selections that we install.',
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
            'comparison_note' => 'GS Construction & Remodeling, founded in 2015, brings 40+ years of combined hands-on experience, 5-star reviews across Google, Houzz, Yelp, and Angi, and full general-contractor scope including basements, additions, and whole-home remodels. Greg and Patryk are your single point of contact from the first call to the final walkthrough.',
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
            'comparison_note' => 'GS Construction & Remodeling is headquartered in Prospect Heights, owner-led by a father-son team, and focused on single-family homes across the Northwest suburbs and North Shore. Kitchens, baths, basements, additions, exteriors, and whole-home remodels are handled by the owners rather than passed between coordinators, with free itemized estimates throughout.',
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
            'comparison_note' => 'GS Construction & Remodeling lives in the suburbs it serves — Prospect Heights headquarters, serving communities across Cook, Lake, and DuPage counties — with an owner-led team supervising every job, published typical project ranges, and a written workmanship warranty. Working these towns daily means knowing the local permit offices and inspectors first-hand.',
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
            'comparison_note' => 'GS Construction & Remodeling focuses exclusively on residential remodeling across the Northwest suburbs — kitchens, baths, basements, additions, and whole-home — with the owners, Greg and Patryk, personally running every project. That focus means the crews on your house do this work every week rather than rotating between unrelated project types.',
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
            'comparison_note' => 'GS Construction & Remodeling is a full general remodeler whose long-standing, vetted trade partners are scheduled and supervised daily by the owners, covered by one GS warranty, with reviews cited across Google, Houzz, Yelp, and Angi. One accountable team carries the job from demolition to final walkthrough.',
            'them' => [
                'project_types' => 'Countertops, tile, and flooring plus kitchen/bath/basement remodels',
            ],
            'also_known_as' => ['thomas meyer renovations', 'tom meyer renovations', 'thomas meyer remodeling'],
        ],
        [
            'slug' => 'delta-remodels',
            'name' => 'Delta Remodels',
            'website' => 'https://www.deltaremodels.com',
            'location' => 'Lake Forest, IL',
            'focus' => 'Kitchen, bathroom, and basement remodeling',
            'comparison_note' => 'The GS Construction & Remodeling Advantage comes down to design freedom and who runs your project: bring your own designer or architect — or use one of our trusted partners — and the owners, Greg and Patryk Szady, personally run every job from the first call to the final walkthrough. Pricing arrives itemized, with labor never marked up through middlemen.',
            'them' => [
                'experience' => 'Family-owned, in business since 1987',
                'design_model' => 'Design-build with an in-house designer; 3D renderings included.',
                'point_of_contact' => 'A dedicated project manager is assigned to each project.',
                'service_area' => 'North Shore communities (Lake Forest, Highland Park, Winnetka, Glenview, Northbrook, etc.)',
                'project_types' => 'Kitchen, bathroom, basement, and accessible remodeling.',
            ],
            // Verified 2026-07-29 on deltaremodels.com (see them_sources quotes).
            'them_sources' => [
                'experience' => [
                    'label' => 'Homepage',
                    'url' => 'https://www.deltaremodels.com/',
                    'quote' => 'Family-Owned Since 1987',
                ],
                'point_of_contact' => [
                    'label' => 'Homepage',
                    'url' => 'https://www.deltaremodels.com/',
                    'quote' => 'dedicated project manager',
                ],
                'design_model' => [
                    'label' => 'Homepage',
                    'url' => 'https://www.deltaremodels.com/',
                    'quote' => 'professional 3D renderings',
                ],
            ],
            'also_known_as' => ['delta remodels', 'delta construction', 'delta remodeling', 'deltaremodels'],
        ],
        [
            'slug' => 'dream-kitchens',
            'name' => 'Dream Kitchens',
            'website' => 'https://dreamkitchens.com',
            'location' => 'Highland Park, IL',
            'focus' => 'Kitchen and bath design',
            'comparison_note' => 'GS Construction & Remodeling approaches these projects from the build side: a licensed and insured construction company run by its owners, where you keep control of the design and one accountable team takes the job from demolition to final walkthrough. Every tradesman on your project is licensed, insured, bonded, and registered in the municipality where the work happens.',
            'them' => [
                'experience' => 'In business since 1992',
                'design_model' => 'Showroom-based design firm: in-house designers, 3D mockups, and detailed drawings; installation by the firm or your own contractor.',
                'service_area' => 'Highland Park and surrounding communities',
                'project_types' => 'Kitchen and bathroom design, including outdoor and kosher kitchens.',
            ],
            // Verified 2026-07-29 on dreamkitchens.com (see them_sources quotes).
            'them_sources' => [
                'experience' => [
                    'label' => 'Homepage',
                    'url' => 'https://dreamkitchens.com/',
                    'quote' => 'Since 1992 we have been transforming our clients\' kitchens into their unique culinary space.',
                ],
                'design_model' => [
                    'label' => 'Homepage',
                    'url' => 'https://dreamkitchens.com/',
                    'quote' => 'carpenter friendly detailed drawings',
                ],
            ],
            'also_known_as' => ['dream kitchens', 'dream kitchens inc', 'dreamkitchens'],
        ],
        [
            'slug' => 'scott-lyon-company',
            'name' => 'Scott Lyon & Company',
            'website' => 'https://www.scottlyonconstruction.com',
            'location' => 'Glencoe, IL',
            'focus' => 'Residential construction',
            // Their live site has shown an "under construction" placeholder
            // since ~2015, so sourced rows quote Internet Archive copies of
            // their real site (2012-2014 captures) and say so inline; the
            // citation tooltips link the exact snapshots. project_types quotes
            // their current Facebook description. Verified 2026-07-30.
            'comparison_note' => 'The most reliable way to compare any two contractors is to request an itemized estimate from each. GS Construction & Remodeling\'s is free, itemized, and walked through with you by the owners themselves, so every line — demolition, materials, labor, permits, finishes — is accounted for before you commit, then Greg and Patryk run the project personally.',
            'them' => [
                'project_types' => 'High-end residential construction and commercial contracting.',
                'point_of_contact' => 'Per their archived site (2014): acts as a representative of the client, with decisions made as a team.',
                'design_model' => 'Per their archived site (2014): a menu of options for the design and detail of each project, discussed personally.',
                'self_perform' => 'Per their archived site (2014): skilled tradesmen through strong relationships.',
            ],
            'them_sources' => [
                'project_types' => [
                    'label' => 'Archived about page (Apr 2014)',
                    'url' => 'https://web.archive.org/web/20140409064545/http://www.scottlyonconstruction.com/about/',
                    'quote' => 'High-end residential construction and commercial contracting.',
                ],
                'point_of_contact' => [
                    'label' => 'Archived about page (Apr 2014)',
                    'url' => 'https://web.archive.org/web/20140409064545/http://www.scottlyonconstruction.com/about/',
                    'quote' => 'we consider our role as a representative of our client, with all decisions being made as a team',
                ],
                'design_model' => [
                    'label' => 'Archived services page (Apr 2014)',
                    'url' => 'https://web.archive.org/web/20140409005158/http://www.scottlyonconstruction.com/services/',
                    'quote' => 'SLC offers a menu of options with regard to the design and detail of each individual project.',
                ],
                'self_perform' => [
                    'label' => 'Archived services page (Apr 2014)',
                    'url' => 'https://web.archive.org/web/20140409005158/http://www.scottlyonconstruction.com/services/',
                    'quote' => 'our strong relationships with highly skilled tradesmen',
                ],
            ],
            'also_known_as' => ['scott lyon', 'scott lyon and company', 'scott lyon construction', 'scott lyon & co'],
        ],
    ],
];
