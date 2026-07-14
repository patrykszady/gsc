<?php

/**
 * Trade-partner pages (/trades and /trades/{slug}).
 *
 * GS Construction is the general contractor: one contract, one project lead,
 * and a bench of long-standing trade partners we schedule, supervise, and
 * stand behind. These pages explain that model per trade — for homeowners
 * researching "who actually does the work" and for search/AI-answer queries
 * like "licensed electrician kitchen remodel".
 *
 * Content rules: everything stated must be true of how GS operates. We do NOT
 * name partner companies, and licensing language stays accurate for Illinois
 * ("licensed as required by Illinois and local municipalities" — plumbers and
 * roofers are state-licensed; electricians are licensed at municipal level).
 */

return [

    'enabled' => true,

    // Hub-page framing.
    'intro' => 'Every GS Construction remodel is delivered by a tight bench of trade partners we have '
        . 'worked with for years — licensed where Illinois or your municipality requires it, insured, and '
        . 'held to our finish standards. You sign one contract with GS and get one dedicated project lead; '
        . 'we plan, schedule, supervise, and stand behind every trade on your job.',

    'trades' => [
        [
            'slug' => 'architects',
            'name' => 'Architects',
            'short' => 'Architects',
            'licensed' => true,
            'summary' => 'Illinois-licensed architects for additions, structural changes, and permit drawings — stamped plans that get approved without resubmittal ping-pong.',
            'what' => [
                'Permit-ready drawings for additions, dormers, and structural remodels',
                'Space planning that balances what you want with what the structure and budget allow',
                'Code and zoning review — setbacks, height limits, FAR — before design gets too far',
                'Stamped plans and revisions handled directly with your village\'s building department',
            ],
            'when' => 'Additions, second stories, and any remodel your municipality requires sealed drawings for. Architecture happens first — before pricing, before permits, before demo.',
            'faq' => [
                ['q' => 'Do I need an architect for my remodel?', 'a' => 'For additions and structural changes, almost always — most Chicago-suburb villages require sealed drawings for the permit. For kitchen and bath remodels within existing walls, usually not; design and layout are handled in our normal design process.'],
                ['q' => 'Do I hire the architect or does GS?', 'a' => 'Either works. Many clients come to us with plans already drawn; otherwise we bring in an architect partner we have delivered permitted projects with, and coordinate the drawings as part of your project.'],
            ],
        ],
        [
            'slug' => 'structural-engineers',
            'name' => 'Structural Engineers',
            'short' => 'Structural Engineers',
            'licensed' => true,
            'summary' => 'Illinois-licensed structural engineers for beam sizing, load calculations, and the sealed letters your village requires before a load-bearing wall comes down.',
            'what' => [
                'Beam and header sizing when load-bearing walls are removed or openings enlarged',
                'Load calculations for additions, second stories, and roof changes',
                'Foundation and footing specifications for new construction',
                'Sealed drawings and letters required by building departments for structural permits',
            ],
            'when' => 'Open-concept wall removals, additions, and anything that changes how the house carries load. The engineer\'s sealed spec is what turns "we think this wall can go" into a permitted, inspected certainty.',
            'faq' => [
                ['q' => 'Why do I need an engineer if the contractor knows the wall is load-bearing?', 'a' => 'Because knowing it carries load and proving what replaces it are different jobs. The engineer calculates the beam, posts, and footings, and seals the spec — which your village requires for the permit and your insurer will want on record.'],
            ],
        ],
        [
            'slug' => 'interior-designers',
            'name' => 'Interior Designers',
            'short' => 'Interior Design',
            'licensed' => false,
            'summary' => 'Space planning, finishes, fixtures, and lighting design — so every selection is made once, on time, and works together in the finished room.',
            'what' => [
                'Space planning and layout studies for kitchens, baths, and living spaces',
                'Finish and fixture selection: cabinetry, tile, counters, hardware, lighting, paint',
                'Selection schedules that keep decisions ahead of the construction timeline',
                'Renderings and boards so you can see the room before demo starts',
            ],
            'when' => 'Best engaged at the very start — selections drive lead times, and lead times drive the schedule. Clients can work with our design partners or bring their own designer; we collaborate either way.',
            'faq' => [
                ['q' => 'Do I need an interior designer, or does GS handle selections?', 'a' => 'We guide selections on every project and many clients never need more. For larger remodels or clients who want a fully curated look, a designer partner is worth it — and we coordinate directly with them so design intent survives construction.'],
            ],
        ],
        [
            'slug' => 'design-showrooms',
            'name' => 'Design Showrooms',
            'short' => 'Showrooms',
            'licensed' => false,
            'summary' => 'Chicagoland kitchen and bath showrooms — like Studio41 — where you can see, touch, and choose cabinetry, fixtures, and tile with real guidance.',
            'what' => [
                'Plumbing fixture selection (faucets, sinks, tubs, shower systems) you can see and operate in person',
                'Cabinetry and vanity lines across the good/better/best price spectrum',
                'Tile, stone, and hardware libraries far beyond what any website shows',
                'Showroom consultants who work from your project\'s plan and budget, not a generic pitch',
            ],
            'when' => 'During design, before ordering. We send clients to trusted Chicagoland showrooms such as Studio41 with the project plan in hand — so what you fall in love with is what actually fits, in spec and in budget.',
            'faq' => [
                ['q' => 'Do I have to buy through a showroom?', 'a' => 'No — it is simply the best way to choose things you touch every day, like faucets and cabinet doors. We coordinate whichever route fits: showroom selections, designer-specified products, or items you source yourself.'],
            ],
        ],
        [
            'slug' => 'licensed-electricians',
            'name' => 'Licensed Electricians',
            'short' => 'Electrical',
            'licensed' => true,
            'summary' => 'Panel upgrades, new circuits, recessed lighting, and code-compliant rough-in for kitchens, bathrooms, and additions.',
            'what' => [
                'Rough-in wiring for remodeled kitchens, bathrooms, basements, and additions',
                'Panel and service upgrades when new appliances or additions increase load',
                'Recessed and under-cabinet lighting, dimmers, and smart-switch installation',
                'GFCI/AFCI protection and code corrections uncovered during demo',
            ],
            'when' => 'Practically every kitchen and bathroom remodel: layouts move, appliances upgrade, and lighting plans change. Electrical rough-in happens right after framing and before insulation and drywall.',
            'faq' => [
                ['q' => 'Are the electricians on my project licensed?', 'a' => 'Yes. Electrical work on GS projects is performed by electricians licensed as required by your municipality, and every permit-required scope is inspected before walls close.'],
                ['q' => 'Do I need a panel upgrade for a kitchen remodel?', 'a' => 'Often, yes — modern kitchens add dedicated circuits for induction ranges, ovens, and appliance garages. We evaluate your panel during the estimate so the cost is in the quote, not a surprise.'],
            ],
        ],
        [
            'slug' => 'licensed-plumbers',
            'name' => 'Licensed Plumbers',
            'short' => 'Plumbing',
            'licensed' => true,
            'summary' => 'State-licensed plumbers for supply, drain, and gas lines — moving fixtures, roughing in new baths, and passing inspection the first time.',
            'what' => [
                'Relocating sinks, toilets, tubs, and showers when the layout changes',
                'New supply and drain lines for added bathrooms and basement baths',
                'Gas line work for ranges, cooktops, and outdoor kitchens',
                'Shut-off, venting, and code corrections found during demolition',
            ],
            'when' => 'Any remodel that moves water: bathroom layout changes, kitchen sink or island relocations, basement bathroom additions. Plumbing rough-in runs alongside electrical, before insulation and drywall.',
            'faq' => [
                ['q' => 'Is plumbing work permitted and inspected?', 'a' => 'Yes. Illinois requires state-licensed plumbers, and permit-required plumbing on GS projects is inspected at rough-in and final. We pull the permits and meet the inspectors.'],
                ['q' => 'How much does it cost to move a toilet or sink?', 'a' => 'Moving a fixture a few feet typically adds $1,500–$4,000 depending on drain routing and floor structure. We price it exactly during design so you can decide with real numbers.'],
            ],
        ],
        [
            'slug' => 'hvac-contractors',
            'name' => 'HVAC Contractors',
            'short' => 'Heating & Cooling',
            'licensed' => true,
            'summary' => 'Ductwork rerouting, ventilation, and heating/cooling extensions for additions, basements, and reworked floor plans.',
            'what' => [
                'Extending supply and return ducts into additions and finished basements',
                'Range-hood and bathroom exhaust venting to the exterior (not the attic)',
                'Relocating registers and returns when walls move',
                'Right-sizing equipment when an addition increases the conditioned area',
            ],
            'when' => 'Additions, basement finishes, and any kitchen with a real exhaust hood. HVAC rough-in is coordinated with framing and before insulation.',
            'faq' => [
                ['q' => 'Does a home addition need its own furnace or AC?', 'a' => 'Usually not — most additions tie into the existing system if it has capacity. We have our HVAC partner run the load calculation early, so if equipment must grow, it is in the budget from day one.'],
            ],
        ],
        [
            'slug' => 'demolition-contractors',
            'name' => 'Demolition Contractors',
            'short' => 'Demolition',
            'licensed' => false,
            'summary' => 'Selective interior demo done surgically — dust containment, protected floors, safe disconnects, and debris gone the same week, not sitting in your driveway.',
            'what' => [
                'Selective interior demolition that removes exactly what the plan calls for — and nothing more',
                'Dust containment: sealed openings, floor protection, and negative-air setups for occupied homes',
                'Safe removal around plumbing, gas, and electrical after proper disconnects',
                'Dumpster logistics, debris hauling, and recycling of metals and clean fill',
            ],
            'when' => 'The first crew on site after permits post. Good demo is what every following trade builds on — and what keeps the rest of your house livable while one part of it is a construction zone.',
            'faq' => [
                ['q' => 'Can we live in the house during demolition?', 'a' => 'Usually yes for kitchens, baths, and additions. Work zones are sealed with dust barriers, floors are protected along access paths, and utilities to the rest of the house stay live. We plan the containment with you before the first hammer swings.'],
            ],
        ],
        [
            'slug' => 'framing-carpenters',
            'name' => 'Framing Carpenters',
            'short' => 'Framing',
            'licensed' => false,
            'summary' => 'Structural framing for additions, wall removals, and layout changes — beams, headers, and floors that inspectors sign off without drama.',
            'what' => [
                'Load-bearing wall removal with engineered beams (LVL/steel) and proper temporary shoring',
                'Framing additions, dormers, and second-story build-outs',
                'Floor system repairs and leveling uncovered during demo',
                'Window and door opening changes with correct headers',
            ],
            'when' => 'Open-concept conversions, additions, and any remodel that touches structure. Framing follows demolition and precedes every rough-in trade.',
            'faq' => [
                ['q' => 'Can you remove the wall between my kitchen and living room?', 'a' => 'Usually yes — most can be opened with an engineered beam. We confirm what is load-bearing, involve a structural engineer when required, and pull the structural permit before demo begins.'],
            ],
        ],
        [
            'slug' => 'excavation-contractors',
            'name' => 'Excavation Contractors',
            'short' => 'Excavation',
            'licensed' => false,
            'summary' => 'Site work for additions and structural repairs — footings, foundation trenches, drainage, and grading done safely around an occupied home.',
            'what' => [
                'Digging footings and foundation trenches for room additions',
                'Underground downspout, drain tile, and sump discharge lines',
                'Regrading for drainage correction around foundations',
                'Utility locates (JULIE) and safe digging around gas, water, and sewer lines',
            ],
            'when' => 'Additions with new foundations, drainage corrections, and egress-window installs for basement bedrooms.',
            'faq' => [
                ['q' => 'Will excavation wreck my yard?', 'a' => 'There is always some disturbance, but we plan machine access with you in advance, protect what can be protected, and include rough grading in the scope so the site is left ready for landscaping.'],
            ],
        ],
        [
            'slug' => 'drywall-installers',
            'name' => 'Drywall Installers',
            'short' => 'Drywall',
            'licensed' => false,
            'summary' => 'Hanging, taping, and Level 4–5 finishing — the flat, shadow-free walls that make or break how a remodel photographs.',
            'what' => [
                'Hanging and taping new walls and ceilings after rough-in inspections pass',
                'Level 4 and Level 5 smooth finishes (critical under kitchen lighting)',
                'Moisture-resistant board in bathrooms and cement board behind tile',
                'Blending new drywall seamlessly into existing plaster walls in older homes',
            ],
            'when' => 'Every remodel, right after insulation. Finish quality here determines how every painted surface looks for the life of the home.',
            'faq' => [
                ['q' => 'What is a Level 5 finish and do I need it?', 'a' => 'Level 5 adds a full skim coat over the whole surface, eliminating texture differences visible under raking light. We recommend it for kitchens and great rooms with big windows or extensive recessed lighting.'],
            ],
        ],
        [
            'slug' => 'carpenters',
            'name' => 'Carpenters',
            'short' => 'Carpentry',
            'licensed' => false,
            'summary' => 'The backbone crew of every remodel — from blocking and subfloors to doors, decks, and everything the other trades build on.',
            'what' => [
                'Door and window installation, subfloor repair, and stair work',
                'Blocking and backing for cabinets, vanities, grab bars, and TV mounts',
                'Deck, porch, and exterior repair carpentry tied into remodel scopes',
                'Coordinating dimensions so cabinetry, tile, and trim land exactly as drawn',
            ],
            'when' => 'Start to finish on every project — carpentry is the thread that connects every other trade, and no crew spends more days on site.',
            'faq' => [
                ['q' => 'Does GS use its own carpenters or subs?', 'a' => 'Carpentry on GS projects is performed by long-standing carpentry partners — crews we have worked with across many projects, not whoever is available that week. GS provides the dedicated project lead who schedules them, walks their work daily, and holds it to our punch-list standard.'],
            ],
        ],
        [
            'slug' => 'finish-carpenters',
            'name' => 'Finish Carpenters',
            'short' => 'Finish Carpentry',
            'licensed' => false,
            'summary' => 'Trim, built-ins, and cabinetry installation — the tight miters and consistent reveals you notice every day for the next twenty years.',
            'what' => [
                'Casing, baseboard, crown molding, and wainscoting with tight, glued miters',
                'Custom built-ins: mudroom lockers, window seats, bookcases, closet systems',
                'Cabinet and vanity installation — level, scribed to walls, doors aligned',
                'Interior door hanging and hardware with consistent margins',
            ],
            'when' => 'The last major phase before paint. This is where a remodel starts looking like the renderings — and where we refuse to rush.',
            'faq' => [
                ['q' => 'What is the difference between a carpenter and a finish carpenter?', 'a' => 'Rough carpentry builds the structure; finish carpentry is the visible woodwork. Finish work is measured in 32nds of an inch, which is why we keep dedicated finish carpenters on the bench.'],
            ],
        ],
        [
            'slug' => 'insulation-contractors',
            'name' => 'Insulation Contractors',
            'short' => 'Insulation',
            'licensed' => false,
            'summary' => 'Batt, blown-in, and spray-foam insulation plus air sealing — comfort and energy performance installed between rough-in and drywall.',
            'what' => [
                'Wall, ceiling, and rim-joist insulation for remodeled spaces and additions',
                'Closed-cell spray foam where moisture or space demands it (basements, cathedral ceilings)',
                'Air sealing around penetrations before drywall closes everything in',
                'Sound insulation for bathrooms, laundry rooms, and bedroom walls',
            ],
            'when' => 'After all rough-ins pass inspection and before drywall. Additions and basements live or die on this phase — Chicago winters do not forgive skipped air sealing.',
            'faq' => [
                ['q' => 'Is spray foam worth the extra cost?', 'a' => 'In rim joists, basements, and cathedral ceilings — usually yes, because it insulates and air-seals in one step. In standard walls, high-density batts done carefully perform well for less. We quote both when it is a close call.'],
            ],
        ],
        [
            'slug' => 'roofing-contractors',
            'name' => 'Roofing Contractors',
            'short' => 'Roofing',
            'licensed' => true,
            'summary' => 'State-licensed roofers for tie-ins where additions and dormers meet the existing roof — flashed and warrantied, not just shingled.',
            'what' => [
                'Roof tie-ins where addition and dormer roofs meet the existing structure',
                'Full re-roofs scheduled as part of larger exterior remodels',
                'Flashing, ice-and-water shield, and ventilation done to manufacturer spec',
                'Skylight and sun-tunnel installation with leak-free integration',
            ],
            'when' => 'Additions, dormers, and exterior packages. The tie-in between new and old roof is the highest-risk leak point on any addition — we only put licensed, proven roofers on it.',
            'faq' => [
                ['q' => 'Are roofers licensed in Illinois?', 'a' => 'Yes — Illinois requires roofing contractors to hold a state roofing license. The roofing partners on GS projects carry it, plus the insurance certificates we keep on file.'],
            ],
        ],
        [
            'slug' => 'siding-contractors',
            'name' => 'Siding Contractors',
            'short' => 'Siding',
            'licensed' => false,
            'summary' => 'James Hardie, LP SmartSide, cedar, and vinyl — blending addition exteriors invisibly into the existing home or re-cladding it entirely.',
            'what' => [
                'Matching and blending siding where additions meet the original house',
                'Full re-sides with proper housewrap, flashing, and trim details',
                'Fiber-cement (James Hardie) and engineered-wood (LP SmartSide) installation',
                'Soffit, fascia, and exterior trim replacement',
            ],
            'when' => 'Additions and exterior refreshes. A well-built addition can still look "stuck on" if the siding blend is lazy — this is a detail we supervise closely.',
            'faq' => [
                ['q' => 'Can you match my existing siding?', 'a' => 'Often yes — and when an exact match no longer exists, we design an intentional transition (board-and-batten accents, color-matched panels) so the addition looks original rather than approximated.'],
            ],
        ],
        [
            'slug' => 'masonry-contractors',
            'name' => 'Masonry Contractors',
            'short' => 'Masonry & Brick',
            'licensed' => false,
            'summary' => 'Brick, block, and stone — matching existing brick on additions, tuckpointing, chimneys, and stone veneer done by real masons.',
            'what' => [
                'Brick matching and toothing-in where additions meet existing brick homes',
                'Foundation block work for additions and structural repairs',
                'Tuckpointing and lintel replacement on older Chicago-area brick',
                'Stone veneer for fireplaces, kitchens, and exterior accents',
            ],
            'when' => 'Brick-home additions, fireplace remodels, and exterior restoration. Matching 60-year-old Chicago common brick is a craft — our masonry partners have done it for years.',
            'faq' => [
                ['q' => 'Can new brick really match my old brick?', 'a' => 'A skilled mason gets remarkably close using reclaimed or blended brick and tinted mortar — and mortar color matters as much as the brick. We build a sample panel for approval before laying the wall.'],
            ],
        ],
        [
            'slug' => 'tile-setters',
            'name' => 'Tile Setters',
            'short' => 'Tile',
            'licensed' => false,
            'summary' => 'Showers, backsplashes, and floors — waterproofed substrates, level large-format tile, and grout lines that stay straight around corners.',
            'what' => [
                'Shower systems with modern waterproofing (Schluter and equivalent) — not just cement board',
                'Large-format and porcelain-slab tile with lippage-free flat installs',
                'Kitchen backsplashes, herringbone and mosaic feature walls',
                'Heated tile floors wired by our licensed electrical partners',
            ],
            'when' => 'Nearly every kitchen and bathroom. Tile failures are almost always waterproofing failures — which is why we control the substrate, not just the tile.',
            'faq' => [
                ['q' => 'Why do tile quotes vary so much?', 'a' => 'The invisible parts: waterproofing system, substrate prep, and layout time. A cheap tile job skips those and looks fine for a year. Our quotes itemize the system underneath so you know what you are comparing.'],
            ],
        ],
        [
            'slug' => 'painters',
            'name' => 'Painters',
            'short' => 'Painting',
            'licensed' => false,
            'summary' => 'Interior and cabinet-grade finishing — proper prep, sprayed trim and doors, and clean lines that hold up to daylight.',
            'what' => [
                'Full interior repaints after remodel work, walls to ceilings to trim',
                'Sprayed finishes on trim, doors, and built-ins for a factory-smooth result',
                'Cabinet painting and refinishing with catalyzed, kitchen-grade coatings',
                'Drywall priming and finish-coat quality control under raking light',
            ],
            'when' => 'The final phase of every remodel, after finish carpentry. We schedule painters last and protect their work through punch-list.',
            'faq' => [
                ['q' => 'Is cabinet painting durable?', 'a' => 'With cabinet-grade catalyzed coatings, professional spray equipment, and real prep — yes, it wears like a factory finish. Brushed wall paint on cabinet doors is what gives cabinet painting a bad name.'],
            ],
        ],
        [
            'slug' => 'flooring-installers',
            'name' => 'Flooring Installers',
            'short' => 'Flooring',
            'licensed' => false,
            'summary' => 'Hardwood installation, sanding and refinishing, LVP, and seamless lace-ins where new floors meet original oak.',
            'what' => [
                'Site-finished and prefinished hardwood installation',
                'Lacing new hardwood into existing floors so additions read as original',
                'Whole-floor sanding and refinishing with dust-contained equipment',
                'Luxury vinyl plank (LVP) for basements and wet areas',
            ],
            'when' => 'Most whole-home remodels and additions. Blending new flooring into a 1960s oak floor is one of the most-requested details on our North Shore projects.',
            'faq' => [
                ['q' => 'Can you match my existing hardwood?', 'a' => 'Usually — we lace new boards into the old field, then sand and finish everything as one surface. After finishing, the transition is effectively invisible.'],
            ],
        ],
        [
            'slug' => 'countertop-fabricators',
            'name' => 'Countertop Fabricators',
            'short' => 'Countertops',
            'licensed' => false,
            'summary' => 'Quartz, granite, and porcelain slab — laser templating, precise seams, and installs coordinated to keep your kitchen timeline moving.',
            'what' => [
                'Laser templating after cabinets are set, for exact fit around walls that are never square',
                'Quartz, granite, quartzite, and porcelain slab fabrication',
                'Waterfall edges, integrated drainboards, and full-height slab backsplashes',
                'Sink cutouts and mounting coordinated with our plumbing partners',
            ],
            'when' => 'Every kitchen and most bath remodels. Fabrication takes 5–10 business days after template — we sequence it so plumbing reconnects the same week counters land.',
            'faq' => [
                ['q' => 'Why is there a gap between cabinets and countertops in the schedule?', 'a' => 'Templating cannot happen until cabinets are permanently set, and fabrication runs about a week after that. We use the gap for tile, paint, and electrical finish so no day is wasted.'],
            ],
        ],
    ],

];
