<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class BlogPost extends Model
{
    use BelongsToSite;
    use \Hszope\LaravelAigeo\Traits\HasGeoProfile;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'project_id', 'slug', 'title', 'excerpt', 'body', 'meta_title', 'meta_description',
        'status', 'writer', 'published_at', 'dated_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'dated_at' => 'date',
    ];

    protected static function booted(): void
    {
        static::creating(function (BlogPost $post): void {
            if (empty($post->slug)) {
                $post->slug = static::uniqueSlug($post->title);
            }
        });
    }

    /**
     * The date a story is shown with: the month the project was completed,
     * on a day picked deterministically from the project id — so every
     * regeneration keeps the same date. Never in the future.
     */
    public static function dateFor(Project $project): \Illuminate\Support\Carbon
    {
        $anchor = $project->completed_at ?? $project->created_at ?? now();
        $month = $anchor->copy()->startOfMonth();

        mt_srand((int) $project->id * 7919 + 17);
        $day = mt_rand(1, $month->daysInMonth);
        mt_srand();

        $date = $month->copy()->day($day)->startOfDay();

        return $date->isFuture() ? now()->startOfDay() : $date;
    }

    /**
     * A signed link that shows the post even while unpublished. Seven days,
     * then it 404s like any other draft. This is the only way to see a draft.
     */
    public function previewUrl(): string
    {
        return \Illuminate\Support\Facades\URL::temporarySignedRoute('blog.show', now()->addDays(7), ['post' => $this->slug, 'preview' => 1]);
    }

    /**
     * GEO profile for laravel-aigeo (scored on the /geo dashboard like
     * projects and reviews). A story is described as the project it tells,
     * with the review that backs it.
     */
    public function geoProfile(): array
    {
        $project = $this->project;
        $type = $project ? (Project::projectTypes()[$project->project_type] ?? 'Remodel') : 'Remodel';
        $loc = $project?->location ?: 'Chicago Suburbs';
        $review = $project?->testimonials->where('is_hidden', false)->sortByDesc('review_date')->first();

        return [
            'brand' => config('brand.name'),
            'name' => $this->title,
            'description' => $this->excerpt ?: $this->meta_description,
            'url' => $this->url(),
            'image' => $project?->cover()?->url,
            'sku' => 'story-' . $this->id,
            'price' => 'Contact for quote',
            'currency' => 'USD',
            'in_stock' => true,
            'rating' => $review?->star_rating ?? 5,
            'review_count' => max(1, (int) $project?->testimonials->count()),
            'reviews' => $review ? [[
                'author' => $review->display_name,
                'rating' => $review->star_rating ?? 5,
                'body' => $review->review_description,
                'date' => optional($review->review_date)->toDateString(),
            ]] : [],
            'breadcrumb' => [
                ['name' => 'Home', 'url' => url('/')],
                ['name' => 'Blog', 'url' => route('blog.index')],
                ['name' => $this->title, 'url' => $this->url()],
            ],
            'faqs' => [
                ['question' => "Where was this {$type} project?", 'answer' => "In {$loc}, by " . config('brand.name') . '.'],
                ['question' => 'Who did the work?', 'answer' => config('brand.name') . ' handled the consultation, estimate, permits, scheduling and construction' . ($project && $project->collaborators->isNotEmpty() ? ', working with ' . $project->collaborators->map(fn ($c) => $c->name . ' (' . strtolower($c->roleLabel()) . ')')->implode(', ') . '.' : '.')],
            ],
            'attributes' => array_filter([
                'Project Type' => $type,
                'Location' => $loc,
                'Completed' => $project?->completed_at?->format('F Y'),
                'Published' => $this->displayDate()?->format('F j, Y'),
            ]),
        ];
    }

    /**
     * The body as HTML for the admin's rich-text editor. Plain CommonMark —
     * media shortcodes stay as their own paragraphs ("[cover]") so the
     * editor shows them and the round trip keeps them.
     */
    public function bodyHtml(): string
    {
        $converter = new \League\CommonMark\GithubFlavoredMarkdownConverter(['html_input' => 'strip', 'allow_unsafe_links' => false]);

        return (string) $converter->convert((string) $this->body);
    }

    /**
     * The inverse: HTML from the editor back to the Markdown we store.
     * Headings as "##", emphasis as * and **, shortcode paragraphs back to
     * bare "[cover]" lines.
     */
    public static function markdownFromHtml(string $html): string
    {
        $converter = new \League\HTMLToMarkdown\HtmlConverter([
            'header_style' => 'atx',
            'strip_tags' => true,
            'hard_break' => false,
            'remove_nodes' => 'script style',
        ]);
        $markdown = $converter->convert($html);
        // The editor may escape the brackets of a shortcode paragraph.
        $markdown = preg_replace('/^[ \t]*\\\\?\[(before|cover|before-after|timelapse|gallery)\\\\?\][ \t]*$/m', '[$1]', $markdown) ?? $markdown;
        return trim(preg_replace("/\n{3,}/", "\n\n", $markdown) ?? $markdown);
    }

    /** The date shown on the post and the index. */
    public function displayDate(): ?\Illuminate\Support\Carbon
    {
        return $this->dated_at ?? $this->published_at ?? $this->created_at;
    }

    public static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'post';
        $slug = $base;
        $n = 1;
        while (static::query()->where('slug', $slug)->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))->exists()) {
            $slug = $base . '-' . ++$n;
        }

        return $slug;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function scopePublished(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_PUBLISHED)->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED && $this->published_at && $this->published_at->lte(now());
    }

    public function url(): string
    {
        return url('/blog/' . $this->slug);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'project_title' => $this->project?->title,
            'slug' => $this->slug,
            'title' => $this->title,
            'excerpt' => $this->excerpt,
            'body' => $this->body,
            // For the admin's rich-text editor; Markdown stays the stored form.
            'body_html' => $this->bodyHtml(),
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'status' => $this->status,
            'writer' => $this->writer,
            'published_at' => $this->published_at?->toIso8601String(),
            'dated_at' => $this->dated_at?->toDateString(),
            'url' => $this->url(),
            'preview_url' => $this->previewUrl(),
            'cover_url' => $this->project?->cover()?->getWebpThumbnailUrl('medium'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
