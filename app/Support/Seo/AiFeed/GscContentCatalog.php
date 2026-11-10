<?php

namespace App\Support\Seo\AiFeed;

use App\Models\BlogPost;
use App\Models\LandingPage;
use SsSystems\Platform\Seo\AiFeed\Contracts\ContentCatalog;

/** Moved verbatim out of the old `AiFeedController` closure — same query, same row shape. */
final class GscContentCatalog implements ContentCatalog
{
    public function stories(): array
    {
        return BlogPost::published()
            ->with('project')
            ->orderByDesc('dated_at')
            ->limit(50)
            ->get()
            ->map(fn (BlogPost $b): array => array_filter([
                'id' => $b->id,
                'title' => $b->title,
                'url' => $b->url(),
                'summary' => $b->excerpt,
                'project_type' => $b->project?->project_type,
                'location' => $b->project?->location,
                'project_url' => $b->project ? url('/projects/'.$b->project->slug) : null,
                'date' => $b->displayDate()?->toDateString(),
            ]))
            ->values()
            ->all();
    }

    /**
     * The guide and town pages under /remodeling/ — only those the sitemap
     * lists (published, indexed, with real project proof). The kit's
     * ContentCatalog contract only knows `stories()`, so AiFeedController
     * hands this in through the feed's `extra` block as `guides`.
     *
     * @return list<array<string,mixed>>
     */
    public function guides(): array
    {
        return LandingPage::published()
            ->orderBy('title')
            ->get()
            ->filter(fn (LandingPage $page): bool => $page->shouldIndex())
            ->map(fn (LandingPage $page): array => array_filter([
                'id' => $page->id,
                'title' => $page->title,
                'url' => $page->url(),
                'summary' => $page->meta_description,
                'kind' => $page->isGuide() ? 'guide' : 'town',
                'service' => $page->service,
                'location' => $page->city,
                'answers' => $page->target_query,
                'date' => $page->published_at?->toDateString(),
            ]))
            ->values()
            ->all();
    }
}
