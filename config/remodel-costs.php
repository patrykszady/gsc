<?php

/**
 * Cost-guide pages (/costs and /costs/{slug}).
 *
 * Every dollar figure here is the same one already published in
 * config/geo-answers.php and the area pricing guide — these pages are the
 * "right container" for pricing data we already stand behind (year-stamped
 * titles, tier tables, FAQPage schema) so search and AI answers can cite it.
 * Do not invent numbers: update geo-answers.php and this file together.
 */

return [

    'enabled' => true,

    'intro' => 'Most remodelers make you sit through a sales visit to hear a number. We publish ours. '
        . 'These are the real ranges we see across our completed projects in the Chicago suburbs — '
        . 'what drives them up or down, and what is typically quoted separately.',

    'guides' => [
        [
            'slug' => 'kitchen-remodel-cost',
            'service' => 'kitchen-remodeling',
            'name' => 'Kitchen Remodel Cost',
            'h1' => 'What a kitchen remodel costs in the Chicago suburbs',
            'answer' => 'A mid-range kitchen remodel in the Chicago suburbs typically runs $35,000–$80,000 depending on cabinets, countertops, and layout changes. High-end kitchens with custom cabinetry exceed $100,000. Every GS Construction estimate is itemized and free.',
            'tiers' => [
                ['tier' => 'Refresh (same layout)', 'range' => '$35,000–$50,000', 'includes' => 'Semi-custom cabinets, quartz or granite counters, new backsplash, lighting, paint — plumbing and walls stay put'],
                ['tier' => 'Full mid-range remodel', 'range' => '$50,000–$80,000', 'includes' => 'Layout changes, an island, appliance upgrades, tile floors, recessed lighting, possible wall opening'],
                ['tier' => 'Custom / high-end', 'range' => '$100,000+', 'includes' => 'Custom cabinetry, stone or porcelain-slab surfaces, structural changes, premium appliance packages'],
            ],
            'drivers' => [
                ['factor' => 'Cabinet grade', 'note' => 'Stock vs semi-custom vs custom is the single biggest swing — often 30–40% of the budget. We also install IKEA systems for value-focused projects.'],
                ['factor' => 'Layout changes', 'note' => 'Moving the sink, range, or walls adds plumbing, electrical, and sometimes structural work.'],
                ['factor' => 'Countertop material', 'note' => 'Quartz and granite sit mid-range; quartzite and porcelain slabs push higher.'],
                ['factor' => 'Appliances', 'note' => 'Panel-ready and pro-style ranges can add five figures on their own — we quote them as their own line.'],
            ],
            'timeline' => 'Most kitchen remodels take 6–10 weeks from demo to walkthrough; custom-cabinet projects can run 12+ weeks due to lead times.',
            'faq' => [
                ['question' => 'How much does a kitchen remodel cost in the Chicago suburbs?', 'answer' => 'A mid-range kitchen remodel typically runs $35,000–$80,000 depending on cabinets, countertops, and layout changes. High-end kitchens with custom cabinetry exceed $100,000. GS Construction provides free in-home estimates.'],
                ['question' => 'What is the biggest cost driver in a kitchen remodel?', 'answer' => 'Cabinetry — the jump from stock to semi-custom to custom cabinets is often 30–40% of the total budget. Layout changes that move plumbing or walls are the second biggest swing.'],
                ['question' => 'Is the estimate really itemized?', 'answer' => 'Yes — labor, materials, demolition, and disposal are broken out line by line, so you can compare quotes apples-to-apples and adjust scope to fit budget.'],
            ],
        ],
        [
            'slug' => 'bathroom-remodel-cost',
            'service' => 'bathroom-remodeling',
            'name' => 'Bathroom Remodel Cost',
            'h1' => 'What a bathroom remodel costs in the Chicago suburbs',
            'answer' => 'A standard hall bathroom remodel in the Chicago suburbs runs $15,000–$30,000. Primary bathrooms with custom showers and tile typically cost $30,000–$60,000+. Every GS Construction estimate is itemized with no hidden fees.',
            'tiers' => [
                ['tier' => 'Hall / guest bath', 'range' => '$15,000–$30,000', 'includes' => 'New tub or shower, tile surround, vanity, toilet, lighting, and flooring in the existing layout'],
                ['tier' => 'Primary / master bath', 'range' => '$30,000–$60,000+', 'includes' => 'Custom walk-in shower with glass, floor-to-ceiling tile, double vanity, heated floors, possible layout changes'],
            ],
            'drivers' => [
                ['factor' => 'Tile scope', 'note' => 'Floor-only vs full-height walls, and large-format or mosaic work, moves both material and labor significantly.'],
                ['factor' => 'Shower system', 'note' => 'A proper waterproofed custom shower with frameless glass is the priciest single element in most primary baths.'],
                ['factor' => 'Moving fixtures', 'note' => 'Relocating a toilet or sink a few feet typically adds $1,500–$4,000 in plumbing and floor work.'],
                ['factor' => 'Vanity & fixtures', 'note' => 'Stock vs custom vanities and fixture finish lines (chrome vs brushed brass) swing the finish budget.'],
            ],
            'timeline' => 'A typical bathroom remodel takes 3–5 weeks; large primary baths with custom tile and glass run 6–8 weeks. Permits in some suburbs add 1–2 weeks.',
            'faq' => [
                ['question' => 'How much does a bathroom remodel cost in Illinois?', 'answer' => 'A standard hall bathroom remodel runs $15,000–$30,000. Primary/master bathrooms with custom showers and tile typically cost $30,000–$60,000+. GS Construction provides itemized estimates with no hidden fees.'],
                ['question' => 'How much does it cost to move a toilet or sink?', 'answer' => 'Moving a fixture a few feet typically adds $1,500–$4,000 depending on drain routing and floor structure. We price it exactly during design so you can decide with real numbers.'],
                ['question' => 'How long does a bathroom remodel take?', 'answer' => 'A typical bathroom remodel takes 3–5 weeks. Larger primary bathrooms with custom tile and glass enclosures can take 6–8 weeks.'],
            ],
        ],
        [
            'slug' => 'basement-finishing-cost',
            'service' => 'basement-remodeling',
            'name' => 'Basement Finishing Cost',
            'h1' => 'What finishing a basement costs near Chicago',
            'answer' => 'A finished basement in the Chicago suburbs typically runs $45,000–$90,000 for a standard build-out, and $90,000–$150,000+ when adding a full bathroom, wet bar, or home theater. Egress windows and waterproofing are quoted separately.',
            'tiers' => [
                ['tier' => 'Standard build-out', 'range' => '$45,000–$90,000', 'includes' => 'Framing, insulation, drywall, flooring, lighting, and an open rec-room layout'],
                ['tier' => 'Full lower level', 'range' => '$90,000–$150,000+', 'includes' => 'Adds a full bathroom, wet bar or kitchenette, home theater, guest bedroom with egress'],
            ],
            'drivers' => [
                ['factor' => 'Adding a bathroom', 'note' => 'Below-grade plumbing (and sometimes an ejector pit) makes the basement bath the biggest single add.'],
                ['factor' => 'Egress windows', 'note' => 'Required for legal bedrooms — excavation plus the window itself, quoted separately.'],
                ['factor' => 'Moisture control', 'note' => 'Drain tile, sump work, and waterproofing are prerequisites we scope before finishes — also quoted separately.'],
                ['factor' => 'Ceiling treatment', 'note' => 'Drywall vs drop ceiling, and how much ductwork rerouting the layout needs.'],
            ],
            'timeline' => 'Standard build-outs typically run 6–9 weeks depending on scope; adding a bathroom extends the plumbing and inspection sequence.',
            'faq' => [
                ['question' => 'How much does it cost to finish a basement near Chicago?', 'answer' => 'A finished basement typically runs $45,000–$90,000 for a standard build-out, and $90,000–$150,000+ when adding a full bathroom, wet bar, or home theater. Egress windows and waterproofing are quoted separately.'],
                ['question' => 'Does a basement bedroom need an egress window?', 'answer' => 'Yes — building codes require egress for legal basement bedrooms. Egress excavation and installation are quoted as their own line so the core build-out price stays clear.'],
            ],
        ],
        [
            'slug' => 'home-addition-cost',
            'service' => 'home-additions',
            'name' => 'Home Addition Cost',
            'h1' => 'What a home addition costs in Illinois',
            'answer' => 'Most home additions in the Chicago suburbs cost $200–$400 per square foot — roughly $60,000–$120,000 for a 300 sq ft bump-out and $150,000–$350,000+ for a master suite or full second-story addition.',
            'tiers' => [
                ['tier' => 'Bump-out (~300 sq ft)', 'range' => '$60,000–$120,000', 'includes' => 'Foundation, framing, roof tie-in, and finishing one new space — mudroom, breakfast nook, office'],
                ['tier' => 'Master suite / second story', 'range' => '$150,000–$350,000+', 'includes' => 'Large-footprint or second-floor addition with bathroom, structural engineering, and full mechanical extension'],
            ],
            'drivers' => [
                ['factor' => 'Foundation vs building up', 'note' => 'Ground-level additions need excavation and foundation; second stories need structural analysis and temporary weather protection.'],
                ['factor' => 'Roof tie-in', 'note' => 'Where new roof meets old is precision work — flashed and warrantied, not just shingled.'],
                ['factor' => 'Mechanical extension', 'note' => 'HVAC capacity, electrical panel load, and plumbing runs to the new space.'],
                ['factor' => 'Bathroom in the addition', 'note' => 'A master-suite bath adds full plumbing rough-in and finish costs to the per-square-foot math.'],
            ],
            'timeline' => 'Additions carry the longest schedules — architectural drawings and permits come first (sealed plans are required in most villages), then construction typically runs several months depending on size.',
            'faq' => [
                ['question' => 'How much does a home addition cost in Illinois?', 'answer' => 'Most home additions in the Chicago suburbs cost $200–$400 per square foot — roughly $60,000–$120,000 for a 300 sq ft bump-out and $150,000–$350,000+ for a master suite or full second-story addition.'],
                ['question' => 'Do I need an architect for an addition?', 'answer' => 'Almost always — most Chicago-suburb villages require sealed drawings for addition permits. We coordinate the architectural drawings and pull the permits as part of the project.'],
            ],
        ],
    ],

];
