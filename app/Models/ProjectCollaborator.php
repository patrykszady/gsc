<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ProjectCollaborator extends Model
{
    protected $fillable = [
        'project_id', 'role', 'name', 'url', 'note', 'inferred_note', 'inferred_at',
        'site_title', 'site_description', 'site_excerpt', 'site_fetched_at', 'sort_order',
    ];

    protected $casts = [
        'site_fetched_at' => 'datetime',
        'inferred_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * Role vocabulary: the /trades disciplines (slug => singular label) plus
     * "other". One list feeds the admin form's select, API validation, and
     * the /trades/{slug} link on the post.
     *
     * @return array<string, string>
     */
    public static function roles(): array
    {
        $roles = [];
        foreach ((array) config('trades.trades', []) as $trade) {
            if (! empty($trade['slug'])) {
                $roles[$trade['slug']] = Str::singular($trade['name'] ?? $trade['short'] ?? Str::headline($trade['slug']));
            }
        }
        $roles['other'] = 'Other partner';

        return $roles;
    }

    public function roleLabel(): string
    {
        return static::roles()[$this->role] ?? Str::headline($this->role);
    }

    /** The /trades page for this role, when there is one. */
    public function tradeSlug(): ?string
    {
        return $this->role !== 'other' && array_key_exists($this->role, static::roles()) ? $this->role : null;
    }

    /**
     * What they did on the job: the admin's note when there is one, otherwise
     * what we estimated from their website against this project.
     */
    public function contribution(): ?string
    {
        return $this->note ?: $this->inferred_note;
    }

    /** Host of the partner's site, for display ("jpeterson-design.com"). */
    public function host(): ?string
    {
        $host = $this->url ? parse_url($this->url, PHP_URL_HOST) : null;

        return $host ? preg_replace('/^www\./', '', $host) : null;
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'role_label' => $this->roleLabel(),
            'name' => $this->name,
            'url' => $this->url,
            'note' => $this->note,
            'inferred_note' => $this->inferred_note,
            'site_title' => $this->site_title,
            'site_fetched_at' => $this->site_fetched_at?->toIso8601String(),
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
