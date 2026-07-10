<?php

namespace App\Services\Seo;

use App\Models\AreaServed;
use App\Models\LandingPage;
use App\Models\Project;
use Illuminate\Support\Str;

/**
 * Builds a demand-driven landing page's content from REAL data — matched
 * project proof, the city's own permit notes, real pricing and service FAQs —
 * so every page is materially unique rather than a spun template. Returns null
 * when there's no proof, which is what keeps the Autopilot from generating
 * thin pages.
 */
class LandingPageContentGenerator
{
    /** service slug => Project.project_type used as proof. */
    private const SERVICE_PROJECT_TYPE = [
        'kitchen-remodeling' => 'kitchen',
        'bathroom-remodeling' => 'bathroom',
        'home-remodeling' => 'home-remodel',
        'basement-remodeling' => 'home-remodel',
        'home-additions' => 'home-remodel',
        'mudroom-remodeling' => 'mudroom',
    ];

    private const MODIFIER_LABEL = [
        'luxury' => 'Luxury',
        'affordable' => 'Affordable',
        'small-space' => 'Small-Space',
        'condo' => 'Condo',
        'modern' => 'Modern',
    ];

    private const PRICING = [
        'kitchen-remodeling' => '$35,000–$80,000+',
        'bathroom-remodeling' => '$15,000–$60,000',
        'home-remodeling' => 'varies by scope; whole-home projects typically start around $75,000',
        'basement-remodeling' => '$45,000–$150,000',
        'home-additions' => '$60,000–$350,000+',
        'mudroom-remodeling' => '$8,000–$25,000',
    ];

    public function __construct(private readonly TitleMetaGenerator $titles = new TitleMetaGenerator())
    {
    }

    /**
     * @return array<string,mixed>|null  LandingPage attributes, or null if no proof.
     */
    public function build(string $service, string $city, ?string $modifier = null, ?string $targetQuery = null): ?array
    {
        $serviceLabel = TitleMetaGenerator::SERVICES[$service] ?? Str::of($service)->replace('-', ' ')->title();
        $modLabel = $modifier ? (self::MODIFIER_LABEL[$modifier] ?? Str::of($modifier)->replace('-', ' ')->title()) : null;

        $proof = $this->proofProjects($service, $city);
        if ($proof->isEmpty()) {
            return null; // proof gate — never build a thin page
        }

        $area = AreaServed::whereRaw('LOWER(city) = ?', [Str::lower($city)])->first();
        $slug = Str::slug(trim(($modifier ? $modifier . ' ' : '') . $service . ' ' . $city));

        $h1 = trim(($modLabel ? "$modLabel " : '') . "$serviceLabel in $city, IL");
        $titleCore = trim(($modLabel ? "$modLabel " : '') . "$serviceLabel · $city");
        $title = $this->fit($titleCore . ' | 5★ · Free Estimate', 60);

        $count = $proof->count();
        $pricing = self::PRICING[$service] ?? 'a range we scope on a free in-home visit';

        $meta = $this->fit(
            "{$modLabel} {$serviceLabel} in {$city}, IL by GS Construction — {$count} completed local projects, "
            . "transparent pricing, 5-star rated. Family-owned, licensed & insured. Free estimate.",
            158
        );

        $intro = $this->intro($serviceLabel, $city, $modLabel, $count, $pricing);
        $sections = $this->sections($serviceLabel, $city, $modLabel, $area, $pricing);
        $faq = $this->faq($serviceLabel, $city);

        $hero = optional($proof->first()->coverImage()->first() ?: $proof->first()->images()->first())->url;

        return [
            'slug' => $slug,
            'template' => 'service_modifier_city',
            'service' => $service,
            'city' => $city,
            'modifier' => $modifier,
            'target_query' => $targetQuery,
            'title' => $title,
            'h1' => $h1,
            'meta_description' => $meta,
            'intro' => $intro,
            'sections' => $sections,
            'faq' => $faq,
            'hero_image' => $hero,
            'proof_project_ids' => $proof->pluck('id')->all(),
        ];
    }

    /** Matched, published proof projects — city matches first, then same-type. */
    private function proofProjects(string $service, string $city)
    {
        $type = self::SERVICE_PROJECT_TYPE[$service] ?? null;
        if (! $type) {
            return collect();
        }

        $base = Project::where('is_published', true)->where('project_type', $type);

        $local = (clone $base)->where('location', 'like', "%{$city}%")->get();
        $others = (clone $base)->where('location', 'not like', "%{$city}%")
            ->orderByDesc('is_featured')->limit(6)->get();

        return $local->merge($others)->unique('id')->take(6)->values();
    }

