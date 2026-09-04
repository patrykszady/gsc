<?php

namespace App\Observers;

use App\Jobs\AnnounceBlogPostJob;
use App\Models\BlogPost;
use App\Support\SEO\RecrawlNudger;
use Illuminate\Support\Facades\Cache;

/**
 * A post going live (or changing address, or going away) is announced to
 * the crawlers — see AnnounceBlogPostJob. Drafts are invisible and stay so.
 */
class BlogPostObserver
{
    public function saved(BlogPost $post): void
    {
        $wentLive = $post->isPublished() && (
            $post->wasChanged('status') || $post->wasChanged('published_at') || $post->wasRecentlyCreated
        );
        $movedWhileLive = $post->isPublished() && $post->wasChanged(['slug', 'title', 'body']);

        if ($wentLive || $movedWhileLive) {
            // Small delay: lets the same request's follow-up writes settle,
            // and batches a publish-then-tweak into one announcement.
            AnnounceBlogPostJob::dispatch($post)->delay(now()->addSeconds(20));

            return;
        }

        // Unpublished: drop it from the sitemap and the AI feed.
        if ($post->wasChanged('status') && ! $post->isPublished()) {
            RecrawlNudger::nudge();
            Cache::forget('ai_feed_v1');
        }
    }

    public function deleted(BlogPost $post): void
    {
        if ($post->isPublished()) {
            RecrawlNudger::nudge();
            Cache::forget('ai_feed_v1');
        }
    }
}
