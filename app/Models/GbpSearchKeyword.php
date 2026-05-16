<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GbpSearchKeyword extends Model
{
    protected $table = 'gbp_search_keywords';

    protected $fillable = ['location_id', 'keyword', 'year', 'month', 'impressions'];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'impressions' => 'integer',
    ];
}
