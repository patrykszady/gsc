<?php

namespace App\Services\Seo;

use App\Models\LandingPage;
use App\Models\Project;
use App\Services\AiContentService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Writes one guide page (LandingPage template "guide") from a cluster of
 * content-guides.clusters and its proof projects, through AiContentService.
 *
 * The model is handed GuideFactSheet's text as the whole of what it may use,
 * and the draft is then checked against that same text: every dollar figure
 * and every duration ("8–12 weeks", "10 business days") in the copy must
 * appear in the facts, no competitor from config/competitors.php and none of
 * the banned filler phrases may appear, and the page must run 900–1,650
 * words. A draft that fails is sent back once with the reason; a second
 * failure is a hard failure (null + getLastError()) — the command never
 * saves a page the checks did not pass, and nothing is ever written by hand
 * in its place.
 */
class GuidePageContentGenerator
{
    public const MIN_WORDS = 900;

    public const MAX_WORDS = 1650;

    private const BANNED_PHRASES = ['nestled in', 'premier', 'your trusted', 'look no further', 'dream home', 'welcome to'];

    private ?string $lastError = null;

    public function __construct(
        private readonly AiContentService $ai,
        private readonly GuideFactSheet $facts = new GuideFactSheet,
    ) {}

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @param  array<string,mixed>  $cluster
     * @param  Collection<int,Project>  $projects  proof, best first
     * @return array<string,mixed>|null LandingPage attributes, or null (see getLastError())
     */
    public function build(array $cluster, Collection $projects): ?array
    {
        $this->lastError = null;

        if ($projects->isEmpty()) {
            $this->lastError = 'No published proof projects — a guide is never built without real work behind it.';

            return null;
        }

        $facts = $this->facts->build($cluster, $projects);
        $allowedFigures = $this->figures($facts);
        $prompt = $this->prompt($cluster, $facts);
        $rejection = null;

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $raw = $this->ai->generateText($rejection === null ? $prompt : $prompt."\n\nYOUR PREVIOUS DRAFT WAS REJECTED. Reason: {$rejection}\nFix exactly that and return the whole JSON object again.", 4500, 0.6);

            if ($raw === null) {
                $this->lastError = 'The model did not answer: '.($this->ai->getLastError() ?: 'no response');

                return null;
            }

            $copy = $this->decode($raw);
            $rejection = $copy === null ? $this->lastError : $this->reject($copy, $allowedFigures);

            if ($rejection === null && $copy !== null) {
                return $this->attributes($cluster, $copy, $projects);
            }
        }

        $this->lastError = 'Draft rejected twice: '.$rejection;

