<?php

namespace App\Models;

use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * A demand-driven programmatic landing page (/remodeling/{slug}).
 *
 * @property string $slug
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
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';

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
        return url('/remodeling/' . $this->slug);
    }

    /** Real projects backing this page (the unique, non-thin content). */
    public function proofProjects(): Collection
    {
        $ids = $this->proof_project_ids ?: [];
        if (empty($ids)) {
            return new Collection();
        }

        return Project::whereIn('id', $ids)
            ->where('is_published', true)
            ->orderByRaw('FIELD(id, ' . implode(',', array_map('intval', $ids)) . ')')
            ->get();
    }
}
