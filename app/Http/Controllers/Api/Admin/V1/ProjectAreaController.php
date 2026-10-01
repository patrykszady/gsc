<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Jobs\GenerateAreaDescriptionJob;
use App\Models\Project;
use App\Models\ProjectArea;
use Illuminate\Database\Eloquent\Model;
use SsSystems\Platform\Projects\Http\Concerns\ServesProjectAreas;

/**
 * Every project-areas admin-API endpoint that doesn't ride the plain
 * `projects` resource — new area, area cover, per-area description draft,
 * bulk image-area assignment. See routes/api-admin/project-areas.php and
 * the project-areas admin-API contract's 2026-10-01 addendum (covers,
 * per-area descriptions). The actions themselves live in the kit
 * (ServesProjectAreas, ss-platform-kit 0.16.0) — this controller is just the
 * 5 hooks it needs.
 *
 * Extends ProjectController (rather than the bare Controller) only to reach
 * its protected withCrmFields() — every other project-areas endpoint should
 * answer with the SAME full project shape ProjectController@show/update
 * already does (blog status, testimonial links, partner credits — not a
 * stripped-down one), and `BuildsApiResponses::itemResponse()`/
 * `acceptedResponse()` ServesProjectAreas calls come along with it.
 */
class ProjectAreaController extends ProjectController
{
    use ServesProjectAreas;

    protected function projectModel(): string
    {
        return Project::class;
    }

    protected function projectAreaModel(): string
    {
        return ProjectArea::class;
    }

    protected function projectTypeKeys(): array
    {
        return array_keys(Project::projectTypes());
    }

    protected function projectAreaPayload(Model $project): array
    {
        /** @var Project $project */
        return $this->withCrmFields($project->fresh(['images.tags', 'areas.images', 'testimonials'])->toApiArray(), $project);
    }

    protected function launchAreaDescriptionDraft(Model $area): void
    {
        GenerateAreaDescriptionJob::launch($area);
    }
}
