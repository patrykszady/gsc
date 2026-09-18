<?php

namespace App\Support;

use App\Models\Site;

/**
 * Where a tenant's leads are delivered.
 *
 * The contact form mailed `mail.from.address` — one inbox for the whole
 * deployment, which is gs.construction's. That is fine while GS is the only
 * site and wrong the moment another business's form goes live: her enquiries
 * would arrive in a competitor-adjacent inbox she does not own, and she would
 * never see them.
 *
 * Resolution, in order:
 *   1. `brand.lead_email` — the site said explicitly where leads go.
 *   2. a tenant that is NOT the default: its own `brand.email`. Never the
 *      deployment's MAIL_FROM — crossing businesses is the failure this exists
 *      to prevent, and a wrong-but-own address is visible, while a silent
 *      delivery to somebody else's inbox is not.
 *   3. the default site: `mail.from.address`, exactly as before.
 */
final class LeadInbox
{
    /**
     * Every address this tenant's leads go to. `brand.lead_email` may name
     * several, comma-separated — a two-person studio wants both inboxes, and
     * a lead nobody reads is the failure mode this class exists for.
     *
     * @return list<string>
     */
    public static function recipients(?Site $site = null): array
    {
        $site ??= Site::current();

        $configured = (string) config('brand.lead_email', '');

        if (trim($configured) === '') {
            $configured = $site->slug !== (string) config('sites.default')
                ? (string) config('brand.email', '')
                : (string) config('mail.from.address', '');
        }

        return array_values(array_filter(array_map('trim', explode(',', $configured))));
    }

    /** The address a single-recipient caller should use — the first, or none. */
    public static function address(?Site $site = null): string
    {
        return self::recipients($site)[0] ?? '';
    }

    /**
     * True when this tenant's leads would land in the default site's inbox —
     * another business's mail, and the thing to catch before launch, not after.
     */
    public static function isSharedWithDefaultSite(?Site $site = null): bool
    {
        $site ??= Site::current();

        if ($site->slug === (string) config('sites.default')) {
            return false;
        }

        $addresses = array_map('strtolower', self::recipients($site));

        if ($addresses === []) {
            return true;
        }

        $default = require config_path('brand.php');

        $theirs = array_filter([
            strtolower(trim((string) config('mail.from.address', ''))),
            strtolower(trim((string) ($default['lead_email'] ?? ''))),
            strtolower(trim((string) ($default['email'] ?? ''))),
        ]);

        return array_intersect($addresses, $theirs) !== [];
    }
}
