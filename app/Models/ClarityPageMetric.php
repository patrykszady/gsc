<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * One path, one day, from Microsoft Clarity's URL breakdown. See the
 * migration for why this exists beside ClarityDailyMetric.
 */
class ClarityPageMetric extends Model
{
    use BelongsToSite;

    protected $table = 'clarity_page_metrics';

    protected $fillable = [
        'date',
        'project_id',
        'path',
        'path_hash',
        'sessions',
        'rage_clicks',
        'dead_clicks',
        'quickbacks',
        'script_errors',
        'scroll_depth',
    ];

    protected $casts = [
        // Y-m-d, not the bare `date` cast: that one writes 'Y-m-d H:i:s' and
        // only MySQL's DATE column truncates it back, so on sqlite every
        // re-sync missed its own row and every window query lost its last
        // day. See GscDailyTotalLookupTest for the original discovery.
        'date' => 'date:Y-m-d',
        'sessions' => 'integer',
        'rage_clicks' => 'integer',
        'dead_clicks' => 'integer',
        'quickbacks' => 'integer',
        'script_errors' => 'integer',
        'scroll_depth' => 'float',
    ];

    public static function hashPath(string $path): string
    {
        return sha1($path);
    }
}
