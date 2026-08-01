<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

class BingTrafficStat extends Model
{
    use BelongsToSite;

    protected $table = 'bing_traffic_stats';

    protected $fillable = ['date', 'site_url', 'query', 'impressions', 'clicks', 'position', 'dim_hash'];

    protected $casts = [
        'date' => 'date',
        'impressions' => 'integer',
        'clicks' => 'integer',
        'position' => 'float',
    ];
}
