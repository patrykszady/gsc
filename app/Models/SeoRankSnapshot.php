<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SeoRankSnapshot extends Model
{
    protected $table = 'seo_rank_snapshots';

    protected $fillable = [
        'engine',
        'query',
        'location',
        'city_slug',
        'gsc_position',
        'gsc_match_title',
        'result_count',
        'top_results',
        'meta',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'top_results' => 'array',
            'meta' => 'array',
            'fetched_at' => 'datetime',
        ];
    }

    public function scopeForQuery(Builder $q, string $engine, string $query, ?string $location): Builder
    {
        return $q->where('engine', $engine)
            ->where('query', $query)
            ->when($location, fn ($qq) => $qq->where('location', $location));
    }

    /**
     * Latest snapshot per (engine, query, location).
     */
    public static function latestForEach(): \Illuminate\Support\Collection
    {
        return self::query()
            ->whereIn('id', function ($sub) {
                $sub->selectRaw('MAX(id)')
                    ->from('seo_rank_snapshots')
                    ->groupBy('engine', 'query', 'location');
            })
            ->orderBy('engine')
            ->orderBy('query')
            ->get();
    }
}
