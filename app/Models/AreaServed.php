<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AreaServed extends Model
{
    protected $table = 'areas_served';

    protected $fillable = [
        'city',
        'slug',
    ];

    /**
     * Get the route key for the model.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Get the URL for this area's home page.
     */
    public function getUrlAttribute(): string
    {
        return route('area.home', $this);
    }
}
