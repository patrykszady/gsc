<?php

namespace App\Services\Blog;

use App\Models\BlogPost;
use App\Models\Project;
use App\Services\Blog\PartnerContributionEstimator;
use App\Services\Blog\PartnerSiteFetcher;
use App\Services\AiContentService;
use Illuminate\Support\Str;

/**
 * Writes a draft blog post for a project — the story of one job, told the
 * way the site tells every job: through the six-step process, the real
 * photos, the before/afters and the timelapse if the project has them.
 *
 * Grounding is the whole game. The prompt gets ONLY facts the project
 * already carries (title, location, type, description, each image's own
 * AI caption/alt text, which media exist) plus the published process steps,
 * and is told to invent nothing else. Media is never described by the model
 * — it is placed with shortcodes the renderer expands from the real files:
 *
 *   [cover]  [before-after]  [timelapse]  [gallery]
 *
 * Always a DRAFT. Publishing is a human click in the admin.
 */
class ProjectBlogWriter
{
    protected ?string $lastError = null;

    public function __construct(protected AiContentService $ai)
    {
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function write(Project $project): ?BlogPost
    {
        $project->loadMissing(['images', 'beforeAfters', 'timelapses.frames', 'testimonials', 'collaborators']);

        $typeLabel = Project::projectTypes()[$project->project_type] ?? Str::of((string) $project->project_type)->replace('-', ' ')->title();
        $city = trim((string) Str::of((string) $project->location)->before(','));

        $photoNotes = $project->images->take(12)->map(function ($img, $i) {
            $line = ($i + 1) . '. ' . trim((string) ($img->caption ?: $img->seo_alt_text ?: $img->alt_text ?: 'project photo'));

            return mb_substr($line, 0, 220);
        })->implode("\n");

        $hasBeforeAfter = $project->beforeAfters->isNotEmpty();
        $hasBefore = \App\Support\Blog\BlogRenderer::beforeImage($project) !== null;
        $hasTimelapse = $project->timelapses->contains(fn ($t) => $t->frames->count() >= 3);
        $beforeAfterTitles = $project->beforeAfters->pluck('title')->filter()->take(4)->implode('; ');

        $review = $project->testimonials->where('is_hidden', false)->sortByDesc('review_date')->first();
        $reviewBlock = '';
        if ($review) {
            $reviewer = $review->display_name;
            $reviewText = trim((string) $review->review_description);
            $reviewBlock = <<<REVIEW

HOMEOWNER REVIEW of this project, verbatim, written by {$reviewer}:
"""
{$reviewText}
"""
Weave 2–3 short excerpts from this review into the story (one sentence or clause
each), quoted EXACTLY as written, in quotation marks, attributed to {$reviewer}
(e.g. after the build, or in the reveal). Never alter, trim mid-word, or invent quoted
words, and never present the review as saying something it does not say.
REVIEW;
        }

        $partnerBlock = $this->partnerBlock($project);
        $towns = $this->towns($project, $city);
        $townList = implode(', ', $towns);
        $recipe = $this->recipe($project);
        $avoid = $this->avoidBlock($project);

        $steps = $this->processSteps($project);
        $stepLines = collect($steps)->map(fn ($s) => '- ' . ($s['title'] ?? '') . ': ' . ($s['body'] ?? $s['description'] ?? ''))->implode("\n");

        $mediaMenu = collect([
            $hasBefore ? '[before] — the "before" photo, shown beside the text (use once, in the first section, right before the paragraph that describes the space as we found it)' : null,
            '[cover] — the project cover photo (use once, right after the intro)',
            $hasBeforeAfter ? '[before-after] — side-by-side before/after pair(s)' . ($beforeAfterTitles ? " ({$beforeAfterTitles})" : '') : null,
            $hasTimelapse ? '[timelapse] — the construction timelapse frames' : null,
            $project->images->count() >= 4 ? '[gallery] — grid of finished-project photos (use once, near the end)' : null,
        ])->filter()->implode("\n");

        $prompt = <<<PROMPT
You are writing a blog post for GS Construction, a family-owned kitchen, bathroom, and
whole-home remodeling contractor in Arlington Heights, Illinois (owners Gregory and
Patryk, father & son; licensed and insured; one dedicated project lead per job).

Write the story of ONE completed project, for a homeowner reading the blog to decide
whether to hire us. Warm, specific, honest. No hype, no sales pressure.

PROJECT FACTS (the only facts you may use — invent nothing, no client names, no prices
beyond what is stated, no dates):
- Title: {$project->title}
- Type: {$typeLabel}
- Location: {$project->location}
- Description: {$project->description}
- Photo notes (what our photos actually show):
{$photoNotes}
{$reviewBlock}
{$partnerBlock}

OUR PROCESS (structure the middle of the post around the steps that plausibly applied;
do not claim a step happened in a way the facts contradict):
{$stepLines}

MEDIA SHORTCODES you may place on their own line between paragraphs — each at most once,
only the ones listed here exist for this project:
{$mediaMenu}

THIS POST'S SHAPE (every project's story is told differently — follow this, not a template):
- Angle: {$recipe['angle']}
- Structure: {$recipe['structure']}
- Opening: {$recipe['opening']}
- Include: {$recipe['extras']}
- Length: {$recipe['length']} words, with {$recipe['headings']} "##" headings. Headings must be specific
  to this project (a detail, a decision, a material) — never generic labels like
  "The Vision", "The Build", "The Result", "The Reveal", "Conclusion", and never the
  "Label: detail" colon pattern. Use "##" for every heading.
- Closing: {$recipe['closing']}
{$avoid}
Return ONLY a JSON object with EXACTLY these keys:
- "title": 55–70 characters, specific to this project and town, no clickbait.
- "excerpt": 1–2 sentences, 140–200 characters, plain text.
- "meta_title": ≤ 60 characters, includes the town.
- "meta_description": 140–158 characters.
- "body": Markdown following the shape above. Place the media shortcodes on their own
  lines where they make narrative sense; do NOT start with the title or any # heading —
  the page renders the title itself. Write in first person plural ("we"). Mention {$city}
  naturally. Whenever you list towns (the closing invitation especially), the project's own
  town comes FIRST, then nearby ones, in exactly this order: {$townList}. Never lead with
  another town.

Hard rules: no invented facts; no client names other than the reviewer's display name given above; no exact prices
unless in the description; no emoji; no phrases "nestled in", "dream home", "look no further", "your trusted",
"testament to", "elevate", "seamlessly", "breathtaking", "stunning".
Return ONLY the JSON. No code fences.
PROMPT;

        // Long enough for the top length band plus the JSON wrapper; a cap
        // that truncates the JSON fails validation and writes nothing.
        $raw = $this->ai->generateText($prompt, 6000, 0.7);
        if ($raw === null) {
            $this->lastError = $this->ai->getLastError() ?? 'AI call failed';

            return null;
        }

        $raw = preg_replace('/^```json\s*|^```\s*|\s*```$/im', '', trim($raw));
        $data = json_decode(trim((string) $raw), true);
        if (! is_array($data) || empty($data['title']) || empty($data['body']) || mb_strlen((string) $data['body']) < 1500) {
            $this->lastError = 'Blog copy failed validation: ' . mb_substr((string) $raw, 0, 200);

            return null;
        }

        // Strip any shortcode the project cannot back, so the renderer never
        // meets a promise the media can't keep.
        $body = (string) $data['body'];
        // The page renders the title as its H1; a leading heading in the body
        // would print it twice.
        $body = preg_replace('/\A\s*#{1,2}\s+[^\n]+\n+/', '', $body);
        if (! $hasBefore) {
            $body = preg_replace('/^\s*\[before\]\s*$/m', '', $body);
        }
        if (! $hasBeforeAfter) {
            $body = preg_replace('/^\s*\[before-after\]\s*$/m', '', $body);
        }
        if (! $hasTimelapse) {
            $body = preg_replace('/^\s*\[timelapse\]\s*$/m', '', $body);
        }
        if ($project->images->count() < 4) {
            $body = preg_replace('/^\s*\[gallery\]\s*$/m', '', $body);
        }

        $existing = BlogPost::where('project_id', $project->id)->first();

        return BlogPost::updateOrCreate(
            ['project_id' => $project->id],
            [
                'dated_at' => $existing?->dated_at ?? BlogPost::dateFor($project),
                'title' => mb_substr(trim((string) $data['title']), 0, 191),
                'excerpt' => mb_substr(trim((string) ($data['excerpt'] ?? '')), 0, 500),
                'body' => trim($body),
                'meta_title' => mb_substr(trim((string) ($data['meta_title'] ?? $data['title'])), 0, 191),
                'meta_description' => mb_substr(trim((string) ($data['meta_description'] ?? '')), 0, 320),
                'status' => BlogPost::STATUS_DRAFT,
                'writer' => 'ai',
                'published_at' => null,
            ]
        );
    }

