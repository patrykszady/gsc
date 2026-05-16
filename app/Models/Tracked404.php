<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tracked404 extends Model
{
    protected $table = 'tracked_404s';

    protected $fillable = [
        'path',
        'referer',
        'user_agent',
        'hit_count',
        'first_seen_at',
        'last_seen_at',
        'indexnow_submitted_at',
    ];

    protected $casts = [
        'hit_count' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'indexnow_submitted_at' => 'datetime',
    ];
}
