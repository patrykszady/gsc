<?php

namespace App\Support\Reviews;

/**
 * Houzz review import.
 *
 * The scraper reads the public profile's review cards in a browser and
 * resolves each one's permalink from the reviewer's activity page.
 */
final class HouzzReviews extends ReviewImport
{
    public static function platform(): string
    {
        return 'houzz';
    }

    public static function label(): string
    {
        return 'Houzz';
    }

    public static function command(): string
    {
        return 'testimonials:sync-houzz-reviews';
    }

    protected static function commandOptions(): array
    {
        return ['--browser-scrape' => true, '--only-new' => true];
    }

    /**
     * Houzz writes the reviewed business into every review URL
     * ("/viewReview/1453810/GS-Construction-review"), so a reviewer's
     * activity page can list reviews of other pros too. The last path
     * segment must START with the brand (punctuation and spacing stripped),
     * which allows the suffix Houzz adds from the registered name —
     * "J. Peterson Design" matches "J-Peterson-Design-LLC-review" — but not
     * another business that merely contains the name
     * ("Atlas-GS-Construction-Partners-review").
     */
    public static function reviewUrlBelongsTo(string $url, string $brand): bool
    {
        $needle = self::normalizeName($brand);
        $segments = array_values(array_filter(explode('/', (string) parse_url($url, PHP_URL_PATH))));
        $last = self::normalizeName((string) (end($segments) ?: ''));

        return $needle !== ''
            && str_contains(self::normalizeName(implode('/', $segments)), 'viewreview')
            && str_starts_with($last, $needle);
    }
}
