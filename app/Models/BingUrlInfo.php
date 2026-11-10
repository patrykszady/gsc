<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * What Bing knows of one sitemap URL — when it found and last fetched it
 * (GetUrlInfo), whether it showed the page in results in the last 28 days
 * (GetPageStats), keyed on url_hash. Filled by App\Support\Seo\
 * BingCoverageWriter; a URL that left the sitemap keeps its row with
 * in_sitemap = false rather than being deleted. BelongsToSite like every
 * other kit table here (App\Models\BingTrafficStat, GscCoverageState).
 */
class BingUrlInfo extends Model
{
    use BelongsToSite;

    protected $table = 'bing_url_info';

    protected $fillable = [
        'url',
        'url_hash',
        'in_sitemap',
        'http_status',
        'is_page',
        'document_size',
        'anchor_count',
        'child_url_count',
        'discovered_on',
        'last_crawled_at',
        'impressions_28d',
        'clicks_28d',
        'last_shown_on',
        'inspected_at',
        'inspect_error',
    ];

    protected $casts = [
        'in_sitemap' => 'boolean',
        'http_status' => 'integer',
        'is_page' => 'boolean',
        'document_size' => 'integer',
        'anchor_count' => 'integer',
        'child_url_count' => 'integer',
        'discovered_on' => 'date',
        'last_crawled_at' => 'datetime',
        'impressions_28d' => 'integer',
        'clicks_28d' => 'integer',
        'last_shown_on' => 'date',
        'inspected_at' => 'datetime',
    ];
}
