<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClarityDailyMetric extends Model
{
    protected $table = 'clarity_daily_metrics';

    protected $fillable = [
        'date',
        'project_id',
        'sessions',
        'users',
        'pageviews',
        'scroll_depth',
        'active_time_seconds',
        'bounce_rate',
        'dead_clicks',
        'rage_clicks',
        'quickbacks',
    ];

    protected $casts = [
        'date' => 'date',
        'sessions' => 'integer',
        'users' => 'integer',
        'pageviews' => 'integer',
        'scroll_depth' => 'float',
        'active_time_seconds' => 'integer',
        'bounce_rate' => 'float',
        'dead_clicks' => 'integer',
        'rage_clicks' => 'integer',
        'quickbacks' => 'integer',
    ];
}
