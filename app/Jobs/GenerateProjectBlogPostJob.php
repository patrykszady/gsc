<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\Blog\ProjectBlogWriter;
use App\Support\Tenancy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Draft a blog post for a newly added project. Dispatched by ProjectObserver
 * on create (and by blog:generate). Never publishes.
 */
class GenerateProjectBlogPostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 240;

    public int $tries = 2;

    public function __construct(public Project $project, public bool $force = false)
    {
        $this->onQueue('ai-content');
    }

    /** Cache key set while a draft is being written for the project (the admin polls it). */
    public static function generatingKey(Project $project): string
    {
        return "blog:generating:{$project->id}";
    }

    public function handle(ProjectBlogWriter $writer): void
    {
        $run = function () use ($writer): void {
            if (! $this->force && $this->project->blogPost()->exists()) {
                return;
            }

            $fresh = $this->project->fresh();

            // The photos-first draft flow creates the project under a
            // placeholder title (GenerateProjectDetailsJob::
            // PLACEHOLDER_TITLE) until the admin explicitly drafts the real
            // one from the uploaded photos — a post about "New project"
            // would be worse than no post at all. Retry once (the same
            // window as the description wait below), then give up silently
            // rather than write one; nothing re-dispatches this once
            // details land, so publishing from there is the admin's own
            // "regenerate" action on the blog card.
            if (! $this->force && $fresh->title === GenerateProjectDetailsJob::PLACEHOLDER_TITLE) {
                if ($this->attempts() < 2) {
                    $this->release(600);

                    return;
                }

                Log::channel('ai_content')->info('Blog draft skipped — project is still a photos-first draft', ['project_id' => $fresh->id]);

                return;
            }

            // Wait for the description the AI-content pipeline writes on
            // create — the post is far better with it. Retry once later.
            // A forced run (the admin's button) writes with whatever exists.
            if (! $this->force && empty($fresh->description) && $this->attempts() < 2) {
                $this->release(600);

                return;
            }

            $post = $writer->write($fresh);
            if ($post === null) {
                Log::channel('ai_content')->warning('Blog draft failed', [
                    'project_id' => $this->project->id,
                    'error' => $writer->getLastError(),
                ]);

                return;
            }

            Log::channel('ai_content')->info('Blog draft written', ['project_id' => $this->project->id, 'post_id' => $post->id]);
        };

        $site = $this->project->site;
        try {
            $site ? Tenancy::for($site, $run) : $run();
        } finally {
            Cache::forget(static::generatingKey($this->project));
        }
    }

    public function failed(?\Throwable $e = null): void
    {
        Cache::forget(static::generatingKey($this->project));
    }
}
