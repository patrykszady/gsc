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
    public static function address(?Site $site = null): string
    {
        $explicit = trim((string) config('brand.lead_email', ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $site ??= Site::current();

        if ($site->slug !== (string) config('sites.default')) {
            return trim((string) config('brand.email', ''));
        }

        return trim((string) config('mail.from.address', ''));
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

        $address = strtolower(self::address($site));

        if ($address === '') {
            return true;
        }

        $default = require config_path('brand.php');

        return in_array($address, array_filter([
            strtolower(trim((string) config('mail.from.address', ''))),
            strtolower(trim((string) ($default['lead_email'] ?? ''))),
            strtolower(trim((string) ($default['email'] ?? ''))),
        ]), true);
    }
}
