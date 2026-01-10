<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactSubmission extends Model
{
    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'message',
        'availability',
        'city',
        'source',
        'referrer',
        'user_agent',
        'ip_address',
        'utm_source',
        'utm_medium',
        'utm_campaign',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
