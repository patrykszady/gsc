<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

class ClientError extends Model
{
    use BelongsToSite;

    protected $fillable = [
        'fingerprint',
        'kind',
        'message',
        'source',
        'line',
        'column',
        'stack',
        'page_path',
        'user_agent',
        'occurrences',
        'first_seen_at',
        'last_seen_at',
        'resolved_at',
    ];

    protected $casts = [
        'line' => 'integer',
        'column' => 'integer',
        'occurrences' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function getIsResolvedAttribute(): bool
    {
        return $this->resolved_at !== null;
    }

    /** Management-API shape — see Project::toApiArray(). */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'message' => $this->message,
            'source' => $this->source,
            'line' => $this->line,
            'column' => $this->column,
            'stack' => $this->stack,
            'page_path' => $this->page_path,
            'user_agent' => $this->user_agent,
            'occurrences' => (int) $this->occurrences,
            'is_resolved' => $this->is_resolved,
            'first_seen_at' => optional($this->first_seen_at)->toIso8601String(),
            'last_seen_at' => optional($this->last_seen_at)->toIso8601String(),
            'resolved_at' => optional($this->resolved_at)->toIso8601String(),
        ];
    }
}
