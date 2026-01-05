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
}
