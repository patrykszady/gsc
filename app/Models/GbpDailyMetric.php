<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GbpDailyMetric extends Model
{
    protected $table = 'gbp_daily_metrics';

    protected $fillable = ['date', 'location_id', 'metric', 'value'];

    protected $casts = [
        'date' => 'date',
        'value' => 'integer',
    ];
}
