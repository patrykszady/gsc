<?php

namespace App\Jobs;

use App\Models\Project;
use App\Models\ProjectCollaborator;
use App\Services\AiContentService;
use App\Support\Tenancy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use SsSystems\Platform\Projects\AreaPhotoSelection;
use SsSystems\Platform\Projects\ProjectDetailsDraft;
use SsSystems\Platform\Projects\ProjectDetailsStatus;

/**
 * Drafts a project's title and description from its photos (Gemini vision)
 * — the gsc half of the photos-first create flow every ss.systems tenant
 * now runs the same code for (ss-platform-kit 0.16.0, see that kit's
 * docs/PROJECT-DETAILS.md and jpeterson-design.com's own identically-named
 * job, which this mirrors).
 *
 * The admin creates the project under a placeholder title
 * (PLACEHOLDER_TITLE) and uploads photos one at a time; gsc's existing
 * automatic pipeline (GenerateAiContentJob) keeps drafting each photo's own
 * alt_text/caption/seo_alt_text/gbp_caption exactly as it already does for
 * every project — that pipeline needs no project-level title to do its
 * job, so there is no App\Support\Projects\* DraftsImageCaptions adapter
 * here (unlike jpeterson-design's "details first, then caption every
 * photo" — gsc's own pipeline already runs the opposite direction, see
 * the kit doc's §4). Only the PROJECT-level description auto-draft
 * (GenerateAiContentJob::processProject()) is held back while the title is
 * still the placeholder (see that method's own guard) — so this job is the
 * one and only writer of a photos-first project's title, and the first
 * writer of its description. What it writes is a DRAFT; the project stays
 * unpublished until a person reads it and publishes.
 *
 * The cache-backed generating/error status and the "placeholder title ->
 * drafted title + regenerated slug" / "blank-or-force description" rules
 * are the kit's now — ProjectDetailsStatus/ProjectDetailsDraft — identical
 * cache keys and rules as jpeterson-design's own job, so the admin's
 * polling behaves the same on every site. What stays here, deliberately
 * (see the kit's docs): the actual Gemini call and this company's prompt
 * wording/house style, and running under Tenancy::for($project->site) —
 * only this site's own job can know to do that.
 */
class GenerateProjectDetailsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** The title a photos-first project is created under; replaced by the draft. */
    public const PLACEHOLDER_TITLE = 'New project';

    /** Same cap as AiContentService::generateProjectDescription()'s own vision context. */
    public const MAX_IMAGES = 5;

    public int $timeout = 240;

    public function __construct(public int $projectId, public bool $force = false)
    {
        $this->onQueue('ai-content');
    }

    public static function flagKey(int $projectId): string
    {
        return ProjectDetailsStatus::flagKey($projectId);
    }

    public static function errorKey(int $projectId): string
    {
        return ProjectDetailsStatus::errorKey($projectId);
    }

    /** @return array{generating: bool, error: ?string} */
    public static function status(Project $project): array
    {
        return ProjectDetailsStatus::status($project);
    }

    /**
     * Flag first, dispatch after the response: the admin expects its 202
     * back at once, then polls the project payload's `details` block.
     */
    public static function launch(Project $project, bool $force = false): void
    {
        ProjectDetailsStatus::markGenerating($project);
        self::dispatchAfterResponse($project->id, $force);
    }

    public function handle(AiContentService $ai): void
    {
        $project = Project::with(['images', 'testimonials', 'collaborators', 'site'])->find($this->projectId);

        $run = function () use ($project, $ai): void {
            try {
                if (! $project) {
                    return;
                }

                $images = AreaPhotoSelection::choose($project->images, $project->cover(), self::MAX_IMAGES);
                if ($images->isEmpty()) {
                    throw new \RuntimeException('None of the photos could be read.');
                }

                $raw = $ai->generateWithImages($this->prompt($project), $images, 1200, 0.5);
                if ($raw === null) {
                    throw new \RuntimeException($ai->getLastError() ?: 'The model returned nothing.');
                }

                $draft = self::parse($raw);
                if ($draft === null) {
                    throw new \RuntimeException('The model did not answer with a title and description.');
                }

                // project_type is NOT taken from the model: the admin's
                // facts step already chose it before this runs, and a
                // chosen type beats a classified one (see ProjectDetailsDraft's
                // own docblock) — gsc's prompt() below only ever READS it,
                // as a fact for the model, never asks the model to guess it.
                $attrs = ProjectDetailsDraft::attributesToApply(
                    $project,
                    draftTitle: $draft['title'],
                    draftDescription: $draft['description'],
                    titleIsPlaceholder: ProjectDetailsDraft::isPlaceholderTitle($project->title, self::PLACEHOLDER_TITLE),
                    force: $this->force,
                    // The placeholder's slug ("new-project-3") would
                    // otherwise be the public URL for good; the project is
                    // still unpublished, so safe to change. Goes through a
                    // real save() below (not *Quietly()), so Project's own
                    // slug-rename hook (SsSystems\Platform\Projects\
                    // Concerns\HasProjectAreas, via
                    // App\Support\Projects\GscSlugRedirectRecorder) records
                    // the old slug the normal way, same as any other rename.
                    regenerateSlug: fn (string $title) => Project::generateUniqueSlug($title, $project->id),
                );

                if ($attrs !== []) {
                    $project->forceFill($attrs)->save();
                }

                // Every gsc project gets a drafted blog post (ProjectObserver::
                // created, 12 minutes after create). A photos-first project's
                // own dispatch gives up while the title is still the
                // placeholder, so the post is asked for again here, once the
                // real title and description exist.
                if (isset($attrs['title']) && ! $project->blogPost()->exists()) {
                    GenerateProjectBlogPostJob::dispatch($project)->delay(now()->addMinutes(5));
                }

                Log::channel('ai_content')->info('Project details drafted from photos', ['project_id' => $project->id, 'photos' => $images->count()]);
            } catch (\Throwable $e) {
                ProjectDetailsStatus::recordError($this->projectId, $e->getMessage());
                Log::channel('ai_content')->warning('Project details draft failed', ['project_id' => $this->projectId, 'error' => $e->getMessage()]);
            } finally {
                ProjectDetailsStatus::clearGenerating($this->projectId);
            }
        };

        $site = $project?->site;
        $site ? Tenancy::for($site, $run) : $run();
    }

    /**
     * The facts the admin filled in before asking for the draft (the facts
     * step PUTs project_type/location/completed_at/testimonial_ids/
     * collaborators before this runs) — each a line the model may use,
     * none to be embellished. Unlike jpeterson-design's job, project_type
     * IS given as a fact here: gsc's existing house style
     * (AiContentService::generateProjectDescription()) always states the
     * category explicitly rather than asking the model to infer it from
     * the photos.
     */
    public function prompt(Project $project): string
    {
        $facts = [];
        if ($project->project_type) {
            $facts[] = 'Type of work: '.$project->typeLabel().'.';
        }
        if ($project->location) {
            $facts[] = "Location: {$project->location}.";
        }
        if ($project->completed_at) {
            $facts[] = 'Completed: '.$project->completed_at->format('F Y').'.';
        }
        foreach ($project->collaborators as $c) {
            $role = ProjectCollaborator::roles()[$c->role] ?? $c->role;
            $facts[] = "Worked with: {$c->name} ({$role})".($c->note ? " — {$c->note}" : '').'.';
        }
        foreach ($project->testimonials->take(3) as $t) {
            $facts[] = 'Client review'.($t->reviewer_name ? " from {$t->reviewer_name}" : '').': "'.Str::limit(trim((string) $t->review_description), 400).'"';
        }
        $factsBlock = $facts === [] ? "No further facts were provided.\n" : implode("\n", $facts)."\n";

        return <<<PROMPT
        You are an SEO and GEO (generative-engine optimization) copywriter for GS Construction,
        a licensed and insured family-owned home remodeling company serving the Chicago suburbs since 2015.
        The attached photos are all from one finished project. Study them carefully. Facts about it, from the company:
        {$factsBlock}
        Write a title and a description for the company's project portfolio.

        Rules:
        - Use the facts above and what is actually visible in the photos — nothing else. Never invent rooms, fittings, materials you cannot see, dates, brand names, client details, or awards.
        - The location may be named once, naturally. A partner may be credited once by name and role. A client review may inform what mattered to the client; quote at most a short phrase from it, and never make up a quote.
        - Title: 3 to 8 words, no trailing period, does not repeat "GS Construction".
        - Description: 100-150 words (about 5-7 sentences). References specific materials, fixtures, finishes and design features actually visible across the photos. Write counts, quantities and measurements as digit numerals (e.g. "3 pendant lights"), never spelled-out words, and ONLY when clearly visible in the photos or safe/generic (typical 4-10 week timelines, licensed since 2015). Names "GS Construction" at least once. Is SEO-optimized with natural local-remodeling keywords. Sounds professional but approachable.

        Answer with JSON only, no commentary: {"title": "...", "description": "..."}
        PROMPT;
    }

    /** @return array{title: string, description: string}|null */
    public static function parse(string $raw): ?array
    {
        $text = trim($raw);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;

        $data = json_decode($text, true);
        if (! is_array($data) && preg_match('/\{.*\}/s', $text, $m)) {
            $data = json_decode($m[0], true);
        }

        $title = trim((string) ($data['title'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));

        if ($title === '' || $description === '') {
            return null;
        }

        return [
            'title' => Str::limit(rtrim($title, '.'), 120, ''),
            'description' => $description,
        ];
    }
}
