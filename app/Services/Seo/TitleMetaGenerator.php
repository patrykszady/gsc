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
 * The SEO package suffix is empty (config/seo.php), so the returned title is the
 * final <title> — we own the whole ~60-char budget.
 */
class TitleMetaGenerator
{
    private const TITLE_MAX = 60;
    private const DESC_MAX = 158;

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
            $title = $this->fitTitle("{$city} {$service}", ['5★ Rated', 'Free Estimate']);
            $desc = $this->fitDesc(
                "{$city} {$serviceLower} done right — clear pricing, a dedicated project lead, and "
                . "5-star reviews. Family-owned, 40+ yrs combined experience. Book your free {$city} estimate."
            );

            return ['title' => $title, 'description' => $desc];
        }

        // Area landing page (whole service range).
        $title = $this->fitTitle("{$city}, IL Remodeling", ['5★ Rated', 'Free Estimates']);
        $desc = $this->fitDesc(
            "Family-owned {$city} remodeling contractor. Kitchen, bathroom & whole-home renovations "
            . "with clear pricing and timelines. 40+ yrs combined experience, 5-star rated. Free estimate."
        );

        return ['title' => $title, 'description' => $desc];
    }

    /**
     * @return array{title:string,description:string}
     */
    public function forProject(Project $project): array
    {
        $name = trim((string) ($project->title ?? $project->name ?? 'Remodeling Project'));
        $title = $this->fitTitle($name, ['5★ Rated', 'GS Construction']);
        $city = trim((string) ($project->city ?? ''));
        $where = $city !== '' ? " in {$city}, IL" : ' in the Chicago suburbs';
        $desc = $this->fitDesc(
            "See this {$name} remodel{$where} by GS Construction — real photos, materials and layout. "
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
            if (mb_strlen($core . $candidateSuffix) <= self::TITLE_MAX) {
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
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, " ,.·-") . '…';
    }
}
