<?php

namespace App\Services\Seo;

use App\Models\AreaServed;
use App\Models\Project;

/**
 * Deterministic, rules-based CTR-optimized title + meta generator.
 *
 * Kept rules-based (not AI) on purpose: this feeds the FULL-AUTO apply path, so
 * output must be predictable, truthful, and free of hallucination. Every hook
 * used here is a claim the business already makes on-site (family-owned, 40+ yrs
 * combined experience, 5-star rated, free estimates), so nothing new is invented.
 *
 * This does NOT return the final <title>. SEOBuilder appends " | {brand}"
 * afterwards (app/Support/SEO/SEOBuilder.php:152-154), which is why the budget
 * below is computed at runtime rather than being a flat 60. The old constant
 * assumed it owned the whole budget — a docblock that was simply false — and
 * every area page shipped 67 chars, /services/bathroom-remodeling 76, against
 * a ~600px (≈60 char) cutoff. The tail Google truncated was the brand.
 *
 * Budget is read per call, never cached: config('brand.name') is overlaid per
 * TENANT, so a hardcoded length would be wrong on every site but this one.
 */
class TitleMetaGenerator
{
    /** Google truncates desktop titles near 600px ≈ 60 characters. */
    private const TITLE_PIXEL_BUDGET_CHARS = 60;

    private const DESC_MAX = 158;

    /**
     * Characters available BEFORE SEOBuilder appends " | {brand}".
     *
     * Floored at 30 so a pathologically long tenant brand cannot collapse the
     * title to nothing — better to overflow the brand than to ship a page
     * titled with one word.
     */
    private function titleBudget(): int
    {
        $brand = (string) config('brand.name');
        $suffix = $brand === '' ? 0 : mb_strlen(' | ' . $brand);

        return max(30, self::TITLE_PIXEL_BUDGET_CHARS - $suffix);
    }

    /** @var array<string,string> service slug => human label */
    public const SERVICES = [
        'kitchen-remodeling' => 'Kitchen Remodeling',
        'bathroom-remodeling' => 'Bathroom Remodeling',
        'home-remodeling' => 'Home Remodeling',
        'basement-remodeling' => 'Basement Remodeling',
        'home-additions' => 'Home Additions',
        'mudroom-remodeling' => 'Mudroom Remodeling',
    ];

    /**
     * @return array{title:string,description:string}
     */
    public function forArea(AreaServed $area, ?string $serviceSlug = null): array
    {
        $city = trim((string) $area->city);

        if ($serviceSlug !== null && isset(self::SERVICES[$serviceSlug])) {
            $service = self::SERVICES[$serviceSlug];
            $serviceLower = strtolower($service);
            $title = $this->fitTitle("{$city} {$service}", ['Free Estimate']);
            // CTA first. fitDesc() truncates the TAIL, so whatever must
            // survive has to lead — the old ordering put "Free estimate." last
            // and every one of the 40 area descriptions was cut before it.
            $desc = $this->fitDesc(
                "Free estimate on {$city} {$serviceLower}. Clear pricing, a dedicated project lead, "
                . "and 5-star reviews. Family-owned, 40+ yrs combined experience."
            );

            return ['title' => $title, 'description' => $desc];
        }

        // Area landing page (whole service range). No ", IL" — titles never
        // carry the state (SEOBuilder strips it defensively too).
        //
        // "Home Remodeling", not "Remodeling": the queries carrying the
        // impressions are "lake bluff home remodeling" (857/mo), "kenilworth
        // home remodeling" (794), "wilmette home remodeling services" (557) —
        // and the old title never contained the phrase. Google's own rewrite
        // of the Kenilworth page inserted the missing words for us.
        //
        // The "5★ Rated · Free Estimates" pair is gone deliberately. Repeated
        // verbatim across 40 pages it is boilerplate, which is one of the
        // strongest triggers for Google to discard the title and write its
        // own — at which point none of it reaches a searcher anyway.
        $title = $this->fitTitle("{$city} Home Remodeling", ['Kitchen & Bath']);
        // Sized so the longest city on the list ("Arlington Heights", "Elk
        // Grove Village" — 17 chars) still lands inside DESC_MAX as whole
        // sentences. The previous copy fitted short names and cut "clear
        // pricing and…" off the long ones.
        $desc = $this->fitDesc(
            "Free estimate on {$city} kitchen, bathroom & whole-home remodeling. "
            . "Family-owned, 5-star rated, clear pricing and timelines."
        );

        return ['title' => $title, 'description' => $desc];
    }

    /**
     * @return array{title:string,description:string}
     */
    public function forProject(Project $project): array
    {
        $name = trim((string) ($project->title ?? $project->name ?? 'Remodeling Project'));

        // No brand hook: SEOBuilder appends " | {brand}" downstream, and its
        // `! str_contains($title, $brand)` guard meant a project whose name
        // happened to leave room got the brand mid-title and no suffix, while
        // a longer one got the suffix — the same page type titled two ways.
        $title = $this->fitTitle($name, []);

        // Projects carry `location` ("Arlington Heights, IL"), never `city`.
        // Reading the missing attribute meant EVERY project description said
        // "in the Chicago suburbs" — throwing away the one genuinely local,
        // page-unique signal these pages have.
        $city = trim(preg_split('/[,.]/', (string) $project->location)[0] ?? '');
        $where = match (true) {
            // Many project titles already lead with the town ("Barrington
            // Kitchen Transformation"); repeating it reads as a stutter.
            $city !== '' && stripos($name, $city) !== false => '',
            $city !== '' => " in {$city}, IL",
            default => ' in the Chicago suburbs',
        };
        $desc = $this->fitDesc(
            "See this {$name}{$where} by GS Construction — real photos, materials and layout. "
            . "Family-owned, 5-star rated, 40+ yrs combined experience. Free estimate."
        );

        return ['title' => $title, 'description' => $desc];
    }

    /**
     * Assemble "{core} | {hook}" appending as many hooks as fit under the budget,
     * separated by " · ". Guarantees the core is never truncated.
     *
     * @param array<int,string> $hooks
     */
    private function fitTitle(string $core, array $hooks): string
    {
        $core = trim($core);
        $out = $core;
        $suffix = '';

        foreach ($hooks as $hook) {
            $candidateSuffix = $suffix === '' ? " | {$hook}" : "{$suffix} · {$hook}";
            if (mb_strlen($core . $candidateSuffix) <= $this->titleBudget()) {
                $suffix = $candidateSuffix;
            }
        }

        return $core . $suffix;
    }

    private function fitDesc(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if (mb_strlen($text) <= self::DESC_MAX) {
            return $text;
        }

        $cut = mb_substr($text, 0, self::DESC_MAX);

        // Prefer ending on a completed sentence. A snippet reading
        // "…clear pricing and…" looks broken; one that stops a few words
        // earlier at a full stop reads as deliberate. Only taken when it
        // keeps most of the budget, so a long first sentence cannot collapse
        // the description to a stub.
        $lastStop = mb_strrpos($cut, '. ');
        if ($lastStop !== false && $lastStop >= (int) (self::DESC_MAX * 0.6)) {
            return mb_substr($cut, 0, $lastStop + 1);
        }

        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, " ,.·-") . '…';
    }
}
