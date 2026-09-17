<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The copy one service page carries in one town: what a kitchen remodel in
 * Western Springs is like, not what Western Springs is like. Generated once
 * per (town, service) by seo:generate-area-service-content.
 */
class AreaServiceContent extends Model
{
    /** The service spokes an area has, by URL slug. */
    public const SERVICES = ['kitchen-remodeling', 'bathroom-remodeling', 'home-remodeling', 'basement-remodeling', 'home-additions'];

    protected $guarded = [];

    protected $casts = [
        'faq' => 'array',
        'generated_at' => 'datetime',
    ];

    public function area(): BelongsTo
    {
        return $this->belongsTo(AreaServed::class, 'area_served_id');
    }

    /** @return list<array{question: string, answer: string}> */
    public function faqItems(): array
    {
        return AreaServed::normaliseFaq($this->faq);
    }

    public static function label(string $service): string
    {
        return match ($service) {
            'kitchen-remodeling' => 'kitchen remodeling',
            'bathroom-remodeling' => 'bathroom remodeling',
            'home-remodeling' => 'whole-home remodeling',
            'basement-remodeling' => 'basement finishing',
            'home-additions' => 'home additions',
            default => str_replace('-', ' ', $service),
        };
    }
}
