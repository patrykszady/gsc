<?php

/**
 * The guide pages `php artisan content:build-guides` writes for gs.construction
 * (/remodeling/{slug}, LandingPage template "guide") — one page per query
 * cluster from the 2026-11 keyword research (DataForSEO volumes + Search
 * Console; the top 250 of 619 opportunities). These are the searches the site
 * could win that are NOT town searches: the town pages stay on the
 * service_modifier_city template.
 *
 * Per cluster:
 *   slug         the page's /remodeling/{slug}
 *   service      the service page it belongs to (TitleMetaGenerator::SERVICES)
 *   modifier     optional, stored on the row like the city template's
 *   head_query   the one query the page targets; the rest are close variants
 *   queries      query => monthly searches (US, DataForSEO)
 *   angle        one-line brief for the writer — direction, never facts
 *   proof_types  project types that back the page, in priority order
 *   proof_match  optional word a project's title/description must contain to lead the proof
 *   extra_services  other service pages whose facts the writer also gets (the
 *                whole-house pages talk about every room)
 *   cost_guides  /costs pages whose published figures the writer may use
 *   facts        optional extra fact blocks: permits (town permit guides +
 *                adopted codes), hiring (the how-to-choose checklist)
 *
 * Lives under config/sites/gsc/ because every line of it is GS Construction's
 * own content plan; another tenant adds its own file or has no guides.
 */
