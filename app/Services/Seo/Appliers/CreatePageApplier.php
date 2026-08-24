<?php

namespace App\Services\Seo\Appliers;

use App\Models\LandingPage;
use App\Models\SeoAction;
use App\Services\Seo\ActionApplier;
use RuntimeException;

/**
 * Materializes an Autopilot-proposed landing page as a DRAFT.
 *
 * Deliberately conservative: even in full-auto mode this only creates a draft
 * (not live, not indexed), because publishing brand-new indexable pages is the
 * single riskiest SEO action — exactly the mistake behind the thin-page sprawl.
 * Publishing is a one-click human step in the admin panel (or set
 * config('seo.autopilot.auto_publish_landing_pages') to auto-publish once trusted).
 *
 * Reversible: revert() deletes the draft it created (only if still an
 * autopilot-owned draft, so a human's edits/publish are never destroyed).
 */
class CreatePageApplier implements ActionApplier
{
    public function category(): string
    {
        return 'create_page';
    }

    public function apply(SeoAction $action): void
    {
        $content = $action->payload['content'] ?? null;
        if (! is_array($content) || empty($content['slug'])) {
            throw new RuntimeException("create_page action #{$action->id} has no content payload.");
        }

        $autoPublish = (bool) config('seo.autopilot.auto_publish_landing_pages', false);

        $attrs = array_merge($content, [
            'source' => 'autopilot',
            'status' => $autoPublish ? LandingPage::STATUS_PUBLISHED : LandingPage::STATUS_DRAFT,
            'published_at' => $autoPublish ? now() : null,
        ]);

        $page = LandingPage::updateOrCreate(['slug' => $content['slug']], $attrs);

        // A page nobody is told about indexes never. On auto-publish, run the
        // same announcement chain a deploy runs: IndexNow for Bing (the
        // "uploaded to indexnow" half), and the debounced sitemap regeneration
        // whose job also re-submits both sitemaps to Google Search Console —
        // so the new URL is in the sitemap Google is asked to re-read within
        // the same hour it goes live.
        if ($autoPublish) {
            try {
                app(\App\Services\IndexNowService::class)->submit(url('/remodeling/' . $page->slug));
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('create_page: IndexNow submit failed', ['error' => $e->getMessage()]);
            }
            \App\Support\SEO\RecrawlNudger::nudge();
        }

        $payload = $action->payload;
        $payload['landing_page_id'] = $page->id;
        $action->payload = $payload;
    }

    public function revert(SeoAction $action): void
    {
        $id = $action->payload['landing_page_id'] ?? null;
        if (! $id) {
            return;
        }

        // Only remove a page that is still an untouched autopilot draft.
        LandingPage::where('id', $id)
            ->where('source', 'autopilot')
            ->where('status', LandingPage::STATUS_DRAFT)
            ->first()?->delete();
    }
}
