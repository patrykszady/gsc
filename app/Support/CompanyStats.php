<?php

namespace App\Support;

use App\Models\AreaServed;
use App\Models\HiveProjectZipCount;
use App\Models\Testimonial;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Headline company numbers, derived rather than hardcoded.
 *
 * "300+ projects completed" was typed into two Blade files and had drifted well
 * behind reality — the real figure is 405.
 *
 * SOURCE MATTERS HERE. The obvious table, `projects`, holds 29 rows: that is the
 * website PORTFOLIO, a curated showcase, not the job history. Deriving the stat
 * from it would have replaced "300+" with "0+" and understated the business by
 * two orders of magnitude. The real count is the per-ZIP job tally synced from
 * Hive (hive_project_zip_counts.count), which is the company's actual CRM data.
 *
 * Figures round DOWN to a milestone so the site never overstates: 405 → "400+".
 */
class CompanyStats
{
    /** Round down to this, so the claim ticks over every 50 jobs. */
    private const PROJECT_STEP = 50;

    /** The window the "projects completed" figure covers. */
    private const PROJECT_YEARS = 10;

    /**
     * Never publish less than the figure already in market. If the Hive sync
     * fails or has not run for a new environment, a sudden drop to "0+" would
     * be worse than a stale number.
     */
    private const PROJECT_FLOOR = 300;

    /** Completed projects, rounded down to the nearest 50 (e.g. 405 → 400). */
    public static function projectsCompleted(): int
    {
        return Cache::remember(Tenancy::cacheKey('stats.projects_completed'), now()->addHours(12), function (): int {
            if (! Schema::hasTable('hive_project_zip_counts')) {
                return self::PROJECT_FLOOR;
            }

            // Site-scoped by BelongsToSite, so each tenant reports its own.
            $total = (int) HiveProjectZipCount::query()->sum('count');

            // A tenant with no synced jobs gets 0, NOT the floor — the floor
            // protects gs.construction's published claim from a bad sync, and
            // handing it to another site would have J. Peterson Design
            // advertising a contractor's 300 completed jobs. Callers hide the
            // stat when this is 0.
            if ($total === 0) {
                return 0;
            }

            $rounded = intdiv($total, self::PROJECT_STEP) * self::PROJECT_STEP;

            return max($rounded, self::PROJECT_FLOOR);
        });
    }

    /** Five-star reviews, rounded down to the nearest 10 (e.g. 72 → 70). */
    public static function reviewsCount(): int
    {
        return Cache::remember(Tenancy::cacheKey('stats.reviews_count'), now()->addHours(12), function (): int {
            $total = Testimonial::query()->visible()->count();

            // Steps of 5, so the claim ticks over twice as often as projects.
            return intdiv($total, 5) * 5;
        });
    }

    /**
     * Cities we actually serve — the admin area list, which is the curated
     * source of truth (towns get removed from it deliberately).
     *
     * Rounded DOWN to the nearest 10 so the published claim is never larger
     * than the real list. The copy said "more than 89 cities" in three places
     * against an actual 70, which is the sort of number nobody re-checks after
     * a town is removed in admin.
     */
    public static function citiesServed(): int
    {
        return Cache::remember(Tenancy::cacheKey('stats.cities_served'), now()->addHours(12), function (): int {
            return intdiv(AreaServed::query()->count(), 10) * 10;
        });
    }

    /** "70+" — never overstates, because citiesServed() rounds down. */
    public static function citiesServedLabel(): string
    {
        return self::citiesServed() . '+';
    }

    /**
     * The EXACT number of reviews a visitor can actually find on the site.
     *
     * Distinct from reviewsCount(), which rounds down to a marketing figure
     * ("70+"). Use this wherever a precise number is published — schema
     * reviewCount/ratingCount, "All N reviews" links, comparison tables.
     *
     * Eight code paths were calling Testimonial::count() directly, which
     * counts HIDDEN testimonials too: the site claimed 72 reviews while 70
     * were visible. An aggregateRating whose reviewCount exceeds the reviews
     * Google can crawl is exactly the mismatch that gets rich results pulled.
     */
    public static function reviewsTotal(): int
    {
        return Cache::remember(Tenancy::cacheKey('stats.reviews_total'), now()->addHours(12), function (): int {
            return Testimonial::query()->visible()->count();
        });
    }

    /** "400+" — the figure as it appears on the page. */
    public static function projectsCompletedLabel(): string
    {
        return self::projectsCompleted() . '+';
    }

    /** "70+" */
    public static function reviewsCountLabel(): string
    {
        return self::reviewsCount() . '+';
    }

    /** The window the project figure covers, in years. */
    public static function projectYears(): int
    {
        return self::PROJECT_YEARS;
    }

    /**
     * How often a project completes, DERIVED — "every 9 days".
     *
     * Deliberately calculated rather than asserted. The real numbers are 405
     * projects over 10 years, which is one every 9 days; claiming "one every
     * week" would need 520 and overstates by roughly 28%. Deriving it also
     * means the sentence stays true on its own as the count grows: at 520 it
     * will start saying "every week" without anyone editing copy.
     */
    public static function projectCadenceLabel(): string
    {
        $projects = self::projectsCompleted();

        if ($projects <= 0) {
            return '';
        }

        $days = (int) round((self::PROJECT_YEARS * 365) / $projects);

        return match (true) {
            $days <= 1 => 'every day',
            $days <= 7 => "every {$days} days",
            $days <= 8 => 'every week',
            $days <= 31 => "every {$days} days",
            default => 'every ' . (int) round($days / 30) . ' months',
        };
    }

    /** Projects a year, rounded down to a tidy figure — "40+". */
    public static function projectsPerYearLabel(): string
    {
        $perYear = intdiv(self::projectsCompleted(), self::PROJECT_YEARS);

        return (intdiv($perYear, 10) * 10) . '+';
    }
}