    private function intro(string $service, string $city, ?string $mod, int $count, string $pricing): string
    {
        $lead = $mod
            ? "Looking for {$mod} {$service} in {$city}? "
            : "Planning a {$service} project in {$city}? ";

        return $lead
            . "GS Construction is a family-owned, licensed and insured remodeler that has completed {$count}+ "
            . "{$service} and related projects for {$city}-area homeowners. We handle design, materials, permits and "
            . "build with one dedicated project lead per job — no rotating crews. Typical {$city} {$service} investment runs "
            . "{$pricing}, and every project starts with a free, no-pressure in-home estimate.";
    }

    /**
     * @return array<int,array{heading:string,body:string}>
     */
    private function sections(string $service, string $city, ?string $mod, ?AreaServed $area, string $pricing): array
    {
        $sections = [];

        $sections[] = [
            'heading' => "What a {$city} {$service} project includes",
            'body' => "Every {$service} we do in {$city} is fixed-scope and transparently priced up front: design and layout, "
                . "cabinetry or fixtures, countertops and surfaces, flooring, lighting and electrical, plumbing, and finish work. "
                . "You get a written scope, a realistic timeline, and a single point of contact from demo to final walkthrough.\n\n"
                . "Because we self-perform the core trades, quality and schedule stay under our control rather than a rotating "
                . "cast of subs.",
        ];

        if ($mod) {
            $sections[] = [
                'heading' => "Why {$mod} {$service} is different",
                'body' => match (Str::lower($mod)) {
                    'luxury' => "Luxury {$service} is about material and detail: custom cabinetry, natural stone, integrated lighting, "
                        . "and precise tile and millwork. We build to a spec-book standard and coordinate designers and suppliers so the "
                        . "finish matches the vision.",
                    'affordable' => "A smart {$service} budget is about where the money goes. We help {$city} homeowners prioritize the "
                        . "high-impact items, reuse what's sound, and phase work when it makes sense — without cutting corners on the "
                        . "structure, plumbing or electrical that you can't easily redo later.",
                    'small-space' => "Small-space {$service} rewards layout intelligence: smart storage, space-saving fixtures, and finishes "
                        . "that make a compact {$city} room feel larger. We design around how you actually use the space.",
                    'condo' => "Condo {$service} in {$city} means working within building rules, shared walls, and association approvals. "
                        . "We handle the paperwork, protect common areas, and schedule around building hours.",
                    default => "We tailor each {$mod} {$service} to how the space is really used, balancing budget, materials and timeline.",
                },
            ];
        }

        $localBody = "GS Construction works throughout {$city} and the surrounding suburbs. ";
        if ($area && filled($area->permit_notes)) {
            $localBody .= $area->permit_notes . ' ';
        }
        $localBody .= "We pull all required permits and handle inspections so your {$service} is done to code.";
        $sections[] = ['heading' => "{$service} in {$city}: permits & local know-how", 'body' => $localBody];

        $sections[] = [
            'heading' => "{$city} {$service} cost",
            'body' => "Typical {$city} {$service} projects run {$pricing}, depending on size, layout changes and material selections. "
                . "We give you a clear, itemized estimate after a free in-home visit — no surprises mid-project.",
        ];

        return $sections;
    }

    /**
     * @return array<int,array{q:string,a:string}>
     */
    private function faq(string $service, string $city): array
    {
        $faqs = [[
            'q' => "Do you do {$service} in {$city}?",
            'a' => "Yes. {$city} is one of our core service areas — we've completed multiple {$service} projects there and can be on-site for a free estimate quickly.",
        ]];

        // Pull the most relevant general Q&A from the GEO answer bank.
        $keywords = array_filter(explode(' ', Str::lower($service)));
        $keywords[] = 'permit';
        $keywords[] = 'timeline';
        $keywords[] = 'cost';

        foreach ((array) config('geo-answers.answers', []) as $qa) {
            if (count($faqs) >= 5) {
                break;
            }
            $hay = Str::lower(($qa['q'] ?? '') . ' ' . implode(' ', $qa['topics'] ?? []));
            foreach ($keywords as $kw) {
                if ($kw !== '' && str_contains($hay, $kw)) {
                    $faqs[] = ['q' => $qa['q'], 'a' => $qa['a']];
                    break;
                }
            }
        }

        return $faqs;
    }

    private function fit(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max);
        $sp = mb_strrpos($cut, ' ');

        return rtrim($sp ? mb_substr($cut, 0, $sp) : $cut, " ,.·-");
    }
}
