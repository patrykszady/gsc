<?php

/**
 * Service page content for gs.construction.
 *
 * Moved out of App\Livewire\ServicePage, where it was a 210-line hardcoded
 * array. Two problems that fixed:
 *
 *  1. The "Our Process" block was a generic four-step
 *     Consultation → Design → Build → Enjoy that did NOT match the six-stage
 *     process the company actually publishes on /process. A visitor comparing
 *     the two pages saw two different companies.
 *  2. Hardcoding it in shared code meant every tenant claiming /services
 *     rendered GS Construction's kitchen copy. ServicePage now 404s when the
 *     current site declares no services (see the component).
 *
 * EVERY figure below is already published elsewhere on this site — the ranges
 * come from /costs, the stage names, timings and warranty terms from /process
 * and /warranty. Nothing here is invented, so the pages cannot contradict each
 * other. If a number changes there, change it here.
 *
 * Lives under config/sites/gsc/ rather than config/ so it belongs to ONE
 * tenant. As a shared config file every site inherited it, and
 * jpeterson-design.com/services/kitchen-remodeling served a contractor's
 * kitchen page inside an interior designer's theme. A tenant that wants
 * service pages adds its own config/sites/{slug}/services-content.php; one
 * that does not gets a 404.
 */

// The company's real six-stage process, from resources/views/process.blade.php.
// Shared across services because it IS shared — only the build duration differs,
// which each service overrides via 'buildTime'.
$process = [
    ['step' => 1, 'title' => 'Free in-home estimate', 'description' => 'Greg or Patryk — the owners, not a salesperson — walk your space, take real measurements, and give you honest feedback on what your budget buys.', 'time' => 'Week 0', 'body' => 'Greg or Patryk — the owners, not a salesperson — walk your space, talk through what you want, and take real measurements. You get honest feedback on what your budget buys, grounded in the project ranges we publish openly.'],
    ['step' => 2, 'title' => 'Itemized scope & contract', 'description' => 'Labor, materials, demolition and disposal priced line by line, not one mystery number. Payment terms and warranty are in the written contract.', 'time' => 'Within days of the visit', 'body' => 'Your proposal is an itemized scope — labor, materials, demolition, disposal, line by line — not a single mystery number. Payment terms and warranty coverage are spelled out in the written contract. No surprise charges on the final invoice.'],
    ['step' => 3, 'title' => 'Design & selections', 'description' => 'Bring your own designer, use our architects and designers, or choose everything yourself. Selections are scheduled ahead so lead times never stall the site.', 'time' => 'Parallel with permits', 'body' => 'Bring your own designer or architect, let us connect you with our trusted architects, engineers, or designers, or be your own designer — we send you to trusted showrooms and install what you choose. Selections are scheduled ahead of the build so lead times never stall the site.', 'pageTitle' => 'Design & selections, your way'],
    ['step' => 4, 'title' => 'Permits & scheduling', 'description' => 'We pull and manage building, plumbing and electrical permits with your village — typically 1–2 weeks — and hand you a written schedule before demo day.', 'time' => 'Typically 1–2 weeks in most suburbs', 'body' => 'We pull and manage building, plumbing, and electrical permits with your village — Arlington Heights, Palatine, Winnetka, Schaumburg, and every town we serve — and hand you a written schedule before demo day.'],
    ['step' => 5, 'title' => 'The build, owner-supervised', 'description' => 'Long-standing trade partners working in sequence under daily owner supervision. Your client portal shows the schedule, change orders and current balance.', 'time' => 'Kitchens 8–12 wks · Baths 3–5 wks', 'body' => 'Our long-standing trade partners work in sequence under daily owner supervision. Your private client portal shows the schedule (past and upcoming), current change orders, and up-to-date balances — and you always have a direct line to the owners.', 'pageTitle' => 'The build, owner-supervised daily'],
    // 'link' renders as a trailing anchor after the description. Kept as data
    // rather than HTML in the string because every consumer escapes these.
    ['step' => 6, 'title' => 'Walkthrough & warranty', 'description' => 'We walk the finished job together, close out every punch-list item, hand over manufacturer paperwork, and your written workmanship warranty starts.', 'link' => ['href' => '/warranty', 'text' => 'Our commitment never expires'], 'time' => 'Final week', 'body' => 'We walk the finished project together, close out every punch-list item, hand over manufacturer paperwork, and your written workmanship warranty starts — with the owners a phone call away if anything ever needs attention.', 'pageTitle' => 'Walkthrough, punch list & warranty'],
];

