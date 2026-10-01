<?php

namespace App\Support\Projects;

use App\Models\ProjectSlugHistory;
use Illuminate\Database\Eloquent\Model;
use SsSystems\Platform\Projects\Contracts\RecordsProjectSlugRedirect;

/**
 * The `Contracts\RecordsProjectSlugRedirect` adapter over this site's own
 * `ProjectSlugHistory` model/table — used by `App\Models\Project`'s
 * slug-rename hook (SsSystems\Platform\Projects\Concerns\HasProjectAreas)
 * and by `SsSystems\Platform\Projects\ProjectMerger` (through
 * `App\Console\Commands\ProjectsMerge`).
 *
 * Unlike jpeterson-design's own adapter (whose `project_slug_redirects`
 * rows only ever point at the current project), `project_slug_history` is a
 * foreign key TO the project it belongs to, `cascadeOnDelete()` — see
 * database/migrations/2026_08_02_170000_create_project_slug_history_table.php.
 * `repointRedirects()` therefore cannot be a no-op here: a merged-away
 * source's whole address history would otherwise cascade away with it the
 * moment `ProjectMerger` deletes the source row, rather than surviving
 * under the target the way a single slug does.
 */
final class GscSlugRedirectRecorder implements RecordsProjectSlugRedirect
{
    public function recordRedirectIfAbsent(string $oldSlug, Model $project, ?string $anchor = null): void
    {
        // firstOrCreate: projects:merge (recordRedirect(), below) may have
        // just written this row itself, with a real area anchor — never
        // clobber that.
        ProjectSlugHistory::query()->firstOrCreate(['slug' => $oldSlug], ['project_id' => $project->getKey(), 'anchor' => $anchor]);

        // A slug being reclaimed by the project that now owns it is no
        // longer historical — drop the redirect so it does not loop.
        ProjectSlugHistory::query()->where('slug', $project->slug)->delete();
    }

    public function recordRedirect(string $oldSlug, Model $project, ?string $anchor = null, ?int $sourceProjectId = null): void
    {
        ProjectSlugHistory::query()->updateOrCreate(
            ['slug' => $oldSlug],
            ['project_id' => $project->getKey(), 'anchor' => $anchor, 'source_project_id' => $sourceProjectId]
        );
    }

    public function forgetRedirect(string $slug): void
    {
        ProjectSlugHistory::query()->where('slug', $slug)->delete();
    }

    public function repointRedirects(Model $source, Model $target): void
    {
        // Every history row still naming the merged-away source as its
        // project_id (its WHOLE address history, not just the most recent
        // slug) now belongs under the target — see this class's own
        // docblock for why this can't be a no-op on gsc.
        ProjectSlugHistory::query()->where('project_id', $source->getKey())->update(['project_id' => $target->getKey()]);
    }

    public function findBySourceProjectId(int $sourceProjectId): ?array
    {
        $history = ProjectSlugHistory::query()->where('source_project_id', $sourceProjectId)->with('project')->first();

        if (! $history?->project) {
            return null;
        }

        return ['project' => $history->project, 'anchor' => $history->anchor];
    }
}
