<?php

namespace App\Console\Commands;

use App\Jobs\SubmitUrlsToIndexNow;
use App\Models\Project;
use App\Support\Projects\GscSlugRedirectRecorder;
use App\Support\Projects\ProjectOwnedRowsRelocator;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use SsSystems\Platform\Projects\Console\MergeProjectsCommand;
use SsSystems\Platform\Projects\Contracts\RecordsProjectSlugRedirect;
use SsSystems\Platform\Projects\Contracts\RelocatesProjectOwnedRows;

/**
 * Fold one or more projects into another as areas of it — see
 * ss-platform-kit's docs/PROJECT-AREAS.md and
 * SsSystems\Platform\Projects\ProjectMerger (0.16.0), which does the actual
 * work now. `target` and each of `sources` may be a project id or a slug.
 *
 * Example: three separate jobs that were really one house —
 *   php artisan projects:merge palatine-kitchen-remodel \
 *       palatine-mudroom-refresh palatine-basement-finish \
 *       --type=home-remodel --title="Palatine Whole-Home Remodel"
 *
 * Safe to re-run: a source already merged away (its row no longer exists)
 * is reported and skipped rather than erroring — see ProjectMerger's
 * docblock for why that makes the whole command idempotent.
 */
class ProjectsMerge extends MergeProjectsCommand
{
    protected function projectModel(): string
    {
        return Project::class;
    }

    protected function defaultMergeType(): string
    {
        return 'home-remodel';
    }

    protected function relocator(): RelocatesProjectOwnedRows
    {
        return new ProjectOwnedRowsRelocator;
    }

    protected function slugRedirects(): RecordsProjectSlugRedirect
    {
        return new GscSlugRedirectRecorder;
    }

    /**
     * ProjectObserver regenerates the sitemap synchronously (Artisan::call)
     * and queues an IndexNow submission on EVERY Project create/update/
     * delete — a merge saves/deletes several Project rows in one request
     * (one area folded per source, the target's own update, each source's
     * delete). Suppressing every model event for the duration of the merge
     * and doing both once at the end (see printResult() below) turns that
     * into the single sitemap regen + IndexNow submission the observer
     * would have produced for the target's own save, instead of one per
     * row touched.
     *
     * @return ?Closure(Closure): mixed
     */
    protected function runQuietly(): ?Closure
    {
        return fn (Closure $work) => Model::withoutEvents($work);
    }

    /**
     * @param  array{target: Model, areas_created: int, sources: array<int, array<string, mixed>>}  $result
     */
    protected function printResult(array $result): void
    {
        parent::printResult($result);

        if ($result['areas_created'] === 0) {
            // Nothing changed — runQuietly() suppressed nothing worth
            // re-running (ProjectMerger's own update() guard, see its
            // docblock, means this was already a no-op).
            return;
        }

        $this->resyncAfterMerge($result['target']);
    }

    /**
     * What ProjectObserver::updated()/deleted() would have done for the
     * target's own save, done once instead of once per row the merge
     * touched — see runQuietly() above and that observer's own methods.
     */
    protected function resyncAfterMerge(Model $target): void
    {
        try {
            Artisan::call('sitemap:generate');
        } catch (\Throwable $e) {
            Log::warning('Failed to regenerate sitemap after projects:merge', ['error' => $e->getMessage()]);
        }

        if (! config('indexnow.auto_submit', true) || ! $target->is_published) {
            return;
        }

        try {
            SubmitUrlsToIndexNow::dispatch([
                route('projects.show', $target),
                route('projects.index'),
            ])->onQueue('default')->delay(now()->addSeconds(15));
        } catch (\Throwable $e) {
            Log::channel('indexnow')->warning('IndexNow: failed to queue project URL submission after projects:merge', [
                'project_id' => $target->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
