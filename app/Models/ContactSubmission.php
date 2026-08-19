<?php

namespace App\Models;

use App\Jobs\SendLeadToHive;
use App\Jobs\SyncLeadFilterToPeer;
use App\Models\Concerns\BelongsToSite;
use App\Services\LeadAddressCompleter;
use Illuminate\Database\Eloquent\Model;

class ContactSubmission extends Model
{
    use BelongsToSite;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'message',
        'availability',
        'city',
        'state',
        'zip',
        'address_candidates',
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
        'address_candidates' => 'array',
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
     * The street on its own, with any city/state/zip tail the sender ran
     * into it stripped out — "2258 South 8th Avenue, North Riverside, IL
     * 60546" becomes "2258 South 8th Avenue", and a trailing "." left on the
     * street token by that split ("424 Broadview Ave.,  Highland Park") is
     * trimmed too. Uses LeadAddressCompleter::streetOnly()'s exact parsing
     * (split at the first comma, sender's casing preserved) so every
     * consumer of this field agrees with what capture-time completion
     * itself would have written into `address`. Null only when there's no
     * address at all; an address with nothing to strip is returned as-is.
     */
    public function street(): ?string
    {
        $address = trim((string) $this->address);

        if ($address === '') {
            return null;
        }

        $onlyStreet = LeadAddressCompleter::streetOnly($address);

        return $onlyStreet !== null ? rtrim($onlyStreet, '.') : $address;
    }

    /**
     * The best one-line address available, e.g. "511 Sherwood Dr, Addison,
     * IL 60101" — degrading gracefully to whatever parts are known (just a
     * city, just a street, or null when there's nothing at all). Fed by
     * LeadAddressCompleter's city/state/zip backfill, but works the same for
     * a lead that arrived complete on its own.
     */
    public function formattedAddress(): ?string
    {
        $cityStateZip = collect([
            $this->city,
            collect([$this->state, $this->zip])->filter()->implode(' ') ?: null,
        ])->filter()->implode(', ');

        $line = collect([$this->address, $cityStateZip ?: null])->filter()->implode(', ');

        return $line !== '' ? $line : null;
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

        // Spammers email every vendor — a sender trusted here should be
        // trusted on jpeterson too. No-ops when the peer isn't configured.
        SyncLeadFilterToPeer::dispatch('allow', $this->email, $this->phone, $this->ip_address, 'allowed from gsc lead #'.$this->id)
            ->afterCommit();

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

        // Same sender, same spam, on the other site. No-ops when the peer
        // isn't configured.
        SyncLeadFilterToPeer::dispatch('deny', $this->email, $this->phone, $this->ip_address, 'blocked from gsc lead #'.$this->id)
            ->afterCommit();
    }

    /**
     * Management-API shape — see Project::toApiArray(). availability +
     * the Hive-forwarding fields are restored for pixel parity with the
     * legacy monolith's lead modal ("Availability" block, "Hive" badge on
     * wasSentToHive()) — gsc-only: jpeterson has no Hive integration and no
     * matching columns, so its own ContactSubmission::toApiArray() omits
     * hive_sent_at/was_sent_to_hive entirely rather than serializing nulls.
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'street' => $this->street(),
            'message' => $this->message,
            'availability' => $this->availability,
            'city' => $this->city,
            'state' => $this->state,
            'zip' => $this->zip,
            'formatted_address' => $this->formattedAddress(),
            'address_candidates' => $this->address_candidates,
            'source' => $this->source,
            'referrer' => $this->referrer,
            'user_agent' => $this->user_agent,
            'ip_address' => $this->ip_address,
            'status' => $this->status,
            'spam_reason' => $this->spam_reason,
            'utm_source' => $this->utm_source,
            'utm_medium' => $this->utm_medium,
            'utm_campaign' => $this->utm_campaign,
            'hive_sent_at' => optional($this->hive_sent_at)->toIso8601String(),
            'was_sent_to_hive' => $this->wasSentToHive(),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
