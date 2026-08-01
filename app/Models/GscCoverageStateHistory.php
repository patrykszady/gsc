<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

class GscCoverageStateHistory extends Model
{
    use BelongsToSite;

    protected $table = 'gsc_coverage_state_history';

    protected $fillable = [
        'url',
        'verdict',
        'coverage_state',
        'page_fetch_state',
        'observed_at',
    ];

    protected $casts = [
        'observed_at' => 'datetime',
    ];
}
