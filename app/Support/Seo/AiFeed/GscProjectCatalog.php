<?php

namespace App\Support\Seo\AiFeed;

use App\Models\Project;
use SsSystems\Platform\Seo\AiFeed\Contracts\ProjectCatalog;

/** Moved verbatim out of the old `AiFeedController` closure — same query, same row shape. */
final class GscProjectCatalog implements ProjectCatalog
{
    public function projects(): array
    {
        return Project::where('is_published', true)
            ->orderByDesc('is_featured')
            ->orderByDesc('completed_at')
            ->limit(50)
            ->get()
            ->map(fn (Project $p): array => array_filter([
                'id' => $p->id,
                'title' => $p->title,
                'slug' => $p->slug,
                'url' => $p->url(),
                'description' => $p->description,
                'project_type' => $p->project_type,
                'location' => $p->location,
                'completed_at' => optional($p->completed_at)->toDateString(),
                'is_featured' => (bool) $p->is_featured,
            ]))
            ->values()
            ->all();
    }
}
