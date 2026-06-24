<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A learned allow/deny rule for the contact-form spam filter.
 *
 * Rules are created when an operator corrects the filter from the admin:
 *  - converting spam -> real adds `allow` rules (and clears matching `deny`)
 *  - flagging real  -> spam adds `deny`  rules (and clears matching `allow`)
 *
 * @property string $action     allow|deny
 * @property string $match_type email|phone|domain|ip
 * @property string $value
 */
class LeadFilterRule extends Model
{
    protected $fillable = [
        'action',
        'match_type',
        'value',
        'note',
        'submission_id',
    ];

    /**
     * Free email providers we never blocklist by domain (too broad).
     */
    public const FREE_PROVIDERS = [
        'gmail.com', 'googlemail.com', 'hotmail.com', 'outlook.com', 'live.com',
        'yahoo.com', 'ymail.com', 'aol.com', 'icloud.com', 'me.com', 'mac.com',
        'protonmail.com', 'proton.me', 'fastmail.com', 'zoho.com', 'gmx.com',
        'comcast.net', 'sbcglobal.net', 'att.net', 'verizon.net', 'msn.com',
    ];

    /**
     * Build the normalized signals for a submission's email / phone / ip.
     *
     * @return array{email:?string, phone:?string, domain:?string, ip:?string}
     */
    public static function signalsFor(?string $email, ?string $phone, ?string $ip): array
    {
        $email = $email ? Str::lower(trim($email)) : null;
        $domain = $email && str_contains($email, '@') ? Str::after($email, '@') : null;
        $phone = $phone ? preg_replace('/\D+/', '', $phone) : null;

        return [
            'email' => $email ?: null,
            'phone' => $phone ?: null,
            'domain' => $domain ?: null,
            'ip' => $ip ?: null,
        ];
    }

    /**
     * Return the first matching deny rule for these signals, or null.
     */
    public static function matchDeny(?string $email, ?string $phone, ?string $ip): ?self
    {
        return static::matchAction('deny', $email, $phone, $ip);
    }

    /**
     * True when an allow rule trusts this sender (by email or phone).
     */
    public static function isAllowed(?string $email, ?string $phone, ?string $ip): bool
    {
        return static::matchAction('allow', $email, $phone, $ip) !== null;
    }

    protected static function matchAction(string $action, ?string $email, ?string $phone, ?string $ip): ?self
    {
        $signals = static::signalsFor($email, $phone, $ip);

        $pairs = array_filter([
            ['email', $signals['email']],
            ['phone', $signals['phone']],
            ['domain', $signals['domain']],
            ['ip', $signals['ip']],
        ], fn ($p) => ! empty($p[1]));

        if ($pairs === []) {
            return null;
        }

        return static::query()
            ->where('action', $action)
            ->where(function ($q) use ($pairs) {
                foreach ($pairs as [$type, $value]) {
                    $q->orWhere(fn ($w) => $w->where('match_type', $type)->where('value', $value));
                }
            })
            ->first();
    }

    /**
     * Learn to trust this sender. Adds email + phone allow rules and removes any
     * conflicting deny rules so the filter self-heals.
     */
    public static function learnAllow(ContactSubmission $submission): void
    {
        $signals = static::signalsFor($submission->email, $submission->phone, $submission->ip_address);
        $note = 'allowed from lead #' . $submission->id;

        $rules = array_filter([
            ['email', $signals['email']],
            ['phone', $signals['phone']],
        ], fn ($p) => ! empty($p[1]));

        foreach ($rules as [$type, $value]) {
            // Drop any opposing deny rule first (self-heal).
            static::query()->where('action', 'deny')->where('match_type', $type)->where('value', $value)->delete();

            static::query()->updateOrCreate(
                ['action' => 'allow', 'match_type' => $type, 'value' => $value],
                ['note' => $note, 'submission_id' => $submission->id],
            );
        }
    }

    /**
     * Learn to block this sender. Adds email + phone (+ ip + non-free domain)
     * deny rules and removes any conflicting allow rules.
     */
    public static function learnDeny(ContactSubmission $submission): void
    {
        $signals = static::signalsFor($submission->email, $submission->phone, $submission->ip_address);
        $note = 'blocked from lead #' . $submission->id;

        $rules = array_filter([
            ['email', $signals['email']],
            ['phone', $signals['phone']],
            ['ip', $signals['ip']],
            // Only blocklist a domain when it isn't a shared free provider.
            ['domain', $signals['domain'] && ! in_array($signals['domain'], static::FREE_PROVIDERS, true) ? $signals['domain'] : null],
        ], fn ($p) => ! empty($p[1]));

        foreach ($rules as [$type, $value]) {
            // Drop any opposing allow rule first (self-heal).
            static::query()->where('action', 'allow')->where('match_type', $type)->where('value', $value)->delete();

            static::query()->updateOrCreate(
                ['action' => 'deny', 'match_type' => $type, 'value' => $value],
                ['note' => $note, 'submission_id' => $submission->id],
            );
        }
    }
}
