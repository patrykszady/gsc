<?php

namespace App\Services\Seo\Appliers;

use App\Models\SeoAction;
use App\Models\SeoPathOverride;
use App\Services\Seo\ActionApplier;
use RuntimeException;

/**
 * Applies a CTR-oriented title + meta-description rewrite as a path-keyed
 * override (SeoPathOverride), which SEOBuilder::build() applies at the top of
 * the precedence chain. This reaches area pages — whose titles come from
 * SeoService without a bound model — where the polymorphic `seo` row cannot.
 *
 * Payload contract:
 *   new_title, new_description   — the rewrite to apply (set at synthesis time)
 *   prev_title, prev_description — captured here so revert is exact
 *   prev_existed                 — whether a row already existed before apply
 */
class TitleMetaApplier implements ActionApplier
{
    public function category(): string
    {
        return 'title_meta';
    }

    public function apply(SeoAction $action): void
    {
        $url = (string) $action->target_url;
        if ($url === '') {
            throw new RuntimeException("title_meta action #{$action->id} has no target_url.");
        }

        $payload = $action->payload ?? [];
        $newTitle = $this->clean($payload['new_title'] ?? null);
        $newDescription = $this->clean($payload['new_description'] ?? null);

        if ($newTitle === null && $newDescription === null) {
            throw new RuntimeException('title_meta action has no new_title/new_description to apply.');
        }

        $path = SeoPathOverride::normalizePath($url);
        $existing = SeoPathOverride::where('path', $path)->first();

        // Snapshot prior state so revert restores it exactly.
        $payload['prev_existed'] = (bool) $existing;
        $payload['prev_title'] = $existing?->title;
        $payload['prev_description'] = $existing?->description;

        SeoPathOverride::updateOrCreate(
            ['path' => $path],
            ['title' => $newTitle, 'description' => $newDescription, 'source' => 'autopilot']
        );

        $action->payload = $payload;
    }

    public function revert(SeoAction $action): void
    {
        $url = (string) $action->target_url;
        if ($url === '') {
            return;
        }

        $path = SeoPathOverride::normalizePath($url);
        $payload = $action->payload ?? [];

        // If nothing existed before we applied, remove the row entirely.
        // Delete the instance (not a query-builder delete) so the model's
        // deleted() event fires and busts the render cache.
        if (empty($payload['prev_existed'])) {
            SeoPathOverride::where('path', $path)->first()?->delete();

            return;
        }

        SeoPathOverride::updateOrCreate(
            ['path' => $path],
            ['title' => $payload['prev_title'] ?? null, 'description' => $payload['prev_description'] ?? null]
        );
    }

    private function clean(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }
}