    /**
     * The towns a post may name, in order: the project's own town first, then
     * the two nearest towns we serve (by distance between the areas' stored
     * coordinates). Falls back to just the project town.
     *
     * @return array<int, string>
     */
    protected function towns(Project $project, string $city): array
    {
        $city = trim($city);
        if ($city === '') {
            return [];
        }

        $home = \App\Models\AreaServed::query()->where('city', $city)->first();
        if (! $home || $home->latitude === null || $home->longitude === null) {
            return [$city];
        }

        $near = \App\Models\AreaServed::query()
            ->whereKeyNot($home->getKey())
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->get()
            ->sortBy(fn ($a) => (($a->latitude - $home->latitude) ** 2) + ((($a->longitude - $home->longitude) * cos(deg2rad((float) $home->latitude))) ** 2))
            ->take(2)
            ->pluck('city')
            ->all();

        return array_merge([$home->city], $near);
    }

    /**
     * Partners on the job, with what their own site says they do — so the
     * writer can describe their role accurately and link to them. A site not
     * yet read (the queued fetch hasn't run) is read now, best-effort.
     */
    protected function partnerBlock(Project $project): string
    {
        if ($project->collaborators->isEmpty()) {
            return '';
        }

        $fetcher = app(PartnerSiteFetcher::class);
        $estimator = app(PartnerContributionEstimator::class);
        $lines = $project->collaborators->map(function ($c) use ($fetcher, $estimator, $project) {
            if ($c->url && ! $c->site_fetched_at) {
                $fetcher->fetch($c);
            }
            if (! $c->note && ! $c->inferred_note) {
                $estimator->estimate($c, $project);
            }
            $line = "- {$c->name} — {$c->roleLabel()}";
            if ($c->note) {
                $line .= ". On this job (confirmed): {$c->note}";
            } elseif ($c->inferred_note) {
                $line .= ". On this job (our estimate from their services): {$c->inferred_note}";
            }
            if ($c->url) {
                $line .= ". Website: {$c->url}";
            }
            $about = trim(implode("\n", array_filter([
                $c->site_title ? "Title: {$c->site_title}" : null,
                $c->site_description ? "Description: {$c->site_description}" : null,
                $c->site_excerpt ? 'Text: ' . Str::limit((string) $c->site_excerpt, 3500, '') : null,
            ])));
            if ($about !== '') {
                $line .= "\n  What their website says (context only — describe them in your own words, copy no sentence):\n  " . str_replace("\n", "\n  ", $about);
            }

            return $line;
        })->implode("\n");

        return <<<PARTNERS

PEOPLE WE WORKED WITH on this project. Give each one real space, not a name-drop: a designer or
architect gets at least one full paragraph of their own (a "##" section is fine) covering who they
are and what they specialise in (from their website, in your own words), what they delivered on
this job, and how the handoff with us worked — their drawings and selections became our scope,
we built to them, and we coordinated with them through the build. A trade partner gets a few
sentences where their work is described. Weave the partner back in wherever their work shows
up later (e.g. the cabinetry, the layout, the finishes). Mention each by name and role, and say
HOW they helped on this job — "On this job:" below is what they did (confirmed by us, or our
estimate from the services they offer); build that into the story as a concrete contribution,
not a name-drop, and keep the division of work clear: GS Construction did the consultation,
estimate, permits, scheduling and construction; the partner did what is listed for them. Where a Website is given, make the
FIRST mention a Markdown link to it, e.g. [Name](https://…).
Never invent partners or roles beyond this list, and never say who brought the partner in (the
homeowners or us) unless the note says so — just that we worked together on it:
{$lines}
PARTNERS;
    }

    /**
     * A per-project recipe — angle, structure, opening, extras, length,
     * closing — seeded from the project id, so the shape is stable across
     * regenerations of one post yet differs from project to project.
     *
     * @return array<string, string>
     */
    protected function recipe(Project $project): array
    {
        mt_srand((int) $project->id * 104729 + 7);
        $pick = fn (array $options) => $options[mt_rand(0, count($options) - 1)];

        $extras = [
            'a short bulleted "At a glance" list (type, town, scope) near the top',
            'a "Materials & finishes" bulleted list where the finishes are discussed',
            'one paragraph on what we would do the same way again, and why',
            'one short numbered list of the steps, in the order they happened',
            'a one-line excerpt from the review as its own italic paragraph',
            'no lists at all — prose only',
            'one paragraph on what the homeowners were most worried about, and how it went',
            'one paragraph on a detail most people would never notice',
        ];
        shuffle($extras);
        $recipe = [
            'angle' => $pick([
                'the problem the homeowners came to us with, and how the plan solved it',
                'one decision that changed the whole project',
                'the materials and finishes, and why each was chosen',
                'the sequence of trades — who came in when, and why that order',
                'what the space is like to live in now',
                'what we would tell a homeowner planning the same remodel',
                'the constraints of the existing house, and how the design worked around them',
            ]),
            'structure' => $pick([
                'chronological — plan, build, finished room',
                'problem → options → what we chose → how it turned out',
                'finished room first, then how we got there',
                'three lessons from this project, each its own section',
                'question-and-answer: every heading is a question a homeowner would ask, answered from this project',
                'a walk through the room — each section is one part of the space',
            ]),
            'opening' => $pick([
                'open on a specific detail visible in one of the photos',
                'open with what the homeowners originally asked for',
                'open with a short excerpt from the review, if one is given; otherwise with the first decision we made',
                'open with a question a homeowner would ask',
                'open with the moment the homeowners saw the finished room',
                'open with a plain statement of what changed — one sentence, no build-up',
            ]),
            'extras' => implode('; and ', array_slice($extras, 0, mt_rand(1, 2))),
            'length' => $pick(['950–1150', '1050–1300', '1200–1450', '1350–1650']),
            'headings' => $pick(['2–3', '3–4', '4–5', '5–6']),
            'closing' => $pick([
                'a single sentence inviting readers to request a free in-home estimate',
                'a question to the reader, then the invitation to request a free in-home estimate',
                'what to bring to a first meeting, then the invitation to request a free in-home estimate',
                'how long this kind of project usually takes, then the invitation to request a free in-home estimate',
            ]),
        ];
        mt_srand();

        return $recipe;
    }

    /**
     * Openings and titles already used by other posts, so the model does not
     * fall back on the same first sentence or title pattern every time.
     */
    protected function avoidBlock(Project $project): string
    {
        $others = BlogPost::query()->where('project_id', '!=', $project->id)->latest('updated_at')->take(8)->get(['title', 'body']);
        if ($others->isEmpty()) {
            return '';
        }

        $openings = $others->map(function ($p) {
            $text = trim(preg_replace('/^\s*#.*$/m', '', (string) $p->body) ?? '');
            $text = preg_replace('/\s+/', ' ', $text) ?? $text;

            return Str::limit(trim((string) Str::before($text, '. ')), 140, '');
        })->filter()->map(fn ($o) => "  - \"{$o}\"")->implode("\n");
        $titles = $others->pluck('title')->map(fn ($t) => "  - \"{$t}\"")->implode("\n");

        return <<<AVOID
- Do NOT open with a sentence like any of these (other posts already did):
{$openings}
- Do NOT reuse the pattern of these titles:
{$titles}
AVOID;
    }

    /** The published process steps, loaded straight from the site's config file when the request-scoped config isn't present (queue workers). */
    protected function processSteps(Project $project): array
    {
        $steps = (array) config('services-content.process', []);
        if ($steps !== []) {
            return $steps;
        }

        $slug = $project->site?->slug ?? \App\Models\Site::current()?->slug ?? 'gsc';
        $file = config_path("sites/{$slug}/services-content.php");

        return is_file($file) ? (array) ((require $file)['process'] ?? []) : [];
    }
}
