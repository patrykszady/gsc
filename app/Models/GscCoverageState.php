<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GscCoverageState extends Model
{
    protected $table = 'gsc_coverage_states';

    protected $fillable = [
        'url',
        'verdict',
        'coverage_state',
        'robots_txt_state',
        'indexing_state',
        'page_fetch_state',
        'sitemap_url',
        'last_crawl_time',
        'user_canonical',
        'google_canonical',
        'inspected_at',
        'last_changed_at',
        'consecutive_failures',
    ];

    protected $casts = [
        'last_crawl_time' => 'datetime',
        'inspected_at' => 'datetime',
        'last_changed_at' => 'datetime',
        'consecutive_failures' => 'integer',
    ];

    public function isProblem(): bool
    {
        if (($this->verdict ?? '') !== 'PASS') return true;
        $state = strtolower((string) $this->coverage_state);
        return str_contains($state, 'forbidden')
            || str_contains($state, 'not indexed')
            || str_contains($state, 'soft 404')
            || str_contains($state, 'duplicate');
    }
}