return [
    'services' => [

        'kitchen-remodeling' => [
            'title' => 'Kitchen Remodeling',
            'heroTitle' => 'Kitchen Remodeling Contractors',
            'heroSubtitle' => 'Layout changes, permits, trades and schedule — all of it ours to handle, with an owner here every day',
            'projectType' => 'kitchen',
            'shortLabel' => 'Kitchen',
            'description' => 'Your kitchen is the room the whole house runs through, so we don\'t treat the remodel like something you just have to survive. Greg or Patryk is here every day — not a project manager passing your questions along — and the same trades we\'ve worked with for years show up in the order they\'re supposed to. You\'ll know what everything costs before demo day, and you\'ll have a direct line to the owners from the first walkthrough to the last punch-list item.',

            'facts' => [
                'Typical range' => '$35,000–$80,000 · custom $100K+',
                'Typical build' => '8–12 weeks on site',
                'Permits' => 'Pulled and managed by us',
                // The written term lives on /warranty and nowhere else: leading
                // with a number invites "am I still covered?", which is the
                // opposite of what the page actually promises.
                'Warranty' => ['value' => 'Workmanship, in writing', 'note' => 'Our commitment never expires', 'href' => '/warranty'],
            ],

            'features' => [
                ['title' => 'Layout & structural changes', 'description' => 'Removing walls, relocating plumbing and gas, adding an island or a pantry — including the engineering and permits a load-bearing change requires.'],
                ['title' => 'Custom & semi-custom cabinetry', 'description' => 'From stock upgrades to full custom builds, specified to your storage habits and installed level, plumb and scribed to real walls.'],
                ['title' => 'Countertops & backsplash', 'description' => 'Granite, quartz and marble templated after cabinets are set, so seams and overhangs land where they should. Tile and slab backsplash installed to match.'],
                ['title' => 'Plumbing & electrical', 'description' => 'New supply and drain lines, dedicated appliance circuits, code-required GFCI and under-cabinet power — inspected and signed off by your village.'],
                ['title' => 'Lighting design', 'description' => 'Task, ambient and accent layers planned before the ceiling closes, so cans, pendants and under-cabinet runs are where the work actually happens.'],
                ['title' => 'Flooring & finish carpentry', 'description' => 'Hardwood, tile or LVP run correctly into adjoining rooms, plus trim, toe kicks and crown that make new cabinets look built in.'],
            ],

            'included' => [
                'Demolition, dumpster and daily site cleanup',
                'Plumbing, electrical and HVAC modifications with permits',
                'Cabinet, countertop, tile and fixture installation',
                'Drywall, paint and finish carpentry',
                'Appliance installation and final hookup',
                'Final walkthrough, punch list and warranty paperwork',
            ],
            'notIncluded' => [
                'Finish materials — cabinetry, countertops, tile and backsplash, plumbing fixtures, lighting fixtures, hardware and flooring',
                'Appliances — you choose and buy them, we install and connect them',
                'Structural engineering, when a layout change needs a stamped drawing',
            ],

            'faqs' => [
                ['question' => 'How much does a kitchen remodel cost?', 'answer' => 'Every kitchen remodel is different — cost depends on the scope of work, materials you choose, and the size of your space. We provide free in-home estimates with a detailed breakdown tailored to your specific project and budget.'],
                ['question' => 'How long does a kitchen remodel take?', 'answer' => 'The timeline depends on the scope of your project — layout changes, custom cabinetry, and material lead times all play a role. We create a detailed schedule before work begins and keep you informed throughout.'],
                ['question' => 'Do you handle permits for kitchen remodeling?', 'answer' => 'Yes, GS Construction handles all necessary permits for kitchen remodeling projects. We are familiar with local building codes across the Chicagoland area and ensure your project is fully compliant.'],
                ['question' => 'Can I stay in my home during a kitchen remodel?', 'answer' => 'Absolutely! Most of our clients stay in their homes during kitchen remodels. We set up temporary kitchen areas and minimize disruption to your daily routine.'],
                ['question' => 'What is included in a full kitchen remodel?', 'answer' => 'A full kitchen remodel typically includes demolition, new cabinetry, countertops, flooring, backsplash, plumbing and electrical updates, lighting, and appliance installation. We customize every project to your needs and budget.'],
            ],
            'ctaHeading' => 'Ready to Transform Your Kitchen?',
        ],

        'bathroom-remodeling' => [
            'title' => 'Bathroom Remodeling',
            'heroTitle' => 'Bathroom Remodeling Contractors',
            'heroSubtitle' => 'Primary baths and shower rebuilds, done once and done right — with an owner here, not a middleman',
            'projectType' => 'bathroom',
            'shortLabel' => 'Bathroom',
            'description' => 'Bathrooms are small rooms that hide expensive mistakes behind the tile. That\'s why one of us is here while the work happens, and why nothing gets closed up before the village has seen it. You get your scope written out line by line before we start, so there\'s no guessing what you\'re paying for — and we build it to the standard we\'d want in our own house.',

            'facts' => [
                'Typical range' => '$15,000–$30,000 · primary baths higher',
                'Typical build' => '3–5 weeks on site',
                'Permits' => 'Pulled and managed by us',
                // The written term lives on /warranty and nowhere else: leading
                // with a number invites "am I still covered?", which is the
                // opposite of what the page actually promises.
                'Warranty' => ['value' => 'Workmanship, in writing', 'note' => 'Our commitment never expires', 'href' => '/warranty'],
            ],

            'features' => [
                ['title' => 'Custom showers', 'description' => 'Curbless and standard pans, waterproof membranes, niches and benches built into the framing rather than added afterwards.'],
                ['title' => 'Waterproofing done right', 'description' => 'The part nobody sees and everybody pays for twice when it is skipped: proper membrane, slope to drain, and sealed penetrations.'],
                ['title' => 'Tile & stone', 'description' => 'Floor, wall and shower tile set on a stable substrate with layout planned so cuts land where they are least visible.'],
                ['title' => 'Vanities & fixtures', 'description' => 'Stock, semi-custom or built-in vanities, with supply and drain relocated to suit rather than dictating the layout.'],
                ['title' => 'Ventilation & electrical', 'description' => 'Correctly sized fans vented outside — not into the attic — plus GFCI protection, heated floors and lighting on the right circuits.'],
                ['title' => 'Accessibility work', 'description' => 'Curbless entries, blocking for grab bars, comfort-height fixtures and wider doorways for aging-in-place or ADA needs.'],
            ],

            'included' => [
                'Demolition down to studs where the scope requires it',
                'Shower pan, waterproofing and tile installation',
                'Plumbing and electrical modifications with permits',
                'Vanity, fixture and accessory installation',
                'Exhaust fan sized and vented to exterior',
                'Final walkthrough, punch list and warranty paperwork',
            ],
            'notIncluded' => [
                'Finish materials — vanity, tile and stone, plumbing fixtures, shower glass, lighting, mirrors and hardware',
                'Structural changes beyond the bathroom footprint',
            ],

            'faqs' => [
                ['question' => 'How much does a bathroom remodel cost?', 'answer' => 'Bathroom remodeling costs vary based on the size of your space, finishes, and scope of work. We offer free in-home estimates tailored to your vision and budget.'],
                ['question' => 'How long does a bathroom remodel take?', 'answer' => 'The timeline depends on the scope of your renovation — tile work, fixture changes, and any structural modifications all factor in. We provide a detailed schedule before starting any work.'],
                ['question' => 'Do you install walk-in showers?', 'answer' => 'Yes! Walk-in showers are one of our most popular requests. We install frameless glass enclosures, custom tile, rain shower heads, and accessible curbless designs.'],
                ['question' => 'Can you make my bathroom more accessible?', 'answer' => 'Absolutely. We specialize in accessibility modifications including grab bars, walk-in tubs, curbless showers, wider doorways, and comfort-height toilets for safe, comfortable living.'],
                ['question' => 'Do you handle plumbing during bathroom remodels?', 'answer' => 'Yes, our team handles all plumbing work as part of the remodel, including moving fixtures, installing new supply lines, and updating drain systems to meet current codes.'],
            ],
            'ctaHeading' => 'Ready to Start Your Bathroom Remodel?',
        ],

        'basement-remodeling' => [
            'title' => 'Basement Remodeling',
            'heroTitle' => 'Basement Finishing & Remodeling',
            'heroSubtitle' => 'Egress, moisture control and code-compliant build-outs — finished space that passes inspection and stays dry',
            'projectType' => 'basement',
            'shortLabel' => 'Basement',
            'description' => 'Most of a basement is decided before the drywall ever goes up: where the water\'s really coming from, whether the egress is legal, how much ceiling you actually have. We sort all that out first, pull the permits with your village, and hand you a schedule before demo day. Then we build the room you actually wanted — and nothing gets framed over until it\'s been signed off.',

            'facts' => [
                'Typical range' => '$45,000–$90,000 · $90,000–$150,000+ with bed & bath',
                'Permits' => 'Pulled and managed by us',
                'Egress' => 'Required for a legal bedroom',
                // The written term lives on /warranty and nowhere else: leading
                // with a number invites "am I still covered?", which is the
                // opposite of what the page actually promises.
                'Warranty' => ['value' => 'Workmanship, in writing', 'note' => 'Our commitment never expires', 'href' => '/warranty'],
            ],

            'features' => [
                ['title' => 'Moisture & waterproofing', 'description' => 'Diagnosing where water actually comes from before anything is framed — drainage, sump, vapor barrier and insulation detailing.'],
                ['title' => 'Egress windows', 'description' => 'Cutting and permitting a code-compliant egress window and well, which is what makes a basement bedroom legal rather than just finished.'],
                ['title' => 'Full baths & wet bars', 'description' => 'Below-grade plumbing including ejector pumps where gravity drainage is not available.'],
                ['title' => 'Framing, insulation & drywall', 'description' => 'Built to maintain required ceiling height and clearances around beams, ducts and service panels.'],
                ['title' => 'Electrical & HVAC', 'description' => 'Circuits, lighting layout, and heating and cooling extended properly rather than bled off an existing run.'],
                ['title' => 'Flooring & finish', 'description' => 'Floor assemblies chosen for below-grade conditions, plus trim, doors and built-ins.'],
            ],

            'included' => [
                'Framing, insulation, drywall, paint and trim',
                'Electrical, lighting and HVAC extension with permits',
                'Egress window cutting and installation where required',
                'Bathroom and wet-bar plumbing including ejector pump',
                'Flooring and finish carpentry',
                'Final walkthrough, punch list and warranty paperwork',
            ],
            'notIncluded' => [
                'Finish materials — flooring, tile, cabinetry, plumbing and lighting fixtures, doors and hardware',
                'Foundation repair or exterior drainage work, quoted separately if needed',
            ],

            'faqs' => [
                ['question' => 'How much does basement finishing cost?', 'answer' => 'Finishing a basement in the Chicago suburbs typically runs $45,000–$90,000, or $90,000–$150,000+ once you add a bedroom and full bath — driven by square footage, layout, finishes, and whether you need egress windows or below-grade plumbing. GS Construction provides a free, itemized estimate broken down by phase.'],
                ['question' => 'How long does basement finishing take?', 'answer' => 'A typical basement finish takes 6–12 weeks. Waterproofing, framing, electrical, and plumbing are the longest phases. Adding a full bathroom or wet bar adds 1–2 weeks. We work to minimize disruption to your daily life.'],
                ['question' => 'Do I need permits to finish my basement in Illinois?', 'answer' => 'Yes — Illinois municipalities (Arlington Heights, Palatine, Hoffman Estates, Schaumburg, etc.) all require building, electrical, and plumbing permits for basement finishing. GS Construction handles all permitting and inspections for you.'],
                ['question' => 'Can you add a bedroom or bathroom in my basement?', 'answer' => 'Yes. Basement bedrooms require code-compliant egress windows, and bathrooms require proper plumbing tie-ins (often with an ejector pit). We handle the engineering, permits, and full build.'],
                ['question' => 'What about moisture and water in the basement?', 'answer' => 'Before any finishing, we assess your basement for moisture intrusion. We can install vapor barriers, sump systems, drain tile, and proper insulation to ensure your finished space stays dry and mold-free.'],
            ],
            'ctaHeading' => 'Ready to Finish Your Basement?',
        ],

        'home-additions' => [
            'title' => 'Home Additions',
            'heroTitle' => 'Home Addition Contractors',
            'heroSubtitle' => 'Room additions, second stories and dormers — foundation to roofline, permitted and owner-supervised',
            'projectType' => 'addition',
            'shortLabel' => 'Home Addition',
            'description' => 'An addition is really a small house attached to the one you already have, and the seam between them is where most projects go wrong. We run the whole thing as one job with one schedule, so nobody\'s pointing at the next trade. The engineering and the village approvals are done before we break ground, one of us is here while it goes up, and you can check the schedule, any change orders and your balance in your portal whenever you want.',

            'facts' => [
                'Typical range' => '$200–$400 per sq ft',
                'Typical project' => '$60,000–$120,000 for ~300 sq ft',
                'Permits' => 'Zoning review, plans and permits handled',
                // The written term lives on /warranty and nowhere else: leading
                // with a number invites "am I still covered?", which is the
                // opposite of what the page actually promises.
                'Warranty' => ['value' => 'Workmanship, in writing', 'note' => 'Our commitment never expires', 'href' => '/warranty'],
            ],

            'features' => [
                ['title' => 'Zoning & feasibility', 'description' => 'Setbacks, lot coverage and height limits checked with your village before design time is spent on something that cannot be built.'],
                ['title' => 'Foundation & framing', 'description' => 'Footings and foundation sized for local frost depth, framed and tied into the existing structure by engineered detail.'],
                ['title' => 'Roof & envelope tie-in', 'description' => 'The join done properly — flashing, water management and insulation continuity, so the new roof does not leak into the old one.'],
                ['title' => 'Second stories & dormers', 'description' => 'Including the structural work to carry a new floor on a house that was never designed for it.'],
                ['title' => 'Mechanical capacity', 'description' => 'Honest assessment of whether existing HVAC, electrical service and water heating can carry the added square footage.'],
                ['title' => 'Exterior matching', 'description' => 'Siding, roofing, brick and trim matched so the addition reads as part of the house rather than bolted on.'],
            ],

            'included' => [
                'Structural engineering coordination and permit drawings',
                'Excavation, foundation and framing',
                'Roofing, siding and exterior finish matched to the existing home',
                'Electrical, plumbing and HVAC extension',
                'Insulation, drywall, flooring and finish carpentry',
                'Final walkthrough, punch list and warranty paperwork',
            ],
            'notIncluded' => [
                'Finish materials — flooring, tile, cabinetry, plumbing and lighting fixtures, windows, doors and hardware',
                'Architectural design fees, when you engage your own architect',
                'Utility service upgrades required by the village',
            ],

            'faqs' => [
                ['question' => 'How much does a home addition cost?', 'answer' => 'A room addition or home extension typically runs $200–$400 per square foot depending on the type of room, finishes, foundation work, and site conditions — about $60,000–$120,000 for a ~300 sq ft addition, and $150,000–$350,000+ for a large second-storey or master-suite build. GS Construction provides free, detailed estimates.'],
                ['question' => 'How long does a room addition take?', 'answer' => 'Most room additions take 8–16 weeks depending on size, permits, weather, and structural work required. Foundation, framing, and roofing are the longest phases. Second-story additions typically take 16–24 weeks.'],
                ['question' => 'Do you handle architectural plans and permits for additions?', 'answer' => 'Yes. We work with licensed architects and structural engineers and handle all village/city zoning, building, electrical, plumbing, and mechanical permits — including Arlington Heights, Palatine, Hoffman Estates, and Schaumburg.'],
                ['question' => 'Can I add a second story to my existing house?', 'answer' => 'Often, yes. We evaluate the existing foundation and structural framing for capacity, work with an engineer to design proper reinforcement, and coordinate permits. Second-story additions are major projects but add significant square footage without losing yard space.'],
                ['question' => 'Will the addition match my existing home?', 'answer' => 'Absolutely. Our design team specs matching siding, brick, roofing, windows, and trim so the addition reads as part of the original home. Interior transitions are also planned to flow naturally with existing finishes.'],
            ],
            'ctaHeading' => 'Ready to Add Space to Your Home?',
        ],

        'home-remodeling' => [
            'title' => 'Whole-Home Remodeling',
            'heroTitle' => 'Whole-Home Remodeling Contractors',
            'heroSubtitle' => 'Multi-room and gut renovations sequenced as one project, under daily owner supervision',
            'projectType' => 'home-remodel',
            'shortLabel' => 'Home Remodel',
            'description' => 'A whole-home remodel lives or dies on the order things happen in. We run it as one job, with one schedule and one number to call: Greg or Patryk here on site, trades we\'ve used for years rather than whoever happens to be free that week, and a portal showing you the schedule, any change orders and your balance. You shouldn\'t have to project-manage your own house.',

            'facts' => [
                'Scope' => 'Multi-room and full gut renovations',
                'Supervision' => 'Daily, by the owners',
                'Permits' => 'Pulled and managed by us',
                // The written term lives on /warranty and nowhere else: leading
                // with a number invites "am I still covered?", which is the
                // opposite of what the page actually promises.
                'Warranty' => ['value' => 'Workmanship, in writing', 'note' => 'Our commitment never expires', 'href' => '/warranty'],
            ],

            'features' => [
                ['title' => 'One schedule, one contract', 'description' => 'Every room in a single itemized scope, sequenced so plumbing, electrical and HVAC happen before anything closes up.'],
                ['title' => 'Structural changes', 'description' => 'Removing walls and opening up floor plans, with engineering and permits for anything load-bearing.'],
                ['title' => 'Systems upgrades', 'description' => 'Electrical service, plumbing supply lines and HVAC brought up to what a modern house actually draws.'],
                ['title' => 'Kitchens & baths as part of the whole', 'description' => 'The rooms that drive the schedule, planned against the rest of the house rather than in isolation.'],
                ['title' => 'Flooring & trim continuity', 'description' => 'Materials and transitions that run correctly room to room, which is what makes a renovation read as one project.'],
                ['title' => 'Phased or all-at-once', 'description' => 'We will tell you honestly which is cheaper for your scope, and whether you can realistically stay in the house.'],
            ],

            'included' => [
                'Single itemized scope covering every room in the project',
                'Demolition, structural work and mechanical upgrades',
                'All trade coordination and scheduling',
                'Drywall, paint, flooring and finish carpentry',
                'Permits and inspections across every trade',
                'Final walkthrough, punch list and warranty paperwork',
            ],
            'notIncluded' => [
                'Finish materials — cabinetry, countertops, tile, flooring, plumbing and lighting fixtures, doors and hardware',
                'Appliances, which you choose and buy',
                'Temporary housing, if the scope makes staying impractical',
            ],

            'faqs' => [
                ['question' => 'What does whole home remodeling include?', 'answer' => 'Whole home remodeling can include kitchen and bathroom renovations, open floor plan conversions, room additions, basement finishing, and complete interior updates. We customize every project to your needs and budget.'],
                ['question' => 'How long does a whole home remodel take?', 'answer' => 'The timeline for a whole home remodel depends entirely on the scope — whether it includes structural changes, additions, or a full interior renovation. We create detailed project timelines and keep you updated throughout construction.'],
                ['question' => 'Do you handle room additions?', 'answer' => 'Yes, we handle room additions including sunrooms, master suites, family rooms, and second-story additions. We manage everything from architectural design through final construction.'],
                ['question' => 'Can you convert my home to an open floor plan?', 'answer' => 'Open floor plan conversions are one of our specialties! We safely remove walls, including load-bearing walls with proper engineering and permits, to create modern, flowing living spaces.'],
                ['question' => 'Do you work with architects and designers?', 'answer' => 'Yes, we collaborate with architects and interior designers, and also have in-house design capabilities. Whether you bring your own plans or need us to design from scratch, we ensure your vision becomes reality.'],
            ],
            'ctaHeading' => 'Ready to Remodel Your Home?',
        ],

        'mudroom-remodeling' => [
            'title' => 'Mudroom Remodeling',
            'heroTitle' => 'Mudroom & Entry Remodeling',
            'heroSubtitle' => 'The small job we run like a big one — measured to your family, priced line by line, owner on site',
            'projectType' => 'mudroom',
            'shortLabel' => 'Mudroom & Laundry',
            'description' => 'A mudroom is a small job, and small jobs are the ones people cut corners on. We measure how your family actually comes through the door — boots, backpacks, the dog leash, all of it — price the built-ins line by line like any other project, and build it with the same owner on site and the same written warranty as a full kitchen. Short job, same standard.',

            'facts' => [
                'Scope' => 'Entry, mudroom and laundry combinations',
                'Common add-on' => 'Built alongside a kitchen or addition',
                'Permits' => 'Handled where the scope requires them',
                // The written term lives on /warranty and nowhere else: leading
                // with a number invites "am I still covered?", which is the
                // opposite of what the page actually promises.
                'Warranty' => ['value' => 'Workmanship, in writing', 'note' => 'Our commitment never expires', 'href' => '/warranty'],
            ],

            'features' => [
                ['title' => 'Built-in lockers & benches', 'description' => 'Sized per person to what actually gets dropped at the door, with closed storage for the things that should not be on display.'],
                ['title' => 'Durable flooring', 'description' => 'Tile and LVP chosen for salt, slush and dog traffic, detailed so water does not reach the subfloor.'],
                ['title' => 'Laundry integration', 'description' => 'Combining entry and laundry where the layout supports it, including plumbing and venting relocation.'],
                ['title' => 'Coat, boot & sports storage', 'description' => 'Hooks, cubbies, drying space and racking planned around the gear you own, not a showroom photo.'],
                ['title' => 'Lighting & power', 'description' => 'Practical lighting plus outlets where phones, chargers and vacuums actually get used.'],
                ['title' => 'Garage & side-entry transitions', 'description' => 'Doors, thresholds and steps made safe and weather-tight where the mudroom meets the outside.'],
            ],

            'included' => [
                'Demolition and framing modifications',
                'Custom built-in cabinetry, benches and storage',
                'Flooring and tile installation',
                'Electrical and lighting work',
                'Drywall, paint and finish carpentry',
                'Final walkthrough, punch list and warranty paperwork',
            ],
            'notIncluded' => [
                'Finish materials — tile and flooring, lighting fixtures, hooks, hardware and any stone or countertop',
                'Washer and dryer, where laundry is part of the scope',
            ],

            'faqs' => [
                ['question' => 'How much does a mudroom remodel cost?', 'answer' => 'Most mudroom projects in the Chicago suburbs run $8,000–$25,000+ depending on the size of the space, the amount of custom cabinetry and built-in lockers, flooring, and whether laundry or plumbing work is included. GS Construction provides a free, itemized estimate.'],
                ['question' => 'Can you combine my mudroom and laundry room?', 'answer' => 'Yes — combined mudroom/laundry rooms are one of our most popular requests. We plan cabinetry, folding counters, hanging space, utility sinks, and drop zones so the room handles both laundry and everyday entry clutter.'],
                ['question' => 'How long does a mudroom remodel take?', 'answer' => 'A typical mudroom or laundry/mudroom remodel takes 2–5 weeks depending on custom cabinetry lead times, tile work, and any plumbing or electrical changes. We give you a clear schedule before we start.'],
                ['question' => 'Do you build custom lockers and benches?', 'answer' => 'Yes. We build custom lockers, cubbies, bench seating, hooks, and cabinetry sized to your space and family — including pet stations, shoe storage, and charging drop zones.'],
                ['question' => 'Do mudroom projects need permits?', 'answer' => 'Simple built-ins and finishes usually do not, but adding or relocating plumbing (utility sink, washer) or electrical circuits typically requires permits. GS Construction handles any required permitting and inspections for you.'],
            ],
            'ctaHeading' => 'Ready to Build Your Mudroom?',
        ],
    ],

    // Attached to every service below, so the service pages and /process can
    // never describe two different companies.
    'process' => $process,

    /*
     * Presentation data for the six service cards rendered by
     * resources/views/partials/services-grid.blade.php (used on /contact and
     * the area pages).
     *
     * Lifted verbatim out of that partial, which is SHARED code: it hardcoded
     * GS Construction's six services, so any tenant including the partial
     * advertised GSC's services as its own. The partial now reads this key and
     * renders nothing when a site declares none.
     *
     * Overlaps with 'services' above (title, description) and should fold into
     * it once the card copy and the service-page copy are reconciled — the two
     * descriptions differ today, so merging them would change the visible page.
     */
    'grid' => [
        [
            'slug' => 'kitchen-remodeling',
            'urlSlug' => 'kitchen-remodeling',
            'title' => 'Kitchen Remodeling',
            'projectType' => 'kitchen',
            'description' => 'Transform your kitchen into the heart of your home. From custom cabinetry and premium countertops to complete renovations – we create beautiful, functional spaces where families gather and memories are made.',
            'gradient' => 'from-sky-500 to-blue-600',
            'features' => [
                'Custom cabinetry & storage solutions',
                'Granite, quartz & marble countertops',
                'Flooring, lighting & complete renovations',
            ],
        ],
        [
            'slug' => 'bathroom-remodeling',
            'urlSlug' => 'bathroom-remodeling',
            'title' => 'Bathroom Remodeling',
            'projectType' => 'bathroom',
            'description' => 'Create your personal spa retreat with expert bathroom renovations. From luxurious walk-in showers and soaking tubs to modern vanities and tile work – we design bathrooms that combine comfort with style.',
            'gradient' => 'from-indigo-500 to-purple-600',
            'features' => [
                'Walk-in showers & luxury tubs',
                'Custom tile work & vanities',
                'Modern fixtures & lighting',
            ],
        ],
        [
            'slug' => 'home-remodeling',
            'urlSlug' => 'home-remodeling',
            'title' => 'Home Remodeling',
            'projectType' => 'home-remodel',
            'description' => 'Comprehensive home renovations that breathe new life into your entire living space. From room additions and open floor plans to complete home makeovers – we handle projects of any scale with precision.',
            'gradient' => 'from-emerald-500 to-teal-600',
            'features' => [
                'Room additions & expansions',
                'Open concept floor plans',
                'Complete home renovations',
            ],
        ],
        [
            'slug' => 'basement-remodeling',
            'urlSlug' => 'basement-remodeling',
            'title' => 'Basement Remodeling',
            'projectType' => 'basement',
            'description' => 'Turn an unfinished or dated basement into comfortable, code-compliant living space. From family rooms and home theaters to guest suites, wet bars, and basement bathrooms – we finish lower levels your family will actually use.',
            'gradient' => 'from-amber-500 to-orange-600',
            'features' => [
                'Family rooms, theaters & rec spaces',
                'Guest bedrooms & basement bathrooms',
                'Code-compliant electrical & plumbing',
            ],
        ],
        [
            'slug' => 'home-additions',
            'urlSlug' => 'home-additions',
            'title' => 'Home Additions',
            'projectType' => 'addition',
            'description' => 'Expand your home with seamless additions designed to match your existing layout. From sunrooms and master suites to second-story additions – we add square footage that blends naturally with your home.',
            'gradient' => 'from-rose-500 to-pink-600',
            'features' => [
                'Room additions & bump-outs',
                'Sunrooms & four-season rooms',
                'Master suite & second-story additions',
            ],
        ],
        [
            'slug' => 'mudroom-remodeling',
            'urlSlug' => 'mudroom-remodeling',
            'title' => 'Mudroom & Laundry',
            'projectType' => 'mudroom',
            'description' => 'Tame the daily clutter with a custom mudroom or laundry/mudroom combo. Built-in lockers, benches, cubbies, drop zones, durable tile floors, and utility sinks – designed around how your family actually moves through your home.',
            'gradient' => 'from-teal-500 to-cyan-600',
            'features' => [
                'Built-in lockers, benches & cubbies',
                'Combined laundry/mudroom layouts',
                'Durable tile floors & utility sinks',
            ],
        ],
    ],

];
