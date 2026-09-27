<?php

namespace App\Support\Seo\AiFeed;

use App\Models\BlogPost;
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
}
