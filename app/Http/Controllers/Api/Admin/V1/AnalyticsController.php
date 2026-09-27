<?php

namespace App\Http\Controllers\Api\Admin\V1;

use App\Http\Controllers\Controller;
use App\Models\TrackedEvent;
use App\Support\Tenancy;
use Illuminate\Support\Collection;
use SsSystems\Platform\Http\Admin\Concerns\BuildsAnalyticsScreen;
use SsSystems\Platform\Http\Admin\Concerns\BuildsApiResponses;
use SsSystems\Platform\Http\Admin\Contracts\AnalyticsEventReader;
use SsSystems\Platform\Http\Admin\TrackedEventReader;

/**
 * Management API for ss-systems' Livewire\Admin\SiteAnalytics screen.
 * events()/summary() now come from the kit's BuildsAnalyticsScreen trait
 * (0.13.0) — this class only supplies the two hooks it needs: the row
 * source (TrackedEventReader, class-string over App\Models\TrackedEvent so
 * its BelongsToSite global scope keeps firing on every query — see
 * AnalyticsTenantIsolationTest) and this site's own effective timezone.
 * Every other behaviour (the `days`/`type_filter` scope, the four-tile
 * breakdown, the trend chart, top pages, pagination, response shape) is
 * unchanged — see the trait's own docblock for the one deliberate change
 * this port carries: summary() now caches for up to 5 minutes, where this
 * class previously answered from a live SQL aggregate with no cache.
 */
class AnalyticsController extends Controller
{
    use BuildsAnalyticsScreen;
    use BuildsApiResponses;

    protected function analyticsEventReader(): AnalyticsEventReader
    {
        return new TrackedEventReader(TrackedEvent::class);
    }

    /** This site's own effective zone — never a kit constant. */
    /**
     * One cache store serves every tenant here: the summary key carries
     * the current Site (Tenancy::cacheKey), or J. Peterson Design would
     * read gs.construction's numbers for five minutes and vice versa.
     */
    protected function analyticsCacheKey(string $key): string
    {
        return Tenancy::cacheKey($key);
    }

    protected function analyticsTimezone(): string
    {
        return config('services.analytics.timezone', 'America/Chicago');
    }

    /**
     * Public so DashboardStatsController's "contacts" tile can reuse this
     * exact per-type breakdown rather than recounting TrackedEvent
     * independently — same contract as before the port, now over a
     * Collection of normalized rows (AnalyticsEventReader's shape) instead
     * of an Eloquent Builder.
     *
     * @param  Collection<int,array<string,mixed>>  $rows
     * @return array<string,int>
     */
    public static function countsByType(Collection $rows): array
    {
        return (new self)->analyticsCountsByType($rows);
    }
}
