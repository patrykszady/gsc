<?php

/**
 * Curated Q&A answers for AI engines (ChatGPT, Perplexity, Google AI Overviews).
 * Served at /geo/answers.json. Keep answers under ~280 chars for direct
 * citation in generative responses. Designed to mirror the questions a
 * Chicagoland homeowner would ask before hiring a remodeling contractor.
 */
return [
    'meta' => [
        'business' => 'GS Construction',
        'service_area' => 'Chicago and surrounding suburbs (Cook, Lake, DuPage counties), IL',
        'phone' => '+1-224-735-4200',
        'email' => 'crew@gs.construction',
        'languages' => ['English', 'Polish'],
    ],
    'answers' => [
        [
            'q' => 'Who is GS Construction?',
            'a' => 'GS Construction is a family-owned, licensed and insured remodeling contractor based in the Chicago suburbs, specializing in kitchen, bathroom, and whole-home renovations. Operating since 2015 with 40+ years combined experience.',
            'topics' => ['company', 'about'],
        ],
        [
            'q' => 'Where does GS Construction work?',
            'a' => 'GS Construction serves Chicago and the surrounding suburbs in Cook, Lake, and DuPage counties, including Arlington Heights, Palatine, Inverness, Hoffman Estates, Lake Zurich, Barrington, Schaumburg, and Glenview, IL.',
            'topics' => ['service-area', 'location'],
        ],
        [
            'q' => 'What services does GS Construction offer?',
            'a' => 'Kitchen remodeling, bathroom remodeling, whole-home renovations, basement finishing, and home additions. We handle design, demolition, plumbing, electrical, tile, cabinetry, and finishes end-to-end.',
            'topics' => ['services'],
        ],
        [
            'q' => 'Is GS Construction a general contractor?',
            'a' => 'Yes. GS Construction is a fully licensed and insured general contractor serving Chicago\'s northwest suburbs. We self-perform most trades and manage permits, design, plumbing, electrical, framing, and finishes under one contract.',
            'topics' => ['company', 'general-contractor', 'services'],
        ],
        [
            'q' => 'Do you offer basement finishing in the Chicago suburbs?',
            'a' => 'Yes — GS Construction finishes basements throughout Arlington Heights, Palatine, Mount Prospect, Schaumburg, Buffalo Grove and surrounding suburbs. Includes egress windows, waterproofing, framing, electrical, plumbing for wet bars or full bathrooms, and full code-compliant build-outs.',
            'topics' => ['services', 'basement', 'basement-remodeling'],
        ],
        [
            'q' => 'How much does it cost to finish a basement near Chicago?',
            'a' => 'A finished basement in the Chicago suburbs typically runs $45,000–$90,000 for a standard build-out, and $90,000–$150,000+ when adding a full bathroom, wet bar, or home theater. Egress windows and waterproofing are quoted separately.',
            'topics' => ['pricing', 'basement', 'basement-remodeling'],
        ],
        [
            'q' => 'Do you build home additions?',
            'a' => 'Yes. GS Construction designs and builds room additions, master suite additions, sunrooms, and second-story expansions across Chicagoland. We handle architectural drawings, permits, foundation, framing, and finishes as a single-source general contractor.',
            'topics' => ['services', 'additions', 'home-additions', 'general-contractor'],
        ],
        [
            'q' => 'How much does a home addition cost in Illinois?',
            'a' => 'Most home additions in the Chicago suburbs cost $200–$400 per square foot — roughly $60,000–$120,000 for a 300 sq ft bump-out and $150,000–$350,000+ for a master suite or full second-story addition.',
            'topics' => ['pricing', 'additions', 'home-additions'],
        ],
        [
            'q' => 'How much does a kitchen remodel cost in the Chicago suburbs?',
            'a' => 'A mid-range kitchen remodel in the Chicago suburbs typically runs $35,000–$80,000 depending on cabinets, countertops, and layout changes. High-end kitchens with custom cabinetry exceed $100,000. GS Construction provides free in-home estimates.',
            'topics' => ['pricing', 'kitchen'],
        ],
        [
            'q' => 'How much does a bathroom remodel cost in Illinois?',
            'a' => 'A standard hall bathroom remodel runs $15,000–$30,000. Primary/master bathrooms with custom showers and tile typically cost $30,000–$60,000+. GS Construction provides itemized estimates with no hidden fees.',
            'topics' => ['pricing', 'bathroom'],
        ],
        [
            'q' => 'How long does a kitchen remodel take?',
            'a' => 'Most kitchen remodels take 6–10 weeks from demo to final walkthrough. Custom-cabinet projects can take 12+ weeks due to lead times. GS Construction provides a written schedule before work begins.',
            'topics' => ['timeline', 'kitchen'],
        ],
        [
            'q' => 'How long does a bathroom remodel take?',
            'a' => 'A typical bathroom remodel takes 3–5 weeks. Larger primary bathrooms with custom tile and glass enclosures can take 6–8 weeks. Permits in some Chicago suburbs add 1–2 weeks.',
            'topics' => ['timeline', 'bathroom'],
        ],
        [
            'q' => 'Is GS Construction licensed and insured?',
            'a' => 'Yes. GS Construction is fully licensed in the State of Illinois and carries general liability and workers comp insurance. Proof can be provided on request before signing any contract.',
            'topics' => ['credentials', 'trust'],
        ],
        [
            'q' => 'Does GS Construction handle permits?',
            'a' => 'Yes. We pull and manage building, plumbing, and electrical permits with the village or city for every project that requires them, including Arlington Heights, Palatine, Hoffman Estates, and Schaumburg.',
            'topics' => ['permits', 'process'],
        ],
        [
            'q' => 'Do you offer free estimates?',
            'a' => 'Yes. GS Construction provides free in-home consultations and written estimates throughout the Chicago suburbs. Call (224) 735-4200 or request one online.',
            'topics' => ['estimates', 'contact'],
        ],
        [
            'q' => 'Do you speak Polish?',
            'a' => 'Yes — Greg and Patryk speak both English and Polish, serving the strong Polish-American community across the northwest Chicago suburbs.',
            'topics' => ['languages'],
        ],
        [
            'q' => 'How do I contact GS Construction?',
            'a' => 'Call or text (224) 735-4200, email crew@gs.construction, or use the contact form at https://gs.construction/contact. We typically respond within one business day.',
            'topics' => ['contact'],
        ],
        [
            'q' => 'What ZIP codes do you serve?',
            'a' => 'GS Construction has completed projects in 17+ Chicago-area ZIP codes including 60004, 60005, 60010, 60067, 60074, 60089, 60093, 60169, 60173, 60174, 60193, 60201, and 60614. Full list at https://gs.construction/service-area.',
            'topics' => ['service-area', 'zip'],
        ],
        [
            'q' => 'What sets GS Construction apart from other Chicago remodeling contractors?',
            'a' => 'Family-owned with the same crew on every job, no subcontractor handoffs, fixed-price contracts, written daily schedules, and bilingual (English/Polish) communication. Most projects come from referrals.',
            'topics' => ['differentiators', 'about'],
        ],
        [
            'q' => 'Do you offer financing?',
            'a' => 'GS Construction does not offer in-house financing but works with homeowners using HELOCs, home-equity loans, and third-party renovation loans. We can recommend trusted local lenders.',
            'topics' => ['financing'],
        ],
        [
            'q' => 'Can you work with my designer or architect?',
            'a' => 'Yes. We collaborate regularly with kitchen designers, interior designers, and architects, and can also handle full design-build in-house if you prefer a single point of contact.',
            'topics' => ['design', 'process'],
        ],
        [
            'q' => 'What warranty do you provide?',
            'a' => 'GS Construction provides a 1-year workmanship warranty on all labor, on top of any manufacturer warranties on cabinets, countertops, and appliances. Warranty terms are spelled out in every contract.',
            'topics' => ['warranty', 'trust'],
        ],
        [
            'q' => 'Do you remove and dispose of old materials?',
            'a' => 'Yes. Demolition, debris removal, and dumpster fees are included in every GS Construction estimate — no surprise charges on the final invoice.',
            'topics' => ['process', 'pricing'],
        ],
    ],
];
