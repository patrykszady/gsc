<?php

namespace App\Support\Reviews;

/**
 * Angi review import.
 *
 * Angi puts its reviews in the profile page's schema.org LocalBusiness
 * block — reviewer, rating, date and the FULL body, untruncated — so the
 * scraper reads that rather than the rendered cards. Cloudflare turns away
 * plain requests and headless browsers, so the import drives a real browser
 * on a virtual display, the way the citation builder does.
 *
 * Angi gives no per-review permalink, so every imported review links back
 * to the profile page.
 */
final class AngiReviews extends ReviewImport
{
    public static function platform(): string
    {
        return 'angi';
    }

    public static function label(): string
    {
        return 'Angi';
    }

    public static function command(): string
    {
        return 'testimonials:sync-angi-reviews';
    }

    protected static function commandOptions(): array
    {
        return ['--only-new' => true];
    }

    /**
     * Is this profile page the business we are importing for?
     *
     * A pasted URL for another contractor would otherwise import their
     * reviews as ours. Angi appends the registered suffix to the name it
     * prints ("GS Construction & Remodeling" for brand "GS Construction",
     * "J. Peterson Design LLC" for "J. Peterson Design"), so the page name
     * must START with the brand once punctuation is stripped — never merely
     * contain it, or "Atlas GS Construction Partners" would pass.
     */
    public static function pageBelongsToBrand(string $pageBusinessName, string $brand): bool
    {
        $needle = self::normalizeName($brand);

        return $needle !== '' && str_starts_with(self::normalizeName($pageBusinessName), $needle);
    }
}
