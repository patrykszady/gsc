<?php

namespace App\Support\Projects;

use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use SsSystems\Platform\Projects\Contracts\RelocatesProjectOwnedRows;

/**
 * The `Contracts\RelocatesProjectOwnedRows` adapter for this site's own
 * Project: everything a merged-away project owns besides its photos and
 * its own project_type/description (`SsSystems\Platform\Projects\
 * ProjectMerger` folds those itself) — the testimonials pivot,
 * collaborators, before/afters, timelapses, and its blog post when the
 * target has none. Same shape as jpeterson-design's own adapter (the two
 * sites' Project models expose the same relation names here).
 */
final class ProjectOwnedRowsRelocator implements RelocatesProjectOwnedRows
{
    public function relocate(Model $source, Model $target): array
    {
        /** @var Project $source */
        /** @var Project $target */
        $testimonialIds = $source->testimonials()->pluck('testimonials.id');
        if ($testimonialIds->isNotEmpty()) {
            $target->testimonials()->syncWithoutDetaching($testimonialIds->all());
            $source->testimonials()->detach();
        }

        $collaboratorsMoved = $source->collaborators()->update(['project_id' => $target->id]);
        $beforeAftersMoved = $source->beforeAfters()->update(['project_id' => $target->id]);
        $timelapsesMoved = $source->timelapses()->update(['project_id' => $target->id]);

        $blogMoved = false;
        $sourceBlog = $source->blogPost()->first();
        if ($sourceBlog) {
            $blogMoved = $target->blogPost()->doesntExist();
            // Moved when the target had none; otherwise unlinked rather than
            // left pointing at a project id about to be deleted —
            // blog_posts.project_id is nullable for exactly this case (a
            // post with no linked project).
            $sourceBlog->update(['project_id' => $blogMoved ? $target->id : null]);
        }

        return [
            'testimonials_moved' => $testimonialIds->count(),
            'collaborators_moved' => $collaboratorsMoved,
            'before_afters_moved' => $beforeAftersMoved,
            'timelapses_moved' => $timelapsesMoved,
            'blog_post_moved' => $blogMoved,
        ];
    }

    public function describe(Model $project): string
    {
        /** @var Project $project */
        return $project->testimonials()->count().' testimonial(s), '
            .$project->collaborators()->count().' collaborator(s), '
            .$project->beforeAfters()->count().' before/after(s), '
            .$project->timelapses()->count().' timelapse(s)'
            .($project->blogPost()->exists() ? ', a blog post' : '');
    }

    public function describeMoved(array $relocated): string
    {
        return $relocated['testimonials_moved'].' testimonial(s), '
            .$relocated['collaborators_moved'].' collaborator(s), '
            .$relocated['before_afters_moved'].' before/after(s), '
            .$relocated['timelapses_moved'].' timelapse(s)'
            .($relocated['blog_post_moved'] ? ', blog post moved' : '');
    }
}
