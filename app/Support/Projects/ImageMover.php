<?php

namespace App\Support\Projects;

use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectImage;
use Illuminate\Validation\ValidationException;
use SsSystems\Platform\Projects\ProjectFileRelocator;

/**
 * Move photos from one project to another — an existing project, or a
 * new draft made on the spot (photos from one shoot that turn out to be
 * two projects). The files move with the rows into the target project's
 * folder (originals and renditions both live under projects/{id}/), the
 * moved photos go to the end of the target's order, and each side ends
 * up with a cover: the target's first photo if it had none, the source's
 * first remaining photo if its cover left.
 *
 * Same class on jpeterson-design — the two backends stay in step.
 */
class ImageMover
{
    /**
     * @param  array<int, int>  $imageIds  photos of $source to move
     * @param  Project|null  $target  an existing project, or null to create a draft
     * @param  string|null  $title  the draft's title (null: the photos-first placeholder)
     * @return array{target: Project, created: bool, moved: int}
     */
    public static function move(Project $source, array $imageIds, ?Project $target = null, ?string $title = null): array
    {
        $images = $source->images()->whereIn('id', $imageIds)->orderBy('sort_order')->orderBy('id')->get();

        if ($images->count() !== count(array_unique($imageIds))) {
            throw ValidationException::withMessages(['image_ids' => 'Every photo to move must belong to this project.']);
        }

        if ($target && (int) $target->id === (int) $source->id) {
            throw ValidationException::withMessages(['project_id' => 'Pick a different project to move the photos to.']);
        }

        $created = false;
        if (! $target) {
            $target = Project::create([
                'title' => filled($title) ? trim($title) : 'New project',
                'is_published' => false,
                'is_featured' => false,
                // The same shoot, so the same facts until someone says otherwise.
                'project_type' => $source->project_type,
                'location' => $source->location,
                'completed_at' => $source->completed_at,
            ]);
            $created = true;
        }

        $targetHadImages = $target->images()->exists();
        $sourceCoverMoved = $images->contains(fn (ProjectImage $image) => $image->is_cover);
        $next = (int) $target->images()->max('sort_order') + 1;

        // A moved photo always leaves area-less on the target (see the
        // forceFill below); clear it first as the SOURCE area's chosen
        // cover, if it was one — 2026-10-01 addendum (covers).
        ProjectArea::clearStaleCovers($source->id, $images->pluck('id')->all());

        foreach ($images as $image) {
            static::relocateFiles($image, $target);
            $image->forceFill([
                'project_id' => $target->id,
                // The area it belonged to was one of the SOURCE project's —
                // meaningless (and, once the source is a draft with no
                // matching area, orphaned) on the target. A photo that moves
                // to another project always starts area-less there; an admin
                // re-assigns it with POST …/images/area if needed.
                'project_area_id' => null,
                'is_cover' => false,
                'sort_order' => $next++,
            ])->save();
        }

        if (! $targetHadImages) {
            $images->first()?->forceFill(['is_cover' => true])->save();
        }

        if ($sourceCoverMoved) {
            $source->images()->orderBy('sort_order')->orderBy('id')->first()?->forceFill(['is_cover' => true])->save();
        }

        return ['target' => $target, 'created' => $created, 'moved' => $images->count()];
    }

    /**
     * Carry the original and every rendition into the target's folder,
     * keeping names unless one is taken. Public (not just this class' own
     * move()): SsSystems\Platform\Projects\ProjectMerger (kit, 0.16.0) calls
     * the kit's own copy of this same logic to relocate a merged-away
     * project's photos — this method is now a thin delegate so every other
     * call site here keeps working unchanged.
     */
    public static function relocateFiles(ProjectImage $image, Project $target): void
    {
        ProjectFileRelocator::relocate($image, $target);
    }
}
