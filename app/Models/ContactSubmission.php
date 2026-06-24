<?php

namespace App\Models;

use App\Jobs\SendLeadToHive;
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
        'status',
        'spam_reason',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'hive_sent_at',
        'hive_lead_id',
        'hive_send_error',
    ];

    protected $casts = [
        'availability' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'hive_sent_at' => 'datetime',
    ];

    /**
     * A submission is spam only while explicitly flagged as such.
     * Everything else (pending / legitimate) is a real lead.
     */
    public function isSpam(): bool
    {
        return $this->status === 'spam';
    }

    public function wasSentToHive(): bool
    {
        return $this->hive_sent_at !== null && $this->hive_lead_id !== null;
    }

    /**
     * Convert a (possibly spam-flagged) submission into a real lead and
     * forward it to the Hive dashboard. Idempotent: the Hive job no-ops if
     * the lead was already accepted.
     */
    public function markAsReal(): void
    {
        $this->update([
            'status' => 'legitimate',
            'spam_reason' => null,
        ]);

        // Learn going forward: trust this sender so similar messages aren't
        // flagged again (and clear any opposing block rule).
        LeadFilterRule::learnAllow($this);

        SendLeadToHive::dispatch($this->id)->afterCommit();
    }

    public function markAsSpam(): void
    {
        $this->update([
            'status' => 'spam',
            'spam_reason' => $this->spam_reason ?: 'manual',
        ]);

        // Learn going forward: block this sender (and clear any opposing allow rule).
        LeadFilterRule::learnDeny($this);
    }
}
