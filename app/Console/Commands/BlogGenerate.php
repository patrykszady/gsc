<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\Blog\ProjectBlogWriter;
use Illuminate\Console\Command;

/**
 *   php artisan blog:generate 36            draft for one project (skips if a post exists)
 *   php artisan blog:generate 36 --force    rewrite the existing draft
 *   php artisan blog:generate --all         drafts for every published project without one
 */
class BlogGenerate extends Command
{
    protected $signature = 'blog:generate {project? : Project id or slug} {--all} {--force : Rewrite even if a post exists}';

    protected $description = 'Write AI blog-post DRAFTS for projects (never publishes).';

    public function handle(ProjectBlogWriter $writer): int
    {
        $projects = $this->option('all')
            ? Project::published()->whereDoesntHave('blogPost')->get()
            : Project::where('id', $this->argument('project'))->orWhere('slug', $this->argument('project'))->get();

        if ($projects->isEmpty()) {
            $this->warn('No matching projects.');

            return self::FAILURE;
        }

        $ok = 0;
        foreach ($projects as $project) {
            if (! $this->option('force') && $project->blogPost()->exists()) {
                $this->line("  skip #{$project->id} {$project->title} (post exists)");

                continue;
            }
            $post = $writer->write($project);
            if ($post === null) {
                $this->error("  FAIL #{$project->id} {$project->title}: " . ($writer->getLastError() ?? 'unknown'));

                continue;
            }
            $ok++;
            $this->info("  draft #{$post->id} /blog/{$post->slug} — \"{$post->title}\" (" . str_word_count((string) $post->body) . ' words)');
            if ($projects->count() > 1) {
                sleep(10); // gemini rpm
            }
        }
        $this->info("Done. drafts={$ok}");

        return self::SUCCESS;
    }
}
