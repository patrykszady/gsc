<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

class GbpSearchKeyword extends Model
{
    use BelongsToSite;

    protected $table = 'gbp_search_keywords';

    protected $fillable = ['location_id', 'keyword', 'year', 'month', 'impressions'];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'impressions' => 'integer',
    ];
}