return [

    'clusters' => [

        [
            'slug' => 'small-bathroom-remodels',
            'service' => 'bathroom-remodeling',
            'modifier' => 'small-space',
            'head_query' => 'bathroom remodels for small bathrooms',
            'queries' => [
                'bathroom remodels for small bathrooms' => 14800,
                'bathroom remodeling ideas for small bathrooms' => 12100,
                'small bathroom remodel ideas' => 880,
            ],
            'angle' => 'What actually works in a small bathroom: layout choices that keep the plumbing where it is, a walk-in shower instead of a tub, storage, tile scale, ventilation — and what a hall-bath-sized remodel costs and takes here.',
            'proof_types' => ['bathroom'],
            'cost_guides' => ['bathroom-remodel-cost'],
        ],

        [
            'slug' => 'kitchen-and-bathroom-remodels',
            'service' => 'kitchen-remodeling',
            'head_query' => 'kitchen & bathroom remodels',
            'queries' => [
                'kitchen & bathroom remodels' => 9900,
                'kitchen and bathroom remodelers near me' => 2900,
                'kitchen and bath remodeling contractors' => 1600,
                'kitchen and bathroom remodeling' => 320,
                'kitchen and bath remodeling services' => 320,
                'kitchen and bathroom remodeling services' => 320,
                'kitchen and bathroom remodeling service' => 260,
                'kitchen and bathroom remodeling companies near me' => 140,
            ],
            'angle' => 'Doing the kitchen and a bathroom as one project: the order the work happens in, one contract and one schedule, shared trades, what each room costs on its own, and how the combined scope is priced line by line.',
            'proof_types' => ['kitchen', 'bathroom'],
            'cost_guides' => ['kitchen-remodel-cost', 'bathroom-remodel-cost'],
            'facts' => ['hiring'],
        ],

        [
            'slug' => 'kitchen-remodel-contractors',
            'service' => 'kitchen-remodeling',
            'head_query' => 'remodel kitchen contractors near me',
            'queries' => [
                'remodel kitchen contractors near me' => 6600,
                'kitchen makeover contractors' => 170,
                'interior designer for kitchen remodel' => 70,
            ],
            'angle' => 'How to pick a kitchen remodel contractor in the Chicago northwest suburbs: who is on site every day, an itemized estimate, who pulls the permits, working with your own designer, what the kitchen scope includes, and what GS Construction kitchens cost and take.',
            'proof_types' => ['kitchen'],
            'cost_guides' => ['kitchen-remodel-cost'],
            'facts' => ['hiring'],
        ],

        [
            'slug' => 'bathroom-remodel-permit',
            'service' => 'bathroom-remodeling',
            'head_query' => 'bathroom remodel permit',
            'queries' => [
                'bathroom remodel permit' => 3600,
                'bathroom remodel permits' => 3600,
                'bathroom renovation permit' => 3600,
                'bathroom remodel permit required' => 480,
                'building permit for bathroom remodel' => 90,
            ],
            'angle' => 'When a bathroom remodel needs a permit in the Chicago suburbs, which work triggers one (plumbing, electrical, moving fixtures, walls), what the villages review and inspect, contractor registration, how long review takes, and how GS Construction handles all of it.',
            'proof_types' => ['bathroom'],
            'cost_guides' => ['bathroom-remodel-cost'],
            'facts' => ['permits'],
        ],

        [
            'slug' => 'kitchen-remodel-permit',
            'service' => 'kitchen-remodeling',
            'head_query' => 'kitchen remodel permit',
            'queries' => [
                'kitchen remodel permit' => 1000,
                'kitchen remodel permits' => 1000,
                'kitchen remodeling permit' => 1000,
                'kitchen renovation permit' => 1000,
                'permit for kitchen remodel' => 1000,
                'permits for kitchen remodel' => 1000,
                'do you need a permit to remodel a kitchen' => 320,
                'do you need a permit to remodel kitchen' => 320,
                'do you need a permit to renovate a kitchen' => 320,
                'do you need permits to remodel a kitchen' => 320,
                'do i need a permit to remodel my kitchen' => 170,
                'do i need a permit to renovate my kitchen' => 170,
                'do i need permits to remodel my kitchen' => 170,
                'kitchen remodel permit required' => 170,
                'kitchen remodel permit requirements' => 170,
                'do i need a permit for a kitchen remodel' => 140,
                'do i need a permit for kitchen remodel' => 140,
                'do i need a permit to remodel a kitchen' => 140,
                'do i need a permit to remodel kitchen' => 140,
                'do i need permit to remodel kitchen' => 140,
                'does a kitchen remodel require a permit' => 140,
                'does kitchen remodel require permit' => 140,
                'kitchen remodel do i need a permit' => 140,
            ],
            'angle' => 'Do you need a permit to remodel a kitchen: the work that triggers one (plumbing, gas, electrical circuits, load-bearing walls), what the villages review and inspect, the timing, and how GS Construction handles it on every kitchen.',
            'proof_types' => ['kitchen'],
            'cost_guides' => ['kitchen-remodel-cost'],
            'facts' => ['permits'],
        ],

        [
            'slug' => 'remodel-services',
            'service' => 'home-remodeling',
            'extra_services' => ['kitchen-remodeling', 'bathroom-remodeling', 'basement-remodeling', 'home-additions', 'mudroom-remodeling', 'exterior-siding'],
            'head_query' => 'remodel services',
            'queries' => [
                'remodel services' => 4400,
                'home remodeling services near me' => 880,
                'home remodeling service near me' => 880,
                'home remodel services near me' => 880,
                'home remodeling in my area' => 210,
            ],
            'angle' => 'What a full-service remodeler\'s services include — kitchens, bathrooms, basements, additions, whole-home, mudrooms, exteriors — how one contractor runs all of them under one contract, and the published ranges for each.',
            'proof_types' => ['home-remodel', 'kitchen', 'bathroom'],
            'cost_guides' => ['kitchen-remodel-cost', 'bathroom-remodel-cost', 'basement-finishing-cost', 'home-addition-cost'],
            'facts' => ['hiring'],
        ],

        [
            'slug' => 'second-story-addition-contractor',
            'service' => 'home-additions',
            'head_query' => 'second story addition contractor',
            'queries' => [
                'second story addition contractor' => 2900,
                'second floor addition contractors' => 1300,
            ],
            'angle' => 'Choosing a contractor for a second-story or second-floor addition: whether the house can carry it, zoning and sealed drawings, the roof and envelope tie-in, mechanical capacity, what additions cost per square foot, and how long they take.',
            'proof_types' => ['home-remodel'],
            'cost_guides' => ['home-addition-cost'],
            'facts' => ['permits', 'hiring'],
        ],

        [
            'slug' => 'luxury-home-remodeling',
            'service' => 'home-remodeling',
            'modifier' => 'luxury',
            'head_query' => 'luxury home remodeling',
            'queries' => [
                'luxury home remodeling' => 2400,
                'luxurious remodeling' => 480,
                'luxury remodeling' => 480,
                'luxury renovations' => 210,
                'high end remodeling' => 110,
            ],
            'angle' => 'What luxury home remodeling means in practice — custom cabinetry, stone and porcelain slab, custom showers and glass, structural openings — the custom and high-end cost tiers the site publishes, and how the work is supervised.',
            'proof_types' => ['home-remodel', 'kitchen'],
            'cost_guides' => ['kitchen-remodel-cost', 'bathroom-remodel-cost'],
        ],

        [
            'slug' => 'full-house-renovation',
            'service' => 'home-remodeling',
            'extra_services' => ['kitchen-remodeling', 'bathroom-remodeling', 'basement-remodeling', 'home-additions', 'mudroom-remodeling', 'exterior-siding'],
            'head_query' => 'full house renovations',
            'queries' => [
                'full house renovations' => 1900,
                'house remodeling' => 2400,
                'remodeling a home' => 590,
                'full house renovation contractors' => 170,
                'full gut renovation' => 140,
            ],
            'angle' => 'Full house renovation: sequencing a multi-room or gut renovation as one job, what is included, phased or all at once, whether you can stay in the house, and the published room-by-room ranges.',
            'proof_types' => ['home-remodel'],
            'cost_guides' => ['kitchen-remodel-cost', 'bathroom-remodel-cost', 'basement-finishing-cost', 'home-addition-cost'],
        ],

        [
            'slug' => 'condo-remodeling-contractors',
            'service' => 'home-remodeling',
            'modifier' => 'condo',
            'head_query' => 'condo remodeling contractors',
            'queries' => [
                'condo remodeling contractors' => 1300,
                'condo renovation contractor' => 1300,
                'condo renovation' => 390,
                'renovated condo' => 390,
                'can you remodel a condo' => 320,
                'can you renovate condos' => 320,
                'condo remodels' => 110,
                'kitchen renovation condo' => 140,
                'kitchen condo renovations' => 140,
                'condo kitchen renovations' => 90,
                'condo renovation cost' => 90,
                'condo renovation price' => 90,
                'condo remodeling cost' => 90,
                'condo remodeling costs' => 90,
                'condo remodel costs' => 90,
                'apartment remodel contractors' => 480,
                'apartment renovation contractor' => 480,
                'apartment remodeling contractor' => 480,
                'apartment remodeling contractors' => 480,
                'apartment renovation companies' => 110,
            ],
            'angle' => 'Remodeling a condo or apartment: what a condo kitchen or bathroom costs using the published room ranges, shared walls and plumbing stacks, scheduling around a building, and the Chicago condo renovation GS Construction completed.',
            'proof_types' => ['home-remodel', 'kitchen'],
            'proof_match' => 'condo',
            'cost_guides' => ['kitchen-remodel-cost', 'bathroom-remodel-cost'],
            'facts' => ['hiring'],
        ],

        [
            'slug' => 'basement-finishing-contractors',
            'service' => 'basement-remodeling',
            'head_query' => 'basement finishing contractors',
            'queries' => [
                'basement finishing contractors' => 1600,
                'basement renovation company' => 880,
                'basement renovations company' => 880,
                'basement addition contractor' => 590,
                'basement bar contractors' => 210,
            ],
            'angle' => 'Choosing a basement finishing contractor: moisture and egress sorted before framing, permits and inspections, bathrooms and wet bars below grade, what a finished basement costs and how long it takes.',
            'proof_types' => ['home-remodel'],
            'cost_guides' => ['basement-finishing-cost'],
            'facts' => ['permits', 'hiring'],
        ],

        [
            'slug' => 'basement-remodel-with-bathroom-cost',
            'service' => 'basement-remodeling',
            'head_query' => 'cost of basement remodel with bathroom',
            'queries' => [
                'cost of basement remodel with bathroom' => 880,
            ],
            'angle' => 'What a basement remodel with a bathroom costs: the full-lower-level tier against the standard build-out, the ejector pump and below-grade plumbing, egress when a bedroom is added, and what is quoted separately.',
            'proof_types' => ['home-remodel', 'bathroom'],
            'cost_guides' => ['basement-finishing-cost', 'bathroom-remodel-cost'],
        ],

        [
            'slug' => 'walk-in-shower-remodel-cost',
            'service' => 'bathroom-remodeling',
            'head_query' => 'walk-in shower remodel cost',
            'queries' => [
                'walk-in shower remodel cost' => 880,
                'walk-in shower renovation cost' => 880,
                'walk in shower renovation cost' => 880,
                'walk in shower remodel cost' => 880,
                'shower renovation' => 480,
                'shower remodel' => 480,
                'shower remodeling contractors' => 90,
            ],
            'angle' => 'What a walk-in shower remodel costs: the shower system as the priciest single element of a bathroom, waterproofing, tile scope, moving fixtures, frameless glass, curbless entries, the published bathroom tiers, and the timeline.',
            'proof_types' => ['bathroom'],
            'cost_guides' => ['bathroom-remodel-cost'],
        ],

        [
            'slug' => 'ikea-kitchen-remodel-cost',
            'service' => 'kitchen-remodeling',
            'head_query' => 'cost of an ikea kitchen remodel',
            'queries' => [
                'cost of an ikea kitchen remodel' => 880,
                'cost to renovate kitchen ikea' => 880,
                'ikea kitchen renovation cost' => 880,
                'how much does an ikea kitchen remodel cost' => 210,
                'ikea kitchen remodel contractors' => 390,
                'ikea kitchen installers near me' => 140,
            ],
            'angle' => 'What an IKEA kitchen remodel costs when a contractor installs it: where IKEA cabinet systems sit in the published kitchen tiers, what the installation includes, countertops templated after the cabinets, layout changes, and the timeline.',
            'proof_types' => ['kitchen'],
            'cost_guides' => ['kitchen-remodel-cost'],
        ],

        [
            'slug' => 'laundry-room-remodel-cost',
            'service' => 'mudroom-remodeling',
            'head_query' => 'laundry room remodel cost',
            'queries' => [
                'laundry room remodel cost' => 720,
                'remodel laundry room cost' => 720,
                'laundry room renovation cost' => 720,
                'laundry room remodeling cost' => 720,
                'cost to renovate laundry room' => 720,
            ],
            'angle' => 'What a laundry room or combined mudroom-laundry remodel costs: the published mudroom range, built-ins and folding counters, plumbing and venting relocation, durable flooring, and how long the job takes.',
            'proof_types' => ['mudroom'],
            'cost_guides' => [],
        ],

        [
            'slug' => 'bathroom-remodel-ideas',
            'service' => 'bathroom-remodeling',
            'head_query' => 'bathroom remodel ideas',
            'queries' => [
                'bathroom remodel ideas' => 1600,
                'remodel bathroom designs' => 1600,
                'vintage bathroom renovation' => 110,
            ],
            'angle' => 'Bathroom remodel ideas that hold up: custom showers, tile and stone, vanities, heated floors, accessibility, ventilation — each tied to what it adds to the budget and to real GS Construction bathrooms.',
            'proof_types' => ['bathroom'],
            'cost_guides' => ['bathroom-remodel-cost'],
        ],

        [
            'slug' => 'kitchen-remodel-ideas',
            'service' => 'kitchen-remodeling',
            'head_query' => 'kitchen remodel ideas',
            'queries' => [
                'kitchen remodel ideas' => 720,
                'remodeling a kitchen' => 590,
                'kitchen remodelling' => 480,
                'ideas for a galley kitchen remodel' => 320,
                'galley kitchen remodel cost' => 140,
                'renovating a small kitchen' => 140,
                'contemporary kitchen remodel' => 90,
                'contemporary kitchen renovation' => 90,
            ],
            'angle' => 'Kitchen remodel ideas and layouts — galley and small kitchens, islands, wall removals, lighting layers, cabinet grades, countertops — each tied to the published cost drivers and to real GS Construction kitchens.',
            'proof_types' => ['kitchen'],
            'cost_guides' => ['kitchen-remodel-cost'],
        ],

        [
            'slug' => 'gut-renovation-cost',
            'service' => 'home-remodeling',
            'extra_services' => ['kitchen-remodeling', 'bathroom-remodeling', 'basement-remodeling', 'home-additions', 'mudroom-remodeling', 'exterior-siding'],
            'head_query' => 'how much does it cost to gut renovate a house',
            'queries' => [
                'cost gut renovate house' => 260,
                'how much does it cost to gut renovate a house' => 210,
                'full gut renovation cost per square foot' => 140,
                'how much to gut and remodel a house' => 140,
                'how much to gut renovate a house' => 140,
                'cost to gut and remodel $1500 sq ft house' => 90,
            ],
            'angle' => 'How much a gut renovation costs: built from the published room ranges (kitchen, bathroom, basement, addition per square foot), what drives each, what is quoted separately, and why an itemized estimate is the only honest total.',
            'proof_types' => ['home-remodel'],
            'cost_guides' => ['kitchen-remodel-cost', 'bathroom-remodel-cost', 'basement-finishing-cost', 'home-addition-cost'],
        ],

        [
            'slug' => 'how-long-does-it-take-to-renovate-a-house',
            'service' => 'home-remodeling',
            'extra_services' => ['kitchen-remodeling', 'bathroom-remodeling', 'basement-remodeling', 'home-additions', 'mudroom-remodeling', 'exterior-siding'],
            'head_query' => 'how long does it take to renovate a house',
            'queries' => [
                'how long does it take to renovate a house' => 260,
                'how long does it take to remodel a house' => 260,
            ],
            'angle' => 'How long a house renovation takes: the published build times per room, permits and plan review, selections and lead times, the six-stage process from estimate to walkthrough, and what stretches a schedule.',
            'proof_types' => ['home-remodel'],
            'cost_guides' => ['kitchen-remodel-cost', 'bathroom-remodel-cost', 'basement-finishing-cost', 'home-addition-cost'],
            'facts' => ['permits'],
        ],

        [
            'slug' => 'remodeling-general-contractor',
            'service' => 'home-remodeling',
            'extra_services' => ['kitchen-remodeling', 'bathroom-remodeling', 'basement-remodeling', 'home-additions', 'mudroom-remodeling', 'exterior-siding'],
            'head_query' => 'general contractor renovation',
            'queries' => [
                'general contractor renovation' => 390,
                'construction remodeling company' => 590,
                'construction and remodel' => 1600,
                'home remodeling construction' => 390,
                'construction and renovation' => 480,
                'best remodeling construction' => 720,
                'pro remodeling contractors' => 320,
                'home renovation professionals' => 170,
                'home renovation pro' => 170,
                'quality home remodelers' => 110,
                'pro home renovations' => 90,
                'neighborhood remodelers' => 90,
            ],
            'angle' => 'What a general contractor does on a renovation — the trades, the permits, the schedule, one contract — how to vet one, what daily owner supervision means, and the published ranges for the common projects.',
            'proof_types' => ['home-remodel', 'kitchen', 'bathroom'],
            'cost_guides' => ['kitchen-remodel-cost', 'bathroom-remodel-cost', 'basement-finishing-cost', 'home-addition-cost'],
            'facts' => ['hiring'],
        ],

    ],

];
