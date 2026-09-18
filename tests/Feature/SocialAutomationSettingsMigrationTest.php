<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\SocialAutomationSetting;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * 2026_09_18_100000_create_social_automation_settings_table seeds ENABLED
 * rows for the default site (gsc) reproducing today's hard-coded cadence
 * exactly, so gs.construction keeps posting unchanged the moment this
 * deploys — and every other site gets no row (disabled) until an operator
 * turns automation on.
 */
class SocialAutomationSettingsMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_default_site_is_seeded_enabled_with_todays_cadence(): void
    {
        $gsc = Site::where('slug', config('sites.default', 'gsc'))->firstOrFail();

        foreach (SocialAutomationSetting::PLATFORMS as $platform) {
            $setting = SocialAutomationSetting::forSite($gsc)->where('platform', $platform)->first();

            $this->assertNotNull($setting, "no seeded row for {$platform}");
            $this->assertTrue($setting->enabled);
            $this->assertSame(SocialAutomationSetting::DEFAULTS[$platform]['cadence'], $setting->cadence);
            $this->assertSame(SocialAutomationSetting::DEFAULTS[$platform]['options'], $setting->options);
        }
    }

    public function test_every_other_site_has_no_row(): void
    {
        $other = Site::where('slug', '!=', config('sites.default', 'gsc'))->first();
        $this->assertNotNull($other, 'need at least one non-default seeded site to assert against');

        $count = SocialAutomationSetting::forSite($other)->count();
        $this->assertSame(0, $count);
    }

    public function test_running_the_migration_a_second_time_does_not_duplicate_or_error(): void
    {
        $migration = require database_path('migrations/2026_09_18_100000_create_social_automation_settings_table.php');

        $migration->up();
        $migration->up();

        $gsc = Site::where('slug', config('sites.default', 'gsc'))->firstOrFail();
        $this->assertSame(3, SocialAutomationSetting::forSite($gsc)->count());

        foreach (SocialAutomationSetting::PLATFORMS as $platform) {
            $this->assertSame(
                1,
                SocialAutomationSetting::forSite($gsc)->where('platform', $platform)->count(),
                "{$platform} row was duplicated"
            );
        }
    }
}
