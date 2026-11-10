<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use App\Services\Seo\LandingPageContentGenerator;
use App\Services\Seo\TitleMetaGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A demand-driven programmatic landing page (/remodeling/{slug}).
 *
 * Two templates share the table:
 *   service_modifier_city — a service in one town (Autopilot / the hub's
 *                           "Generate draft"), proof = matched local projects;
 *   guide                 — a long-form answer to a search that is not a town
 *                           search ("bathroom remodels for small bathrooms"),
 *                           written by `content:build-guides` from the site's
 *                           own facts; no city; proof = published projects of
 *                           its service, any town.
 *
 * @property string $slug
 * @property string $template
 * @property string|null $service
 * @property string|null $city
 * @property string $title
 * @property string $h1
 * @property array|null $sections
 * @property array|null $faq
 * @property array|null $proof_project_ids
 * @property string $status
 * @property bool $indexed
 */
class LandingPage extends Model
{
    use BelongsToSite;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const TEMPLATE_SERVICE_MODIFIER_CITY = 'service_modifier_city';

    public const TEMPLATE_GUIDE = 'guide';

    /** How many proof projects a guide shows — the city template's own cap. */
    public const GUIDE_PROOF_LIMIT = 6;

    protected $guarded = [];

    protected $casts = [
        'sections' => 'array',
        'faq' => 'array',
        'proof_project_ids' => 'array',
        'indexed' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function scopePublished(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_PUBLISHED);
    }

    public function scopeGuides(Builder $q): Builder
    {
        return $q->where('template', self::TEMPLATE_GUIDE);
    }

    public function isGuide(): bool
    {
        return $this->template === self::TEMPLATE_GUIDE;
    }

    /** Only published pages that clear the proof gate should be indexed. */
    public function shouldIndex(): bool
    {
        return $this->status === self::STATUS_PUBLISHED
            && $this->indexed
            && $this->hasProof();
    }

    public function hasProof(): bool
    {
        return ! empty($this->proof_project_ids);
    }

    public function url(): string
    {
        return url('/remodeling/'.$this->slug);
    }

    /** The service page's label — "Bathroom Remodeling", "Exterior & Siding". */
    public function serviceLabel(): string
    {
        if (! $this->service) {
            return 'Remodeling';
        }

        return TitleMetaGenerator::SERVICES[$this->service]
            ?? (string) Str::of($this->service)->replace('-', ' ')->title();
    }

    public function servicePageUrl(): ?string
    {
        return $this->service ? url('/services/'.$this->service) : null;
    }

    /** The project type this page's service draws proof from (null for a service with no type). */
    public function proofProjectType(): ?string
    {
        return LandingPageContentGenerator::SERVICE_PROJECT_TYPE[$this->service] ?? null;
    }

    /**
     * The published /costs guide for this page's service, if the site has one.
     *
     * @return array<string,mixed>|null
     */
    public function costGuide(): ?array
    {
        if (! $this->service) {
            return null;
        }

        $guide = collect((array) config('remodel-costs.guides', []))->firstWhere('service', $this->service);

        return is_array($guide) && config('remodel-costs.enabled', true) ? $guide : null;
    }

    /** Real projects backing this page (the unique, non-thin content). */
    public function proofProjects(): Collection
    {
        if ($this->isGuide()) {
            return $this->guideProofProjects();
        }

        $ids = $this->proof_project_ids ?: [];
        if (empty($ids)) {
            return new Collection;
        }

        return Project::whereIn('id', $ids)
            ->where('is_published', true)
            // presentFor() (livewire/landing-page-show.blade.php) needs the
            // area list to decide whether a row is listed via one of its
            // areas — see Project::presentFor().
            ->with('areas.images')
            ->orderByRaw('FIELD(id, '.implode(',', array_map('intval', $ids)).')')
            ->get();
    }

    /**
     * A guide's proof is the published work of its service, any town: the
     * projects the build chose (proof_project_ids, in that order) first, then
     * — when fewer than GUIDE_PROOF_LIMIT of those are still published — the
     * service's other published projects, featured first. A page therefore
     * never shows an unpublished project and never goes empty because one
     * project was taken down.
     */
    protected function guideProofProjects(): Collection
    {
        $ids = array_values(array_filter(array_map('intval', $this->proof_project_ids ?: [])));

        $chosen = $ids === []
            ? new Collection
            : Project::whereIn('id', $ids)->where('is_published', true)->with('areas.images')->get()
                ->sortBy(fn (Project $project) => array_search($project->id, $ids, true))
                ->values();

        $missing = self::GUIDE_PROOF_LIMIT - $chosen->count();
        $type = $this->proofProjectType();

        if ($missing <= 0 || $type === null) {
            return $chosen->take(self::GUIDE_PROOF_LIMIT)->values();
        }

        $more = Project::where('is_published', true)
            ->ofType($type)
            ->whereNotIn('id', $chosen->pluck('id')->all() ?: [0])
            ->with('areas.images')
            ->orderByDesc('is_featured')
            ->orderByDesc('completed_at')
            ->limit($missing)
            ->get();

        return $chosen->concat($more)->values();
    }

    /**
     * The sections as the guide view renders them: each body split into
     * paragraphs, with a run of "- " lines becoming one bullet list.
     *
     * @return list<array{heading:string, blocks:list<array{type:'p'|'ul', text?:string, items?:list<string>}>}>
     */
    public function sectionBlocks(): array
    {
        $out = [];
        foreach ((array) $this->sections as $section) {
            $blocks = [];
            foreach (preg_split('/\n\s*\n+/', trim((string) ($section['body'] ?? ''))) ?: [] as $chunk) {
                $chunk = trim($chunk);
                if ($chunk === '') {
                    continue;
                }
                $lines = array_map('trim', preg_split('/\n/', $chunk) ?: []);
                $isList = $lines !== [] && count(array_filter($lines, fn (string $line) => ! str_starts_with($line, '- '))) === 0;
                $blocks[] = $isList
                    ? ['type' => 'ul', 'items' => array_map(fn (string $line) => trim(substr($line, 2)), $lines)]
                    : ['type' => 'p', 'text' => preg_replace('/\s*\n\s*/', ' ', $chunk)];
            }
            $out[] = ['heading' => (string) ($section['heading'] ?? ''), 'blocks' => $blocks];
        }

        return $out;
    }

    /** Published guides for the strips on the blog and the cost pages (all of them when $service is null). */
    public static function publishedGuides(?string $service = null): Collection
    {
        return static::published()->guides()
            ->when($service !== null, fn (Builder $q) => $q->where('service', $service))
            ->orderBy('title')
            ->get();
    }

    /** The service slug a project type belongs to — the reverse of LandingPageContentGenerator::SERVICE_PROJECT_TYPE (first match wins). */
    public static function serviceForProjectType(?string $projectType): ?string
    {
        if ($projectType === null) {
            return null;
        }

        $service = array_search($projectType, LandingPageContentGenerator::SERVICE_PROJECT_TYPE, true);

        return $service === false ? null : $service;
    }

    /** Management-API shape — see Project::toApiArray(). */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'h1' => $this->h1,
            'status' => $this->status,
            'source' => $this->source,
            'indexed' => (bool) $this->indexed,
            'has_proof' => $this->hasProof(),
            'should_index' => $this->shouldIndex(),
            'proof_count' => count($this->proof_project_ids ?? []),
            'url' => '/remodeling/'.$this->slug,
            'published_at' => optional($this->published_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