        return null;
    }

    /** @param  array<string,mixed>  $cluster */
    private function prompt(array $cluster, string $facts): string
    {
        $brand = (string) config('brand.name', 'GS Construction');
        $head = (string) $cluster['head_query'];
        $variants = collect((array) ($cluster['queries'] ?? []))->keys()
            ->reject(fn (string $q) => Str::lower($q) === Str::lower($head))
            ->map(fn (string $q) => '"'.$q.'"')
            ->implode(', ');
        $slug = (string) $cluster['slug'];
        $angle = (string) ($cluster['angle'] ?? '');
        $min = self::MIN_WORDS + 100;
        $max = self::MAX_WORDS - 250;

        return <<<PROMPT
You write long-form guide pages for the website of {$brand}, a family-owned remodeling contractor in Chicago's northwest suburbs. Write in US English, plain and specific, for a homeowner who typed "{$head}" into Google and wants a straight answer.

THE PAGE
- Address: /remodeling/{$slug}
- Head query (the search this page must answer first): "{$head}"
- Close variants this page also answers: {$variants}
- Angle: {$angle}

THE FACTS — the only facts you may use
{$facts}

HARD RULES
1. Use only the facts above. Every dollar figure, timeline, permit rule, code edition, year, credential and project detail must appear in the facts. Where the facts do not answer something, say what we check on the free in-home estimate instead of guessing.
2. Never invent prices, timelines, awards, licence numbers, customer counts, review counts, statistics or client names.
3. Never name another contractor, remodeling company or competitor. Product brands named in the facts (for example IKEA) may be used the way the facts use them.
4. US English spelling. Plain text only: no markdown, no HTML, no URLs, no emoji. Separate paragraphs with a blank line. A section body may use lines that start with "- " for a short list of three to six items.
5. Headings answer the query: each section heading is a plain statement or question a homeowner would ask, not a slogan. Never use: "nestled in", "premier", "your trusted", "look no further", "dream home", "welcome to", "elevate", "transform".
6. Refer to the real projects by their exact titles and towns where they illustrate a point, and only with details the facts give.
7. Write as {$brand} ("we"); name {$brand} at least twice. Describe how we work (itemized estimate, owner on site, permits handled, written warranty) only as the facts state it.
8. Length: {$min}–{$max} words in total across the intro, the sections and the FAQ answers. Each section runs 150–300 words.

OUTPUT — one JSON object only, no code fences, no preamble:
{
  "title": "search title, at most 60 characters, contains the head query's words",
  "meta_description": "140–158 characters: the direct answer to the head query, ending with the free in-home estimate",
  "h1": "page heading of 6–12 words that answers the head query",
  "intro": "2–4 short paragraphs, 120–200 words, the direct answer first",
  "sections": [ {"heading": "...", "body": "..."}, ... exactly 5 or 6 sections ],
  "faq": [ {"q": "...", "a": "..."}, ... 4 to 6 questions a homeowner asks about the head query; answers 40–90 words, from the facts only ]
}
PROMPT;
    }

    /** @return array<string,mixed>|null */
    private function decode(string $raw): ?array
    {
        $raw = preg_replace('/^```json\s*|^```\s*|\s*```$/im', '', trim($raw)) ?? '';
        $decoded = json_decode(trim($raw), true);

        if (! is_array($decoded)) {
            $this->lastError = 'The answer was not valid JSON: '.mb_substr($raw, 0, 200);

            return null;
        }

        foreach (['title', 'meta_description', 'h1', 'intro'] as $key) {
            if (! is_string($decoded[$key] ?? null) || trim($decoded[$key]) === '') {
                $this->lastError = "Missing \"{$key}\" in the answer.";

                return null;
            }
        }

        $sections = [];
        foreach ((array) ($decoded['sections'] ?? []) as $section) {
            if (is_string($section['heading'] ?? null) && is_string($section['body'] ?? null) && trim($section['body']) !== '') {
                $sections[] = ['heading' => trim($section['heading']), 'body' => trim($section['body'])];
            }
        }
        $faq = [];
        foreach ((array) ($decoded['faq'] ?? []) as $item) {
            if (is_string($item['q'] ?? null) && is_string($item['a'] ?? null) && trim($item['a']) !== '') {
                $faq[] = ['q' => trim($item['q']), 'a' => trim($item['a'])];
            }
        }

        return [
            'title' => trim($decoded['title']),
            'meta_description' => trim($decoded['meta_description']),
            'h1' => trim($decoded['h1']),
            'intro' => trim($decoded['intro']),
            'sections' => $sections,
            'faq' => $faq,
        ];
    }

    /**
     * Why a decoded draft cannot be saved, or null when it can.
     *
     * @param  array<string,mixed>  $copy
     * @param  list<string>  $allowedFigures
     */
    private function reject(array $copy, array $allowedFigures): ?string
    {
        if (count($copy['sections']) < 4) {
            return 'fewer than 4 sections';
        }
        if (count($copy['faq']) < 4 || count($copy['faq']) > 6) {
            return 'the FAQ must have 4 to 6 questions, it has '.count($copy['faq']);
        }

        $words = $this->wordCount($copy);
        if ($words < self::MIN_WORDS) {
            return "only {$words} words; the page needs at least ".(self::MIN_WORDS + 100).' words of substance across the intro, sections and FAQ answers';
        }
        if ($words > self::MAX_WORDS) {
            return "{$words} words; tighten the page to at most ".(self::MAX_WORDS - 250).' words';
        }

        $text = $this->text($copy);

        $unsupported = array_values(array_diff($this->figures($text), $allowedFigures));
        if ($unsupported !== []) {
            return 'these figures are not in the facts and must be removed or replaced with figures from the facts: '.implode(', ', $unsupported);
        }

        foreach ($this->bannedTerms() as $term) {
            if (stripos($text, $term) !== false) {
                return "the text mentions \"{$term}\", which is not allowed";
            }
        }

        if (preg_match('#https?://|www\.#i', $text)) {
            return 'the text contains a URL; write plain text only';
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $cluster
     * @param  array<string,mixed>  $copy
     * @param  Collection<int,Project>  $projects
     * @return array<string,mixed>
     */
    private function attributes(array $cluster, array $copy, Collection $projects): array
    {
        return [
            'slug' => (string) $cluster['slug'],
            'template' => LandingPage::TEMPLATE_GUIDE,
            'writer' => 'ai',
            'service' => (string) $cluster['service'],
            'city' => null,
            'modifier' => $cluster['modifier'] ?? null,
            'target_query' => (string) $cluster['head_query'],
            'title' => $this->fit($copy['title'], 60),
            'h1' => $copy['h1'],
            'meta_description' => $this->fit($copy['meta_description'], 158),
            'intro' => $copy['intro'],
            'sections' => $copy['sections'],
            'faq' => $copy['faq'],
            'hero_image' => self::heroImage($projects),
            'proof_project_ids' => $projects->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'status' => LandingPage::STATUS_DRAFT,
            'source' => 'manual',
            'indexed' => true,
        ];
    }

    /**
     * A real photo of a matching project: the first proof project whose
     * cover resolves to a URL (the file exists on the public disk).
     *
     * @param  Collection<int,Project>  $projects
     */
    public static function heroImage(Collection $projects): ?string
    {
        foreach ($projects as $project) {
            $cover = $project->cover();
            $url = $cover?->url ?? $cover?->getAnyUrl('large');
            if (is_string($url) && $url !== '') {
                return $url;
            }
        }

        return null;
    }

    /**
     * Every dollar amount and every duration in $text, normalised so "$35K",
     * "$35,000" and "$35,000+" compare equal and "8–12 weeks", "8-12 weeks"
     * and "8 to 12 weeks" compare equal. A range also yields its endpoints.
     *
     * @return list<string>
     */
    public function figures(string $text): array
    {
        $out = [];

        preg_match_all('/\$\s?(\d[\d,]*(?:\.\d+)?)\s?([kK])?\b/u', $text, $money, PREG_SET_ORDER);
        foreach ($money as $m) {
            $amount = (float) str_replace(',', '', $m[1]);
            if (($m[2] ?? '') !== '') {
                $amount *= 1000;
            }
            $out[] = '$'.number_format($amount, 0, '.', '');
        }

        $units = '(business\s+days?|weeks?|days?|months?|hours?|years?)';
        preg_match_all('/\b(\d+)\s*(?:–|-|to)\s*(\d+)\s*\+?\s*'.$units.'\b/iu', $text, $ranges, PREG_SET_ORDER);
        foreach ($ranges as $r) {
            $unit = $this->unit($r[3]);
            $out[] = "{$r[1]}-{$r[2]} {$unit}";
            $out[] = "{$r[1]} {$unit}";
            $out[] = "{$r[2]} {$unit}";
        }
        preg_match_all('/\b(\d+)\s*\+?\s*'.$units.'\b/iu', $text, $singles, PREG_SET_ORDER);
        foreach ($singles as $s) {
            $out[] = "{$s[1]} ".$this->unit($s[2]);
        }

        return array_values(array_unique($out));
    }

    private function unit(string $unit): string
    {
        $unit = Str::lower(preg_replace('/\s+/', ' ', $unit) ?? $unit);

        return rtrim($unit, 's');
    }

    /** @return list<string> competitor names from config/competitors.php plus the filler phrases the prompt bans */
    private function bannedTerms(): array
    {
        $competitors = collect((array) config('competitors.competitors', []))
            ->flatMap(fn (array $c) => array_merge([(string) ($c['name'] ?? '')], (array) ($c['also_known_as'] ?? [])))
            ->map(fn (string $name) => trim($name))
            ->filter(fn (string $name) => mb_strlen($name) >= 4)
            ->all();

        return array_values(array_unique(array_merge($competitors, self::BANNED_PHRASES)));
    }

    /** @param  array<string,mixed>  $copy */
    private function text(array $copy): string
    {
        $parts = [$copy['title'], $copy['meta_description'], $copy['h1'], $copy['intro']];
        foreach ($copy['sections'] as $section) {
            $parts[] = $section['heading'];
            $parts[] = $section['body'];
        }
        foreach ($copy['faq'] as $item) {
            $parts[] = $item['q'];
            $parts[] = $item['a'];
        }

        return implode("\n", $parts);
    }

    /** @param  array<string,mixed>  $copy */
    public function wordCount(array $copy): int
    {
        $parts = [$copy['intro'] ?? ''];
        foreach ((array) ($copy['sections'] ?? []) as $section) {
            $parts[] = $section['heading'] ?? '';
            $parts[] = $section['body'] ?? '';
        }
        foreach ((array) ($copy['faq'] ?? []) as $item) {
            $parts[] = $item['q'] ?? '';
            $parts[] = $item['a'] ?? '';
        }

        return count(preg_split('/\s+/u', trim(implode(' ', $parts)), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    private function fit(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        $cut = mb_substr($text, 0, $max);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space ? mb_substr($cut, 0, $space) : $cut, ' ,.·-—:');
    }
}
