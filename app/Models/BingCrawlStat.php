<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * One day's Bing Webmaster Tools crawl stats (GetCrawlStats) for one
 * site_url — pages crawled, pages in Bing's index, errors and status
 * codes. Filled by App\Support\Seo\BingCoverageWriter, keyed on
 * date + site_url (see its upsertCrawlStat()). BelongsToSite like every
 * other kit table here (App\Models\BingTrafficStat, GscCoverageState).
 */
class BingCrawlStat extends Model
{
    use BelongsToSite;

    protected $table = 'bing_crawl_stats';

    protected $fillable = [
        'date',
        'site_url',
        'crawled_pages',
        'in_index',
        'in_links',
        'crawl_errors',
        'blocked_by_robots',
        'code_2xx',
        'code_301',
        'code_302',
        'code_4xx',
        'code_5xx',
        'other_codes',
        'connection_timeouts',
        'dns_failures',
    ];

    protected $casts = [
        'date' => 'date',
        'crawled_pages' => 'integer',
        'in_index' => 'integer',
        'in_links' => 'integer',
        'crawl_errors' => 'integer',
        'blocked_by_robots' => 'integer',
        'code_2xx' => 'integer',
        'code_301' => 'integer',
        'code_302' => 'integer',
        'code_4xx' => 'integer',
        'code_5xx' => 'integer',
        'other_codes' => 'integer',
        'connection_timeouts' => 'integer',
        'dns_failures' => 'integer',
    ];
}
