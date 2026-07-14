<?php

/**
 * Insurance-claim repair pages (/insurance-claims and /insurance-claims/{slug}).
 *
 * Positioning (keep every page inside these lines):
 *  - GS Construction is a licensed general contractor that DOCUMENTS damage,
 *    provides itemized repair estimates, meets the homeowner's adjuster on
 *    site, and REBUILDS. We are NOT a public adjuster or an insurance company
 *    and never promise coverage, negotiate claims, or interpret policies —
 *    Illinois regulates public adjusting, so no page may imply we do it.
 *  - Emergency mitigation (water extraction, board-up, drying) is a different
 *    industry; our lane is the rebuild after mitigation. Say so honestly —
 *    it is also our strongest pitch ("after the drying fans leave, we put
 *    your home back").
 *  - Coverage notes are general education with explicit "check your policy"
 *    hedges — never a coverage determination.
 */

return [

    'enabled' => true,

    'intro' => 'Storm, water, and fire damage puts you in a process most homeowners have never been through: '
        . 'mitigation crews, adjusters, scope sheets, and then — the part nobody lines up for you — the rebuild. '
        . 'That last part is what we do. GS Construction documents the damage, gives you an itemized repair '
        . 'estimate your adjuster can work with line by line, meets the adjuster on site when helpful, and '
        . 'rebuilds your home to pre-loss condition or better.',

    'disclaimer' => 'GS Construction & Remodeling is a licensed, bonded, and insured general contractor — not a public '
        . 'adjuster, attorney, or insurance company. What your policy covers is determined by your policy and your '
        . 'insurer. The notes on these pages are general information, not coverage advice.',

    'claims' => [
        [
            'slug' => 'water-and-flood-damage',
            'name' => 'Water & Flood Damage',
            'short' => 'Water damage',
            'h1' => 'Water damage rebuilds after the claim',
            'answer' => 'After a burst pipe, appliance leak, or sump failure, the mitigation company dries the structure — then you\'re left with cut-open walls, torn-out floors, and missing trim. GS Construction handles that rebuild: drywall, flooring, cabinetry, trim, and paint, restored to pre-loss condition with an itemized estimate your adjuster can review line by line.',
            'coverage_notes' => [
                ['point' => 'Sudden water vs. rising water', 'note' => 'Homeowner policies generally treat sudden internal water (burst pipe, appliance failure) differently from rising floodwater, which typically requires separate flood coverage. Your policy and insurer determine what applies — read the claim scope carefully.'],
                ['point' => 'Mitigation vs. rebuild', 'note' => 'The drying/extraction phase and the rebuild phase are usually separate scopes on the same claim. Make sure the rebuild scope includes everything the mitigation crew removed — baseboard, drywall cuts, flooring, vanities.'],
                ['point' => 'Matching materials', 'note' => 'When half a floor is destroyed, the question of whether the undamaged half gets replaced to match is one of the most negotiated lines in a water claim. Document the continuous flooring in photos early.'],
            ],
            'steps' => [
                'Stop the source and get mitigation started — drying comes before any rebuild decisions.',
                'Photograph everything before and during tear-out: floors, baseboard heights, cabinet runs.',
                'Keep the mitigation company\'s scope and moisture logs — the rebuild estimate builds on them.',
                'Get an itemized rebuild estimate (ours are free) before agreeing to the insurer\'s scope numbers.',
            ],
            'rebuild_scope' => 'Drywall and insulation replacement, subfloor repair, hardwood lacing and refinishing or new flooring, baseboard and trim, vanity and cabinet replacement, interior doors, and full repaint — sequenced by one project lead through our licensed plumbing and electrical partners where the loss touched them.',
            'faq' => [
                ['question' => 'Do you handle the water extraction and drying?', 'answer' => 'No — emergency mitigation is its own trade and speed matters, so call a mitigation company (or your insurer\'s emergency line) first. We take over at the rebuild: everything the drying crew tore out, we put back.'],
                ['question' => 'Is a finished-basement flood covered by insurance?', 'answer' => 'It depends on the cause: sudden internal water like a burst pipe or failed sump is treated differently from overland flooding, which usually needs separate flood coverage. Your insurer determines coverage — we document the damage and price the rebuild either way.'],
                ['question' => 'Can you match my existing hardwood floors?', 'answer' => 'Usually yes — we lace new boards into the undamaged field, then sand and refinish everything as one surface, so the repair effectively disappears.'],
            ],
        ],
        [
            'slug' => 'roof-damage',
            'name' => 'Roof Damage',
            'short' => 'Roof damage',
            'h1' => 'Hail and wind roof damage, repaired right',
            'answer' => 'Chicagoland hail and windstorms damage roofs in ways you can\'t see from the driveway — lifted shingles, bruised mats, torn flashing. GS Construction documents the damage with photos, provides an itemized repair or replacement estimate, and executes the work through Illinois state-licensed roofing partners, tied into gutters, flashing, and any interior water staining the leak caused.',
            'coverage_notes' => [
                ['point' => 'Storm date matters', 'note' => 'Claims reference a specific storm event. Note the date of the hail or wind storm and file promptly — most policies have time limits on storm claims.'],
                ['point' => 'Repair vs. full replacement', 'note' => 'Whether the insurer scopes a repair, a slope, or the full roof depends on damage distribution and shingle availability. Discontinued shingles that can\'t be matched are a common path to full-replacement scopes.'],
                ['point' => 'Interior damage rides along', 'note' => 'Ceiling stains and attic insulation hit by the same leak belong on the same claim — make sure the scope covers interior repairs, not just shingles.'],
            ],
            'steps' => [
                'After a storm, get a ground-level look and note the date — don\'t climb the roof.',
                'Get a documented inspection with photos before filing, so you know what you\'re claiming.',
                'Tarp promptly if there\'s active leaking (your insurer\'s emergency line can dispatch this).',
                'Have the itemized repair estimate in hand when the adjuster visits — we can meet them on site.',
            ],
            'rebuild_scope' => 'Shingle repair or full replacement through our state-licensed roofing partners (Illinois licenses roofing contractors), flashing and ventilation to manufacturer spec, gutters and downspouts, plus the interior side — ceiling drywall, insulation, and paint where the leak reached.',
            'faq' => [
                ['question' => 'Are your roofers licensed in Illinois?', 'answer' => 'Yes — Illinois requires roofing contractors to hold a state roofing license, and the roofing partners on GS projects carry it plus the insurance certificates we keep on file.'],
                ['question' => 'Should I file a claim for a few missing shingles?', 'answer' => 'Get a documented inspection first. If the damage is minor and below your deductible, a straight repair may make more sense — we\'ll tell you honestly what we see and price both paths.'],
                ['question' => 'Do you meet the insurance adjuster on site?', 'answer' => 'When it helps, yes — we walk the adjuster through our photo documentation and itemized estimate so the scope discussion is about specifics, not generalities. Coverage decisions remain between you and your insurer.'],
            ],
        ],
        [
            'slug' => 'siding-and-exterior-damage',
            'name' => 'Siding & Exterior Damage',
            'short' => 'Siding damage',
            'h1' => 'Hail-cracked and wind-torn siding, made whole',
            'answer' => 'Hail cracks vinyl, dents aluminum, and chips fiber-cement; wind peels panels off whole elevations. GS Construction documents every elevation, prices the repair honestly — including whether your siding profile is still manufactured — and rebuilds the exterior: siding, soffit, fascia, gutters, and window wraps, blended so the repair doesn\'t read as a patch.',
            'coverage_notes' => [
                ['point' => 'Discontinued siding is the pivot point', 'note' => 'If your profile or color is no longer made, matching becomes impossible — which is often the difference between a one-elevation scope and a larger one. We verify availability with suppliers and document it.'],
                ['point' => 'Check every elevation', 'note' => 'Hail rarely hits just one wall. Document all four elevations plus soffit, fascia, gutters, screens, and AC fins — the scope should reflect the whole loss.'],
                ['point' => 'Cosmetic vs. functional damage', 'note' => 'Some policies distinguish cosmetic denting from functional damage, especially on metal. Know which language your policy uses before the adjuster visit.'],
            ],
            'steps' => [
                'Photograph all four elevations in good light, close-up and wide, right after the storm.',
                'Collect a sample or note the siding brand/profile if you know it — matching drives the scope.',
                'Don\'t accept a one-wall patch scope before match availability is verified in writing.',
                'Get an itemized estimate covering siding, soffit, fascia, gutters, and wraps together.',
            ],
            'rebuild_scope' => 'Vinyl, aluminum, fiber-cement (James Hardie), and LP SmartSide siding repair or full re-side with proper housewrap and flashing, soffit and fascia, seamless gutters, and window/door wraps — executed with the same siding partners who blend our addition exteriors invisibly.',
            'faq' => [
                ['question' => 'My siding color is discontinued — what happens?', 'answer' => 'We document the discontinuation with supplier confirmation, which becomes part of the claim discussion about how much gets replaced to achieve a uniform result. Where a full match is impossible, we design intentional transitions that look original.'],
                ['question' => 'Can you repair just the damaged panels?', 'answer' => 'When the profile is still available, yes — panel-level repair is often the honest answer. We price both the repair and the larger scope so you can compare with real numbers.'],
            ],
        ],
        [
            'slug' => 'storm-and-tree-damage',
            'name' => 'Storm & Tree Damage',
            'short' => 'Storm damage',
            'h1' => 'When the tree comes down, we put the house back',
            'answer' => 'A fallen limb through the roof or a microburst that takes fence, deck, and garage door in one night is a multi-trade rebuild: structure, roof, exterior, and interior finishes. That\'s the job a general contractor exists for — GS Construction sequences the whole restoration under one itemized estimate and one project lead instead of leaving you to coordinate four separate companies.',
            'coverage_notes' => [
                ['point' => 'Structure first', 'note' => 'Impact damage can crack rafters and shift framing beyond what\'s visible. A structural assessment belongs in the scope before finishes are priced — our structural engineer partners provide sealed evaluations when needed.'],
                ['point' => 'Tree removal vs. rebuild', 'note' => 'Getting the tree off the house is usually an emergency-services line on the claim, separate from the repair scope. Keep those invoices — they\'re part of the same loss.'],
                ['point' => 'Detached structures', 'note' => 'Garages, fences, and sheds are typically covered under separate policy limits from the dwelling. List every damaged structure when filing.'],
            ],
            'steps' => [
                'Tarp and board up first — preventing further damage is both urgent and expected by insurers.',
                'Photograph the tree on the structure before removal if it\'s safe to do so.',
                'Save every emergency invoice: tree removal, tarping, board-up.',
                'Get one itemized rebuild estimate covering structure, roof, exterior, and interior together.',
            ],
            'rebuild_scope' => 'Structural framing repair with engineer-sealed specs where required, roof tie-in and replacement through licensed roofing partners, siding and gutter restoration, garage doors, decks and fences, and the interior finish work — drywall, flooring, trim, paint — sequenced by one project lead.',
            'faq' => [
                ['question' => 'The tree is still on my roof — who do I call first?', 'answer' => 'Your insurer\'s emergency line and a tree service — removal and tarping come before anything else. Once the structure is protected, we assess, document, and price the full rebuild.'],
                ['question' => 'Do you rebuild garages and decks too?', 'answer' => 'Yes — detached garages, decks, fences, and porches are part of the same restoration scope, so the whole property comes back together instead of trade by trade.'],
            ],
        ],
        [
            'slug' => 'fire-and-smoke-damage',
            'name' => 'Fire & Smoke Damage',
            'short' => 'Fire damage',
            'h1' => 'Rebuilding after fire and smoke',
            'answer' => 'After the fire department and the mitigation crew are done, a fire loss becomes a rebuild project: structural repairs, full-room gut-and-replace where the fire burned, and finish restoration where smoke traveled. GS Construction rebuilds fire-damaged kitchens, rooms, and whole floors with the same crews and finish standards as our remodels — with an itemized scope your adjuster can review line by line.',
            'coverage_notes' => [
                ['point' => 'Burn zone vs. smoke zone', 'note' => 'The rooms that burned and the rooms smoke reached are usually scoped differently — cleaning and sealing vs. full replacement. Odor that survives cleaning is a common reason smoke-zone scopes get revisited.'],
                ['point' => 'Code upgrades', 'note' => 'Rebuilding often triggers current-code requirements (hardwired smoke detectors, AFCI circuits) that didn\'t exist when the house was built. Many policies carry ordinance-or-law coverage for exactly this — worth checking yours.'],
                ['point' => 'Contents vs. structure', 'note' => 'Cabinets and built-ins are structure; furniture is contents. They\'re separate claim categories — make sure built-ins land on the structure scope.'],
            ],
            'steps' => [
                'Wait for the all-clear, then let mitigation handle soot, odor, and pack-out first.',
                'Photograph every room — including ones that only smell of smoke.',
                'Ask your insurer about ordinance-or-law coverage before the rebuild scope is finalized.',
                'Get the itemized rebuild estimate early — fire scopes are long, and line-by-line clarity prevents disputes.',
            ],
            'rebuild_scope' => 'Structural framing repair with engineered specs, full electrical and plumbing replacement in burn zones through our licensed trade partners, insulation and drywall, kitchens and bathrooms rebuilt to remodel standard, flooring, trim, doors, and complete repaint — brought to current code with permits and inspections handled.',
            'faq' => [
                ['question' => 'Do you do the smoke and soot cleanup?', 'answer' => 'No — soot removal, odor treatment, and pack-out are mitigation specialties that come first. We take the project from there: the structural and finish rebuild that turns the house back into your home.'],
                ['question' => 'Will the rebuilt rooms match the rest of the house?', 'answer' => 'That\'s the point of using a remodeling contractor for the rebuild — trim profiles, floor species, and paint are matched or intentionally upgraded, not approximated. Fire rebuilds get the same finish standard as our full remodels.'],
            ],
        ],
    ],

];
