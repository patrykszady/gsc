<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

class GscQueryMetric extends Model
{
    use BelongsToSite;

    protected $table = 'gsc_query_metrics';

    protected $fillable = [
        'date', 'site_url', 'query', 'page', 'country', 'device',
        'impressions', 'clicks', 'ctr', 'position', 'dim_hash',
    ];

    protected $casts = [
        'date' => 'date',
        'impressions' => 'integer',
        'clicks' => 'integer',
        'ctr' => 'float',
        'position' => 'float',
    ];
}
