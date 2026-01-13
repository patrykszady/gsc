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
        return route('areas.show', $this->slug);
    }

    /**
     * Get URL for a specific page within this area.
     */
    public function pageUrl(string $page): string
    {
        return route('areas.page', ['area' => $this->slug, 'page' => $page]);
    }

    /**
     * Get URL for a specific service page within this area.
     * e.g., /areas-served/arlington-heights/services/kitchens
     */
    public function serviceUrl(string $service): string
    {
        return route('areas.service', ['area' => $this->slug, 'service' => $service]);
    }
}
