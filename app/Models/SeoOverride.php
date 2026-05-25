<?php

namespace App\Models;

use RalphJSmit\Laravel\SEO\Models\SEO as BaseSEO;
use RalphJSmit\Laravel\SEO\Support\SEOData;

/**
 * Custom SEO model that lets admin-entered DB columns OVERRIDE the model's
 * getDynamicSEOData() defaults. The vendor's default does the opposite
 * (dynamic wins), which would make the admin override panel a no-op.
 *
 * Precedence per field: DB column (when not null/blank) > dynamic > null.
 */
class SeoOverride extends BaseSEO
{
    public function prepareForUsage(): SEOData
    {
        $overrides = null;
        if ($this->model && method_exists($this->model, 'getDynamicSEOData')) {
            /** @var SEOData $overrides */
            $overrides = $this->model->getDynamicSEOData();
        }

        $enableTitleSuffix = true;
        if ($this->model) {
            if (method_exists($this->model, 'enableTitleSuffix')) {
                $enableTitleSuffix = $this->model->enableTitleSuffix();
            } elseif (property_exists($this->model, 'enableTitleSuffix')) {
                $enableTitleSuffix = $this->model->enableTitleSuffix;
            }
        }

        $attrs = $this->getAttributes();
        $pick = fn (string $col, $fallback) => $this->nonBlank($attrs[$col] ?? null) ?? $fallback;

        return new SEOData(
            title:             $pick('title',         $overrides?->title),
            description:       $pick('description',   $overrides?->description),
            author:            $pick('author',        $overrides?->author),
            image:             $pick('image',         $overrides?->image),
            url:               $overrides?->url,
            enableTitleSuffix: $enableTitleSuffix,
            published_time:    $overrides?->published_time ?? ($this->model?->created_at ?? null),
            modified_time:     $overrides?->modified_time  ?? ($this->model?->updated_at ?? null),
            articleBody:       $overrides?->articleBody,
            section:           $overrides?->section,
            tags:              $overrides?->tags,
            schema:            $overrides?->schema,
            type:              $overrides?->type ?? 'website',
            locale:            $overrides?->locale,
            robots:            $pick('robots',        $overrides?->robots),
            canonical_url:     $pick('canonical_url', $overrides?->canonical_url),
            openGraphTitle:    $overrides?->openGraphTitle,
            alternates:        $overrides?->alternates,
        );
    }

    private function nonBlank(mixed $v): ?string
    {
        if ($v === null) return null;
        $s = trim((string) $v);
        return $s === '' ? null : $s;
    }
}
