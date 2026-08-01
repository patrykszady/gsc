<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

class GscDailyTotal extends Model
{
    use BelongsToSite;

    protected $table = 'gsc_daily_totals';

    protected $fillable = [
        'date', 'site_url', 'clicks', 'impressions', 'ctr', 'position',
    ];

    protected $casts = [
        'date' => 'date',
        'clicks' => 'integer',
        'impressions' => 'integer',
        'ctr' => 'float',
        'position' => 'float',
    ];
}
