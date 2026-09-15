<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One row per inbox message looked at by EmailLeadIngest. */
class EmailLeadIngest extends Model
{
    public const STATUS_LEAD = 'lead';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'mailbox', 'grant_id', 'nylas_message_id', 'rfc_message_id', 'from_email', 'from_name', 'subject',
        'message_at', 'status', 'skip_reason', 'is_lead', 'confidence', 'submission_id', 'error',
    ];

    protected $casts = [
        'message_at' => 'datetime',
        'is_lead' => 'boolean',
        'confidence' => 'float',
    ];

    public function submission()
    {
        return $this->belongsTo(ContactSubmission::class, 'submission_id');
    }
}
