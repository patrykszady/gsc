<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * One SEO data source's heartbeat for one tenant — when its collector last
 * ran, when it last succeeded and how the last run ended — as the kit's
 * SsSystems\Platform\Seo\Status\Heartbeat hands it over (kit 0.28.0,
 * 2026-11-12). Written and read by App\Support\Seo\TenantHeartbeatStore
 * only; `GET seo/status` is that table read against the kit's registry.
 *
 * BelongsToSite like every other kit table here (App\Models\BingUrlInfo,
 * GscCoverageState). The one row with a NULL site_id is the scheduler's
 * beat, which by the scope's own rule belongs to every site.
 *
 * @property int $id
 * @property int|null $site_id
 * @property string $sync_key
 * @property array<string, mixed>|null $summary
 */
class SeoSyncRun extends Model
{
    use BelongsToSite;

    protected $table = 'seo_sync_runs';

    protected $fillable = [
        'sync_key',
        'summary',
    ];

    protected $casts = [
        'summary' => 'array',
    ];
}
