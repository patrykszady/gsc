<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HiveProjectZipCount extends Model
{
    protected $table = 'hive_project_zip_counts';
    public $timestamps = false;

    protected $fillable = ['zip', 'city', 'state', 'latitude', 'longitude', 'zip_latitude', 'zip_longitude', 'count', 'synced_at'];

    protected $casts = [
        'count' => 'integer',
        'latitude' => 'float',
        'longitude' => 'float',
        'zip_latitude' => 'float',
        'zip_longitude' => 'float',
        'synced_at' => 'datetime',
    ];
}
