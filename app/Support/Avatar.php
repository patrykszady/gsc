<?php

namespace App\Support;

/**
 * Initials avatar as an inline SVG data URI.
 *
 * Replaces a remote call to ui-avatars.com that hardcoded background=0ea5e9
 * (Tailwind sky-500). Two problems with that: the colour was baked into a
 * remote PNG so the per-tenant accent could never reach it, and every admin
 * page load sent the signed-in user's NAME to a third-party service — a
 * client's name, now that tenants have their own logins.
 *
 * Rendered locally it needs no network, works offline and on a preview host,
 * and picks up the tenant accent from config/sites/{slug}/admin.php.
 */
class Avatar
{
    /** Data URI for an initials avatar in the current tenant's accent colour. */
    public static function initials(?string $name, ?string $background = null): string
    {
        $initials = self::initialsFrom($name);

        // -500 of the tenant admin ramp; falls back to Tailwind sky-500, which
        // is what the whole admin uses when a site configures no accent.
        $background ??= (string) (config('admin.accent.500') ?? '#0ea5e9');

        // Both accent -500 values in use clear AA against white (4.52:1 for
        // the J. Peterson teal), so a white glyph is safe.
        $svg = <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64">
            <rect width="64" height="64" rx="32" fill="{$background}"/>
            <text x="32" y="33" fill="#ffffff" font-family="system-ui,-apple-system,Segoe UI,Roboto,sans-serif"
                  font-size="26" font-weight="600" text-anchor="middle" dominant-baseline="central">{$initials}</text>
        </svg>
        SVG;

        return 'data:image/svg+xml;base64,' . base64_encode(preg_replace('/\s+/', ' ', trim($svg)));
    }

    /** "Jenn Peterson" => "JP", "Patryk" => "P", null => "?" */
    public static function initialsFrom(?string $name): string
    {
        $parts = preg_split('/\s+/', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($parts === []) {
            return '?';
        }

        $first = mb_strtoupper(mb_substr($parts[0], 0, 1));
        $last = count($parts) > 1 ? mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1)) : '';

        return htmlspecialchars($first . $last, ENT_QUOTES | ENT_XML1);
    }
}
