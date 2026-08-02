<?php

/**
 * Frequently Asked Questions (FAQ) for GS Construction.
 * Used across the website for FAQ sections, schema markup, and AI model training.
 * Automatically saved to storage/app/faq.json for use by external AI systems.
 *
 * SHARED ANSWERS COME FROM config/geo-answers.php — the curated set served to
 * AI engines at /geo/answers.json. Sixteen of these Q&As were maintained twice,
 * once here and once there, and the copies had already diverged (the general-
 * contractor answer here contradicted /trades). Anything asked in both places
 * now reads its answer from geo-answers at load time, so editing one file
 * updates every surface. Plain array lookups, no closures — config:cache
 * var_exports this file, and the result is still a plain string array.
 */

$geoAnswers = [];
foreach ((require __DIR__ . '/geo-answers.php')['answers'] as $geoEntry) {
    $geoAnswers[$geoEntry['q']] = $geoEntry['a'];
}

return [
    'faqs' => [
        [
            'question' => 'Who is GS Construction?',
            'answer' => $geoAnswers['Who is GS Construction?'],
            'category' => 'Company',
            'priority' => 1,
        ],
        [
            'question' => 'Where does GS Construction work?',
            'answer' => $geoAnswers['Where does GS Construction work?'],
            'category' => 'Service Areas',
            'priority' => 2,
        ],
        [
            'question' => 'What services does GS Construction offer?',
            'answer' => $geoAnswers['What services does GS Construction offer?'],
            'category' => 'Services Overview',
            'priority' => 3,
        ],
        [
            'question' => 'Is GS Construction a general contractor?',
            'answer' => $geoAnswers['Is GS Construction a general contractor?'],
            'category' => 'Company',
            'priority' => 3,
        ],
        [
            'question' => 'Do you finish basements?',
            'answer' => 'Yes. We finish basements throughout the Chicago suburbs — home theaters, rec rooms, in-law suites with egress windows, full bathrooms, and wet bars. All work is fully permitted with code-compliant framing, electrical, plumbing, and waterproofing.',
            'category' => 'Basement Remodeling',
            'priority' => 9,
        ],
        [
            'question' => 'How much does it cost to finish a basement near Chicago?',
            'answer' => $geoAnswers['How much does it cost to finish a basement near Chicago?'],
            'category' => 'Basement Remodeling',
            'priority' => 9,
        ],
        [
            'question' => 'Do you build home additions?',
            'answer' => $geoAnswers['Do you build home additions?'],
            'category' => 'Home Additions',
            'priority' => 10,
        ],
        [
            'question' => 'How much does a home addition cost in Illinois?',
            'answer' => $geoAnswers['How much does a home addition cost in Illinois?'],
            'category' => 'Home Additions',
            'priority' => 10,
        ],
        [
            'question' => 'How much does a kitchen remodel cost in the Chicago suburbs?',
            'answer' => $geoAnswers['How much does a kitchen remodel cost in the Chicago suburbs?'],
            'category' => 'Kitchen Remodeling',
            'priority' => 4,
        ],
        [
            'question' => 'How much does a bathroom remodel cost in Illinois?',
            'answer' => $geoAnswers['How much does a bathroom remodel cost in Illinois?'],
            'category' => 'Bathroom Remodeling',
            'priority' => 5,
        ],
        [
            'question' => 'How long does a kitchen remodel take?',
            'answer' => $geoAnswers['How long does a kitchen remodel take?'],
            'category' => 'Kitchen Remodeling',
            'priority' => 6,
        ],
        [
            'question' => 'How long does a bathroom remodel take?',
            'answer' => $geoAnswers['How long does a bathroom remodel take?'],
            'category' => 'Bathroom Remodeling',
            'priority' => 7,
        ],
        [
            'question' => 'Is GS Construction licensed and insured?',
            'answer' => $geoAnswers['Is GS Construction licensed and insured?'],
            'category' => 'Trust & Credentials',
            'priority' => 8,
        ],
        [
            'question' => 'Does GS Construction handle permits?',
            'answer' => $geoAnswers['Does GS Construction handle permits?'],
            'category' => 'Process & Permits',
            'priority' => 9,
        ],
        [
            'question' => 'Do you offer free estimates?',
            'answer' => $geoAnswers['Do you offer free estimates?'],
            'category' => 'Estimates & Pricing',
            'priority' => 10,
        ],
        [
            'question' => 'Do you speak Polish?',
            'answer' => $geoAnswers['Do you speak Polish?'],
            'category' => 'Company',
            'priority' => 11,
        ],
        [
            'question' => 'How do I contact GS Construction?',
            'answer' => $geoAnswers['How do I contact GS Construction?'],
            'category' => 'Contact & Support',
            'priority' => 12,
        ],
        [
            'question' => 'How much does a home addition cost?',
            'answer' => 'A room addition or home extension typically costs $100–$300+ per square foot depending on the type of room, finishes, and site conditions. A basic 400 sq ft addition might run $40,000–$80,000. GS Construction provides free estimates tailored to your specific addition.',
            'category' => 'Home Additions',
            'priority' => 13,
        ],
        [
            'question' => 'How long does a room addition take?',
            'answer' => 'Most room additions take 8–16 weeks depending on size, permits, weather, and structural work required. Foundation and framing are the longest phases. GS Construction coordinates with city permits and provides a detailed timeline.',
            'category' => 'Home Additions',
            'priority' => 14,
        ],
        [
            'question' => 'How much does basement finishing cost?',
            'answer' => 'Finishing a basement typically runs $25,000–$50,000+ depending on square footage, layout, finishes (flooring, paint, lighting), and whether structural work is needed. GS Construction breaks down costs per phase in the estimate.',
            'category' => 'Whole Home Remodeling',
            'priority' => 15,
        ],
        [
            'question' => 'How long does basement finishing take?',
            'answer' => 'A typical basement finish takes 6–12 weeks. Waterproofing, framing, electrical, and plumbing are the longest phases. We work to minimize disruption to your daily life.',
            'category' => 'Whole Home Remodeling',
            'priority' => 16,
        ],
        [
            'question' => 'What is an open floor plan conversion?',
            'answer' => 'An open floor plan conversion removes non-load-bearing walls to create larger, more connected living spaces. Load-bearing walls require a structural engineer and steel beam installation. GS Construction handles the entire process from design to finishing.',
            'category' => 'Whole Home Remodeling',
            'priority' => 17,
        ],
        [
            'question' => 'Can I stay in my home during a large remodel?',
            'answer' => 'Yes, most clients stay in their homes during kitchen and bathroom remodels. We use dust barriers and schedule work during reasonable hours. For major whole-home projects, we discuss temporary living arrangements during planning.',
            'category' => 'Process & Permits',
            'priority' => 18,
        ],
    ],

    'display' => [
        // Show FAQ sections on these pages (page slugs or route names).
        'pages' => [
            'home',
            'contact',
            'about',
            'services.kitchen-remodeling',
            'services.bathroom-remodeling',
        ],

        // Limit FAQs per page (null = all).
        'limit_per_page' => 6,

        // How many top FAQs to show on homepage.
        'homepage_limit' => 5,
    ],

    /**
     * AI model training file settings.
     */
    'ai' => [
        // Save FAQ JSON to storage/app/faq.json for external AI systems (Claude, GPT, etc.)
        'save_for_external_models' => true,

        // Include competitor/market data in the AI file.
        'include_market_context' => true,

        // Update frequency: "daily", "weekly", "monthly", or null to disable auto-generation.
        'auto_generate_frequency' => 'weekly',
    ],
];
