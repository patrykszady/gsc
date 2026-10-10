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
    /** Google's desktop SERP cuts titles near 600px, roughly 60 characters. */
    private const TITLE_MAX = 60;

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

    /**
     * Fallback setters: apply only when nothing set them programmatically.
     * Used by the layout for static views that pass title/metaDescription as
     * component props — a Livewire #[Title] or SeoService call always wins.
     */
    public function fallbackTitle(?string $v): self
    {
        if ($this->title === null && $v) {
            $this->title = $v;
        }
        return $this;
    }

    public function fallbackDescription(?string $v): self
    {
        if ($this->description === null && $v) {
            $this->description = $v;
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

        // Path-keyed override (Autopilot) wins over the programmatic builder
        // title — area pages set their title via SeoService without binding a
        // model, so this is the only override channel that reaches them.
        $pathOverride = \App\Models\SeoPathOverride::forPath(request()->path());

        // Titles never carry the state — "Palatine Kitchen Remodeling", not
        // "Palatine, IL Kitchen Remodeling". Applied at this choke point so
        // every source (builder, model, autopilot override) is covered.
        $title = ($pathOverride['title'] ?? null) ?: ($this->title ?? $base?->title);
        if (is_string($title)) {
            $title = trim(str_replace([', Illinois', ', IL'], '', $title));

            // Brand on EVERY tab. config('seo.title.suffix') is '' and the
            // package only appends that, so branding was whatever each page
            // happened to hardcode — 3 of 11 sampled pages carried it and
            // /areas-served/{city}/projects showed a bare "Remodeling Projects
            // in Palatine".
            //
            // Applied here rather than in config because this is the one choke
            // point every title source passes through (builder, Eloquent model,
            // Autopilot path override, and the layout's title= prop), and
            // because config/brand.php is overlaid per TENANT at runtime — a
            // literal in config/seo.php would brand every site as GS.
            //
            // Every source above budgets the PRE-brand text for a flat 60
            // (SeoService::normalizeMetaText(), TitleMetaGenerator being the
            // one exception that already reserves room), so appending " |
            // {brand}" unconditionally pushed the rendered <title> to 70-80+
            // chars on area, service and compare pages alike — the town and
            // service keywords a searcher is scanning for were fine; it was
            // always the brand suffix Google truncated. The keyword text is
            // never touched here: the brand is the first thing to give way,
            // and only the rare title that still overflows on its own (a
            // couple of hardcoded service titles) falls back to a word-safe
            // trim, never a mid-word cut.
            $brand = (string) config('brand.name');
            if ($brand !== '' && ! str_contains($title, $brand)) {
                $withBrand = $title . ' | ' . $brand;
                $title = match (true) {
                    mb_strlen($withBrand) <= self::TITLE_MAX => $withBrand,
                    mb_strlen($title) <= self::TITLE_MAX => $title,
                    default => self::truncateAtWord($title, self::TITLE_MAX),
                };
            } elseif (mb_strlen($title) > self::TITLE_MAX) {
                // Brand already embedded (e.g. trade()'s "{name} for Home
                // Remodels | GS Construction") or no brand configured: the
                // whole string is keyword content, so it gets the same
                // word-safe trim instead of overflowing untouched.
                $title = self::truncateAtWord($title, self::TITLE_MAX);
            }
        }

        $data = new SEOData(
            title:             $title,
            description:       ($pathOverride['description'] ?? null) ?: ($this->description ?? $base?->description),
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

    /**
     * Cut $text to the last whole word at or under $max.
     *
     * A title truncated mid-word ("...Remodeling Contra") reads as broken in
     * the SERP; one that simply ends a little early does not. Used only as
     * the fallback once dropping the brand suffix is not (or was never)
     * enough — the normal case never reaches this.
     */
    private static function truncateAtWord(string $text, int $max): string
    {
        $text = trim($text);
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max);
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut, " |\u{2013}\u{2014}\u{00B7},.;:-");
    }
}
