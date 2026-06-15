<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TrackedEvent extends Model
{
    public const TYPE_PHONE_CLICK = 'phone_click';
    public const TYPE_EMAIL_CLICK = 'email_click';
    public const TYPE_FORM_SUBMIT = 'form_submit';
    public const TYPE_CTA_CLICK = 'cta_click';

    protected $fillable = [
        'type',
        'label',
        'page_path',
        'referrer',
        'session_id',
        'country',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Allowed event types accepted by the ingest endpoint.
     *
     * @return array<int, string>
     */
    public static function allowedTypes(): array
    {
        return [
            self::TYPE_PHONE_CLICK,
            self::TYPE_EMAIL_CLICK,
            self::TYPE_FORM_SUBMIT,
            self::TYPE_CTA_CLICK,
        ];
    }

    /**
     * Human-readable label for an event type.
     */
    public static function typeLabel(string $type): string
    {
        return match ($type) {
            self::TYPE_PHONE_CLICK => 'Phone click',
            self::TYPE_EMAIL_CLICK => 'Email click',
            self::TYPE_FORM_SUBMIT => 'Form submission',
            self::TYPE_CTA_CLICK => 'CTA click',
            default => ucfirst(str_replace('_', ' ', $type)),
        };
    }
}
