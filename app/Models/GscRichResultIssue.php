<?php

namespace App\Models;

use App\Models\Concerns\BelongsToSite;
use Illuminate\Database\Eloquent\Model;

class GscRichResultIssue extends Model
{
    use BelongsToSite;

    protected $table = 'gsc_rich_result_issues';

    protected $fillable = [
        'url',
        'rich_result_type',
        'issue_severity',
        'issue_message',
        'issue_type',
        'verdict',
        'inspected_at',
    ];

    protected $casts = [
        'inspected_at' => 'datetime',
    ];
}
