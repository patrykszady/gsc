<?php

namespace App\Jobs;

use App\Models\ProjectArea;
use App\Services\AiContentService;
use App\Support\Tenancy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use SsSystems\Platform\Projects\AreaDescriptionStatus;
use SsSystems\Platform\Projects\AreaPhotoSelection;

/**
 * Drafts one area's description from its own photos (Gemini vision) — the
 * per-area equivalent of AiContentService::generateProjectDescription(),
 * for the project-areas module adopted from jpeterson-design.com via
 * ss-platform-kit 0.16.0 (see that kit's docs/PROJECT-AREAS.md and the
 * project-areas admin-API contract's 2026-10-01 addendum).
 *
 * Unlike the whole-project description, this job writes a single field
 * (`description`) and nothing else — never the area's title or
 * project_type, and never anything on the project itself. There is no
 * placeholder/blank gate either: every run (the admin's "Generate" or "Try
 * again") replaces whatever description is already there.
 *
 * The cache-backed generating/error status (flagKey/errorKey/status/
 * launch) and the photo selection (cover first, then sort order, capped at
 * MAX_IMAGES) are the kit's now — AreaDescriptionStatus/AreaPhotoSelection —
 * identical cache keys and ordering rule as jpeterson-design's own job, so
 * the admin's polling behaves the same on both sites. What stays here,
 * deliberately (see the kit's docs): the actual Gemini call, this site's
 * prompt wording/house style (the same SEO/GEO copywriter voice
 * generateProjectDescription() already uses), and running under
 * Tenancy::for($project->site) — only this site's own job can know to do
 * that (see GenerateProjectBlogPostJob for the identical pattern).
 */
class GenerateAreaDescriptionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Same cap as AiContentService::generateProjectDescription()'s own vision context. */
    public const MAX_IMAGES = 5;

    public int $timeout = 240;

    public function __construct(public int $areaId)
    {
        $this->onQueue('ai-content');
    }

    public static function flagKey(int $areaId): string
    {
        return AreaDescriptionStatus::flagKey($areaId);
    }

    public static function errorKey(int $areaId): string
    {
        return AreaDescriptionStatus::errorKey($areaId);
    }

    /** @return array{generating: bool, error: ?string} */
    public static function status(ProjectArea $area): array
    {
        return AreaDescriptionStatus::status($area);
    }

    /**
     * Flag first, dispatch after the response: the admin expects its 202
     * back at once, then polls the area's `details` block.
     */
    public static function launch(ProjectArea $area): void
    {
        AreaDescriptionStatus::markGenerating($area);
        self::dispatchAfterResponse($area->id);
    }

    public function handle(AiContentService $ai): void
    {
        $area = ProjectArea::with(['images', 'project.site'])->find($this->areaId);

        $run = function () use ($area, $ai): void {
            try {
                if (! $area || ! $area->project) {
                    return;
                }

                $images = AreaPhotoSelection::choose($area->images, $area->cover(), self::MAX_IMAGES);
                if ($images->isEmpty()) {
                    throw new \RuntimeException('None of the photos could be read.');
                }

                $raw = $ai->generateWithImages($this->prompt($area), $images, 600, 0.5);
                if ($raw === null) {
                    throw new \RuntimeException($ai->getLastError() ?: 'The model returned nothing.');
                }

                $description = trim($raw);
                if ($description === '') {
                    throw new \RuntimeException('The model did not answer with a description.');
                }

                // Reruns replace the description unconditionally — there is
                // no blank-or-force gate here, unlike the project-level one.
                $area->forceFill(['description' => $description])->save();

                Log::channel('ai_content')->info('Area description drafted from photos', ['area_id' => $area->id, 'photos' => $images->count()]);
            } catch (\Throwable $e) {
                AreaDescriptionStatus::recordError($this->areaId, $e->getMessage());
                Log::channel('ai_content')->warning('Area description draft failed', ['area_id' => $this->areaId, 'error' => $e->getMessage()]);
            } finally {
                AreaDescriptionStatus::clearGenerating($this->areaId);
            }
        };

        $site = $area?->project?->site;
        $site ? Tenancy::for($site, $run) : $run();
    }

    /**
     * This area's own cover first, then the rest by sort order (see
     * AreaPhotoSelection::choose(), called from handle() before this), each
     * read through AiContentService::generateWithImages() — the 'large'
     * thumbnail when one exists, else the original.
     */
    public function prompt(ProjectArea $area): string
    {
        $project = $area->project;

        // The facts the company has on hand for this area: its own
        // category, plus the project's location/completion date — nothing
        // else (no collaborators/reviews, unlike a whole-project draft,
        // since those speak to the job as a whole, not to any one area).
        $facts = ["Area: {$area->typeLabel()}."];
        if ($project->location) {
            $facts[] = "Location: {$project->location}.";
        }
        if ($project->completed_at) {
            $facts[] = 'Completed: '.$project->completed_at->format('F Y').'.';
        }
        $factsBlock = implode("\n", $facts);

        return <<<PROMPT
        You are an SEO and GEO (generative-engine optimization) copywriter for GS Construction,
        a licensed and insured family-owned home remodeling company serving the Chicago suburbs since 2015.
        The attached photos are all from one area of a larger finished project. Study them carefully. Facts about this area, from the company:
        {$factsBlock}

        Write a description of this area for the company's project portfolio, 90-150 words (about 4-6 sentences), that:
        - References specific materials, fixtures, finishes, and design features actually visible across the photos — never invent rooms, fittings, materials you cannot see, dates, brand names, client details, or awards.
        - Writes counts, quantities and measurements as digit numerals (e.g. "3 pendant lights"), never spelled-out words, and ONLY when clearly visible in the photos or safe/generic (typical 4-10 week timelines, licensed since 2015).
        - Names "GS Construction" at least once.
        - Is SEO-optimized with natural local-remodeling keywords.
        - Sounds professional but approachable.

        Return ONLY the description text (plain prose, no headings, no JSON, no markdown).
        PROMPT;
    }
}
