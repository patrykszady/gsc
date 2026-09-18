<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-site automatic-posting settings for one platform (instagram, facebook,
 * google_business). Replaces the hard-coded Schedule blocks that used to
 * live in routes/console.php — see App\Console\Commands\SocialAutomationTick
 * and App\Services\Social\AutomationPlanner, which compute and dispatch each
 * site's own deterministic weekly plan from these rows.
 *
 * A missing row (no site has ever saved this platform's card) means
 * automation is OFF for that platform on that site — defaultsFor() supplies
 * realistic cadence/options for display, but callers must treat the row's
 * absence as enabled=false rather than inserting a row on read.
 */
class SocialAutomationSetting extends Model
{
    use BelongsToSite;

    protected $table = 'social_automation_settings';

    protected $fillable = [
        'site_id',
        'platform',
        'enabled',
        'cadence',
        'options',
        'last_dispatched_slot',
        'last_dispatched_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'cadence' => 'array',
        'options' => 'array',
        'last_dispatched_at' => 'datetime',
    ];

    /** Fixed order the API always reports items in. */
    public const PLATFORMS = ['instagram', 'facebook', 'google_business'];

    public const LABELS = [
        'instagram' => 'Instagram posts',
        'facebook' => 'Facebook posts',
        'google_business' => 'Google Business posts',
    ];

    /**
     * Today's hard-coded cadence (routes/console.php, before this settings
     * table existed), reproduced here so:
     *   - the migration can seed gs.construction's rows to match it exactly
     *   - a site with no row yet still reports a realistic (disabled) plan
     *     instead of an arbitrary one
     */
    public const DEFAULTS = [
        'instagram' => [
            'cadence' => ['per_week' => 2, 'days' => null, 'window' => ['start' => '10:00', 'end' => '19:00']],
            'options' => ['location_tag' => true],
        ],
        'facebook' => [
            'cadence' => ['per_week' => 2, 'days' => null, 'window' => ['start' => '09:00', 'end' => '19:00']],
            'options' => [],
        ],
        'google_business' => [
            'cadence' => ['per_week' => 2, 'days' => null, 'window' => ['start' => '09:30', 'end' => '13:30']],
            'options' => ['themed' => true, 'catch_up_after_days' => 6],
        ],
    ];

    public static function defaultsFor(string $platform): array
    {
        return static::DEFAULTS[$platform] ?? [
            'cadence' => ['per_week' => 1, 'days' => null, 'window' => ['start' => '09:00', 'end' => '17:00']],
            'options' => [],
        ];
    }
}
