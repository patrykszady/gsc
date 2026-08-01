<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A viewport that has been imported into the gazetteer.
 *
 * Lets the map distinguish "no towns here" (a lake, farmland) from "this area
 * was never fetched" — without it, both look like an empty map and the second
 * silently never resolves.
 */
class TownImport extends Model
{
    protected $guarded = [];

    protected $casts = [
        'south' => 'float',
        'west' => 'float',
        'north' => 'float',
        'east' => 'float',
    ];

    /** Has any imported box fully covered this viewport? */
    public static function covers(float $south, float $west, float $north, float $east): bool
    {
        return static::query()
            ->where('south', '<=', $south)->where('west', '<=', $west)
            ->where('north', '>=', $north)->where('east', '>=', $east)
            ->exists();
    }
}
