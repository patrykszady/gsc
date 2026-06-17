<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClientError extends Model
{
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
}
