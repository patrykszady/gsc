<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A single SEO Autopilot recommendation and its full lifecycle: proposed →
 * applied → measured (worked/no_effect/regressed) → optionally reverted.
 *
 * @property string $fingerprint
 * @property string $source
 * @property string $category
 * @property string $risk
 * @property string $title
 * @property array|null $payload
 * @property string $status
 * @property string|null $outcome
 */
class SeoAction extends Model
{
    // Risk tiers gate auto-apply eligibility.
    public const RISK_SAFE = 'safe';
    public const RISK_REVIEW = 'review';
    public const RISK_MANUAL = 'manual';

    public const STATUS_PROPOSED = 'proposed';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_REVERTED = 'reverted';
    public const STATUS_FAILED = 'failed';

    public const OUTCOME_PENDING = 'pending';
    public const OUTCOME_WORKED = 'worked';
    public const OUTCOME_NO_EFFECT = 'no_effect';
    public const OUTCOME_REGRESSED = 'regressed';
    public const OUTCOME_INCONCLUSIVE = 'inconclusive';

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'auto_applied' => 'boolean',
        'priority' => 'float',
        'impact_score' => 'float',
        'confidence' => 'float',
        'ease' => 'float',
        'baseline_value' => 'float',
        'measured_value' => 'float',
        'delta_pct' => 'float',
        'applied_at' => 'datetime',
        'reverted_at' => 'datetime',
        'baseline_at' => 'datetime',
        'measure_after' => 'datetime',
        'measured_at' => 'datetime',
    ];

    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_PROPOSED);
    }

    public function scopeApplied(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_APPLIED);
    }

    /** Applied actions whose measurement window has elapsed and still pending an outcome. */
    public function scopeDueForMeasurement(Builder $q): Builder
    {
        return $q->where('status', self::STATUS_APPLIED)
            ->whereNotNull('measure_after')
            ->where('measure_after', '<=', now())
            ->where(function (Builder $q) {
                $q->whereNull('outcome')->orWhere('outcome', self::OUTCOME_PENDING);
            });
    }

    public function isRevertible(): bool
    {
        return $this->status === self::STATUS_APPLIED && ! empty($this->payload);
    }
}
