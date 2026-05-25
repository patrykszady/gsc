<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HiveProjectZipCount extends Model
{
    protected $table = 'hive_project_zip_counts';
    public $timestamps = false;

    protected $fillable = ['zip', 'city', 'state', 'count', 'synced_at'];

    protected $casts = [
        'count' => 'integer',
        'synced_at' => 'datetime',
    ];
}
