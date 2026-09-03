<?php

namespace App\Services\Blog;

use App\Models\BlogPost;
use App\Models\Project;
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
        $project->loadMissing(['images', 'beforeAfters', 'timelapses.frames']);

        $typeLabel = Project::projectTypes()[$project->project_type] ?? Str::of((string) $project->project_type)->replace('-', ' ')->title();
        $city = trim((string) Str::of((string) $project->location)->before(','));

        $photoNotes = $project->images->take(12)->map(function ($img, $i) {
            $line = ($i + 1) . '. ' . trim((string) ($img->caption ?: $img->seo_alt_text ?: $img->alt_text ?: 'project photo'));

            return mb_substr($line, 0, 220);
        })->implode("\n");

        $hasBeforeAfter = $project->beforeAfters->isNotEmpty();
        $hasTimelapse = $project->timelapses->contains(fn ($t) => $t->frames->count() >= 3);
        $beforeAfterTitles = $project->beforeAfters->pluck('title')->filter()->take(4)->implode('; ');

        $steps = $this->processSteps($project);
        $stepLines = collect($steps)->map(fn ($s) => '- ' . ($s['title'] ?? '') . ': ' . ($s['body'] ?? $s['description'] ?? ''))->implode("\n");

        $mediaMenu = collect([
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

OUR PROCESS (structure the middle of the post around the steps that plausibly applied;
do not claim a step happened in a way the facts contradict):
{$stepLines}

MEDIA SHORTCODES you may place on their own line between paragraphs — each at most once,
only the ones listed here exist for this project:
{$mediaMenu}

Return ONLY a JSON object with EXACTLY these keys:
- "title": 55–70 characters, specific to this project and town, no clickbait.
- "excerpt": 1–2 sentences, 140–200 characters, plain text.
- "meta_title": ≤ 60 characters, includes the town.
- "meta_description": 140–158 characters.
- "body": Markdown, 700–1100 words. Use ## headings (3–5 of them). Place the media
  shortcodes on their own lines where they make narrative sense. Write in first person
  plural ("we"). Mention {$city} naturally. End with a short paragraph inviting readers
  to request a free in-home estimate.

Hard rules: no invented facts; no client names; no exact prices unless in the description;
no emoji; no phrases "nestled in", "dream home", "look no further", "your trusted".
Return ONLY the JSON. No code fences.
PROMPT;

        $raw = $this->ai->generateText($prompt, 3500, 0.7);
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
        if (! $hasBeforeAfter) {
            $body = preg_replace('/^\s*\[before-after\]\s*$/m', '', $body);
        }
        if (! $hasTimelapse) {
            $body = preg_replace('/^\s*\[timelapse\]\s*$/m', '', $body);
        }
        if ($project->images->count() < 4) {
            $body = preg_replace('/^\s*\[gallery\]\s*$/m', '', $body);
        }

        return BlogPost::updateOrCreate(
            ['project_id' => $project->id],
            [
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
