<?php

namespace App\Support\SEO;

use Illuminate\Database\Eloquent\Model;
use RalphJSmit\Laravel\SEO\SchemaCollection;
use RalphJSmit\Laravel\SEO\Support\SEOData;

/**
 * Per-request mutable SEO state. Build up tags during page handlers, then
 * render once from the layout via `seo(app(SEOBuilder::class)->build())`.
 *
 * Bound as a singleton in AppServiceProvider so all writes during a request
 * accumulate into a single SEOData.
 */
class SEOBuilder
{
    protected ?Model $model = null;
    protected ?string $title = null;
    protected ?string $description = null;
    protected ?string $url = null;
    protected ?string $canonical = null;
    protected ?string $image = null;
    protected ?string $author = null;
    protected ?string $robots = null;
    protected ?string $type = null;
    protected ?string $section = null;
    protected ?string $locale = null;
    protected ?string $siteName = null;
    protected ?\Carbon\CarbonInterface $publishedTime = null;
    protected ?\Carbon\CarbonInterface $modifiedTime = null;
    /** @var array<int,string> */
    protected array $tags = [];
    /** @var array<int,string> */
    protected array $keywords = [];
    protected ?SchemaCollection $schema = null;

    public function for(Model $model): self
    {
        $this->model = $model;
        return $this;
    }

    public function title(?string $v): self        { $this->title = $v ?: $this->title; return $this; }
    public function description(?string $v): self  { $this->description = $v ?: $this->description; return $this; }
    public function url(?string $v): self          { $this->url = $v ?: $this->url; return $this; }
    public function canonical(?string $v): self    { $this->canonical = $v ?: $this->canonical; return $this; }
    public function image(?string $v): self        { $this->image = $v ?: $this->image; return $this; }
    public function author(?string $v): self       { $this->author = $v ?: $this->author; return $this; }
    public function robots(?string $v): self       { $this->robots = $v ?: $this->robots; return $this; }
    public function type(?string $v): self         { $this->type = $v ?: $this->type; return $this; }
    public function section(?string $v): self      { $this->section = $v ?: $this->section; return $this; }
    public function locale(?string $v): self       { $this->locale = $v ?: $this->locale; return $this; }
    public function siteName(?string $v): self     { $this->siteName = $v ?: $this->siteName; return $this; }
    public function publishedTime(?\Carbon\CarbonInterface $v): self { $this->publishedTime = $v ?: $this->publishedTime; return $this; }
    public function modifiedTime(?\Carbon\CarbonInterface $v): self  { $this->modifiedTime = $v ?: $this->modifiedTime; return $this; }
    public function schema(?SchemaCollection $v): self { $this->schema = $v ?: $this->schema; return $this; }

    /** @param array<int,string>|string $tags */
    public function tags(array|string $tags): self
    {
        foreach ((array) $tags as $t) {
            $t = (string) $t;
            if ($t !== '' && ! in_array($t, $this->tags, true)) $this->tags[] = $t;
        }
        return $this;
    }

    /** @param array<int,string>|string $kw */
    public function keywords(array|string $kw): self
    {
        foreach ((array) $kw as $k) {
            $k = (string) $k;
            if ($k !== '' && ! in_array($k, $this->keywords, true)) $this->keywords[] = $k;
        }
        return $this;
    }

    public function markNoindex(): self
    {
        $this->robots = 'noindex,follow';
        return $this;
    }

    public function noindex(): self
    {
        return $this->markNoindex();
    }

    /**
     * Build the final SEOData. If a model was set via for(), its DB row
     * (merged with getDynamicSEOData()) is the base; explicit builder
     * fields then override.
     */
    public function build(): SEOData
    {
        $base = null;
        if ($this->model) {
            // Trigger seo()->withDefault() so we always get an SEO instance, then prepareForUsage.
            $row = $this->model->seo;
            if ($row && method_exists($row, 'prepareForUsage')) {
                $base = $row->prepareForUsage();
            } elseif (method_exists($this->model, 'getDynamicSEOData')) {
                $base = $this->model->getDynamicSEOData();
            }
        }

        $data = new SEOData(
            title:             $this->title          ?? $base?->title,
            description:       $this->description    ?? $base?->description,
            author:            $this->author         ?? $base?->author,
            image:             $this->image          ?? $base?->image,
            url:               $this->url            ?? $base?->url,
            enableTitleSuffix: $base?->enableTitleSuffix ?? true,
            published_time:    $this->publishedTime  ?? $base?->published_time,
            modified_time:     $this->modifiedTime   ?? $base?->modified_time,
            articleBody:       $base?->articleBody,
            section:           $this->section        ?? $base?->section,
            tags:              ! empty($this->tags) ? $this->tags : ($base?->tags ?? null),
            schema:            $this->schema         ?? $base?->schema,
            type:              $this->type           ?? $base?->type ?? 'website',
            site_name:         $this->siteName       ?? $base?->site_name ?? config('seo.site_name'),
            locale:            $this->locale        ?? $base?->locale,
            robots:            $this->robots        ?? $base?->robots,
            canonical_url:     $this->canonical     ?? $base?->canonical_url,
        );

        return $data;
    }

    /** @return array<int,string> */
    public function keywordList(): array
    {
        return $this->keywords;
    }
}
