<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\Site;
use App\Services\Social\AutomationSettingsService;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * kit 0.13.0's Social\AutomationSettingsService moved the planner's seed
 * from a value read live inside item() to a constructor argument
 * (App\Services\Social\AutomationSettingsService::__construct() re-derives
 * Site::current()->slug fresh every time Laravel resolves the class — see
 * that class's own docblock). This pins the one property that change could
 * silently break: two tenants asking for the SAME (unsaved, default)
 * cadence must still get DIFFERENT weekly day shuffles, because each
 * resolution reads its own tenant's slug, never a value cached from
 * whichever tenant happened to resolve the class first.
 *
 * SocialAutomationApiTest already covers "a different tenant's own ROW is
 * isolated" (a site with no saved settings reports disabled defaults,
 * never another tenant's enabled row) — this test is about the PLANNER
 * SEED specifically, which that one doesn't exercise.
 */
class SocialAutomationSeedIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_two_tenants_shuffle_the_same_default_cadence_onto_different_days(): void
    {
        $default = Site::where('slug', config('sites.default', 'gsc'))->firstOrFail();
        $other = Site::where('slug', '!=', config('sites.default', 'gsc'))->firstOrFail();

        $this->assertNotSame($default->slug, $other->slug);

        // Twelve consecutive weeks, not just this one: the planner's chain
        // means any single week COULD coincidentally land both tenants on
        // the same pair of days (it did, for week 0, in the pair this test
        // happened to run against) — checking a run of weeks is what
        // actually pins "re-derived per tenant" rather than "got lucky
        // once", while staying robust to whichever non-default site the
        // query above picks.
        $defaultCadence = null;
        $otherCadence = null;
        $defaultWeeks = [];
        $otherWeeks = [];

        for ($w = 0; $w < 12; $w++) {
            Carbon::setTestNow(Carbon::parse('2026-09-27T12:00:00Z', 'America/Chicago')->addWeeks($w));

            $defaultItem = Tenancy::for($default, fn () => app(AutomationSettingsService::class)->item('google_business'));
            $otherItem = Tenancy::for($other, fn () => app(AutomationSettingsService::class)->item('google_business'));

            $defaultCadence ??= $defaultItem['cadence'];
            $otherCadence ??= $otherItem['cadence'];

            $defaultWeeks[] = array_column($defaultItem['plan']['slots'], 'day');
            $otherWeeks[] = array_column($otherItem['plan']['slots'], 'day');
        }

        Carbon::setTestNow();

        // Same cadence in both tenants throughout (the default site is
        // seeded enabled with today's cadence; the other tenant has no row,
        // so it falls back to the identical DEFAULTS cadence) — the only
        // thing that can possibly differ is the seed the planner shuffled
        // it with.
        $this->assertSame($defaultCadence, $otherCadence);

        $this->assertNotSame(
            $defaultWeeks,
            $otherWeeks,
            'two tenants must not shuffle the identical cadence onto the identical days across 12 straight weeks — the seed is not being re-derived per tenant',
        );
    }
}
