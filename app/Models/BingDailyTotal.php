<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BingDailyTotal extends Model
{
    protected $table = 'bing_daily_totals';

    protected $fillable = ['date', 'site_url', 'clicks', 'impressions', 'ctr'];

    protected $casts = [
        'date' => 'date',
        'clicks' => 'integer',
        'impressions' => 'integer',
        'ctr' => 'float',
    ];
}
