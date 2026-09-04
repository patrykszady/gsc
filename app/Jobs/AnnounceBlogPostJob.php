<?php

namespace App\Jobs;

use App\Models\BlogPost;
use App\Services\IndexNowService;
use App\Support\SEO\RecrawlNudger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Tell the crawlers about a post the moment it is published — the same
 * announcement chain a deploy and an auto-published landing page use:
 *
 *  - IndexNow (Bing/Yandex, instant) for the post, the blog index and the
 *    project page (which now links to the story).
 *  - RecrawlNudger → RegenSitemapsAndNotifyJob: sitemap + image sitemap
 *    regenerated with the post, then re-submitted to Google Search Console
 *    (sitemaps.submit is Google's supported nudge; IndexNow never reaches
 *    Google and the ping endpoint died in 2023).
 *  - GEO: llms.txt / llms-full.txt rebuilt so the story is listed for
 *    ChatGPT/Claude/Perplexity crawlers, and the AI feed cache dropped so
 *    /ai-feed.json carries it on the next fetch.
 */
class AnnounceBlogPostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(public BlogPost $post) {}

    public function handle(IndexNowService $indexNow): void
    {
        $run = function () use ($indexNow): void {
            $post = $this->post->fresh('project');
            if (! $post || ! $post->isPublished()) {
                return;
            }

            $urls = array_values(array_filter([
                $post->url(),
                route('blog.index'),
                $post->project ? route('projects.show', $post->project) : null,
            ]));

            try {
                $indexNow->submitBatch($urls);
            } catch (\Throwable $e) {
                Log::warning('Blog post: IndexNow submit failed', ['post_id' => $post->id, 'error' => $e->getMessage()]);
            }

            RecrawlNudger::nudge();

            Cache::forget('ai_feed_v1');
            foreach (['geo:llms-txt', 'geo:llms-txt --full'] as $cmd) {
                try {
                    Artisan::call($cmd);
                } catch (\Throwable $e) {
                    Log::warning('Blog post: llms.txt regen failed', ['cmd' => $cmd, 'error' => $e->getMessage()]);
                }
            }

            Log::info('Blog post announced to crawlers', ['post_id' => $post->id, 'urls' => $urls]);
        };

        $site = $this->post->site;
        $site ? \App\Support\Tenancy::for($site, $run) : $run();
    }
}
