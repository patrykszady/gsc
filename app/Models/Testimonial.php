<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Testimonial extends Model
{
    protected $fillable = [
        'reviewer_name',
        'project_location',
        'project_type',
        'review_description',
        'review_date',
        'review_url',
        'review_image',
    ];

    protected function casts(): array
    {
        return [
            'review_date' => 'date',
        ];
    }
}
