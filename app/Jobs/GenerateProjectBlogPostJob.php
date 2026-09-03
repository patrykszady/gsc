<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\Blog\ProjectBlogWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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

    public function handle(ProjectBlogWriter $writer): void
    {
        $run = function () use ($writer): void {
            if (! $this->force && $this->project->blogPost()->exists()) {
                return;
            }
            // Wait for the description the AI-content pipeline writes on
            // create — the post is far better with it. Retry once later.
            if (empty($this->project->fresh()->description) && $this->attempts() < 2) {
                $this->release(600);

                return;
            }

            $post = $writer->write($this->project->fresh());
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
        $site ? \App\Support\Tenancy::for($site, $run) : $run();
    }
}
