<?php

namespace Tests\Feature\Console;

use App\Console\Commands\SocialAutomationTick;
use App\Models\ImageSocialPost;
use App\Models\Project;
use App\Models\ProjectImage;
use App\Models\Site;
use App\Models\SocialAutomationSetting;
use App\Services\GoogleBusinessProfileService;
use App\Services\MetaSocialService;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

/**
 * social:automation-tick — the single 5-minute scheduler tick that replaced
 * the hard-coded, single-tenant Schedule blocks.
 *
 * The tick itself is invoked by resolving the command's handle() through the
 * container (App\Console\Commands\SocialAutomationTick) rather than through
 * Artisan::call()/$this->artisan(), because the INNER 'social:post' call the
 * tick makes is what gets faked here (Facade::shouldReceive on the Artisan
 * facade) — the real social:post command can publish to live Meta/GBP
 * accounts, and this test only needs to know WHETHER and with WHAT
 * arguments the tick dispatched it. Faking the whole Artisan facade would
 * also swallow the outer invocation if that used the same facade.
 */
class SocialAutomationTickTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function runTick(): void
    {
        $this->app->call([new SocialAutomationTick, 'handle']);
    }

    protected function fakeConfigured(bool $instagram = true, bool $facebook = true, bool $googleBusiness = true): void
    {
        $meta = Mockery::mock(MetaSocialService::class);
        $meta->shouldReceive('isInstagramConfigured')->andReturn($instagram);
        $meta->shouldReceive('isFacebookConfigured')->andReturn($facebook);
        $this->app->instance(MetaSocialService::class, $meta);

        $gbp = Mockery::mock(GoogleBusinessProfileService::class);
        $gbp->shouldReceive('isConfigured')->andReturn($googleBusiness);
        // The photo observers ask this since the publishing switch was retired
        // (2026-09-23): connected is ready.
        $gbp->shouldReceive('isConnected')->andReturn($googleBusiness);
        $this->app->instance(GoogleBusinessProfileService::class, $gbp);
    }

    /** A setting whose plan draws exactly one slot at a KNOWN minute (window start == end). */
    protected function settingWithFixedSlot(Site $site, string $platform, int $isoDay, string $at, array $options = [], bool $enabled = true): SocialAutomationSetting
    {
        // updateOrCreate: the default site (gsc) already has a seeded row
        // for every platform (2026_09_18_100000_create_social_automation_settings_table).
        return Tenancy::for($site, function () use ($platform, $isoDay, $at, $options, $enabled) {
            return SocialAutomationSetting::updateOrCreate(
                ['platform' => $platform],
                [
                    'enabled' => $enabled,
                    'cadence' => ['per_week' => 1, 'days' => [$isoDay], 'window' => ['start' => $at, 'end' => $at]],
                    'options' => $options,
                    'last_dispatched_slot' => null,
                    'last_dispatched_at' => null,
                ],
            );
        });
    }

    /**
     * A published google_business ImageSocialPost dated $daysAgo days back
     * from $reference (the tick's frozen "now"), for the catch-up staleness
     * check.
     *
     * Freezes time to that historical instant while building the fixture
     * (restoring the real clock before returning) so `published_at` AND the
     * row's auto `created_at` both land in the past relative to $reference
     * — matching what a real stale post looks like. Using the real
     * wall-clock now() here (as this used to) would date the fixture
     * relative to whatever day the test suite happens to run on rather than
     * the frozen '2026-09-16' the catch-up tests reason about, and would
     * also leave created_at exactly at the tick's instant once the caller
     * later freezes to $reference for the tick — tripping maybeCatchUp's
     * "already posted today" guard.
     */
    protected function publishedPostFor(Site $site, int $daysAgo, Carbon $reference): void
    {
        // Fake the queue while building this fixture: creating a published
        // Project/ProjectImage fires the real observers, which (this being a
        // real dev box's copied .env) can carry real Gemini/GBP credentials
        // — see ContentOpsControllerTest's class docblock for the same
        // caution. Nothing here is what this test is exercising.
        Queue::fake();

        $publishedAt = $reference->copy()->subDays($daysAgo);

        Carbon::setTestNow($publishedAt);

        try {
            Tenancy::for($site, function () use ($publishedAt) {
                $project = Project::create([
                    'title' => 'Catch-up Fixture',
                    'slug' => 'catch-up-fixture-'.uniqid(),
                    'project_type' => 'kitchen',
                    'is_published' => true,
                    'location' => 'Elsewhere, IL',
                ]);
                $image = ProjectImage::create([
                    'project_id' => $project->id,
                    'filename' => 'photo-'.uniqid().'.jpg',
                    'original_filename' => 'photo.jpg',
                    'path' => 'projects/1/photo.jpg',
                    'alt_text' => 'A lovely kitchen',
                ]);
                ImageSocialPost::create([
                    'project_image_id' => $image->id,
                    'platform' => 'google_business',
                    'status' => 'published',
                    'published_at' => $publishedAt,
                ]);
            });
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_a_due_slot_dispatches_exactly_once_across_surrounding_ticks(): void
    {
        $this->fakeConfigured();

        $site = Site::where('slug', config('sites.default', 'gsc'))->firstOrFail();
        // 2026-09-16 is a Wednesday (ISO day 3).
        $this->settingWithFixedSlot($site, 'instagram', 3, '13:20', ['location_tag' => true]);

        Artisan::shouldReceive('call')
            ->once()
            ->with('social:post', Mockery::on(function ($params) {
                return $params['--platform'] === 'instagram'
                    && $params['--via'] === 'puppeteer'
                    && $params['--yes'] === true
                    && $params['--random-delay'] === 0;
            }))
            ->andReturn(0);

        // Tick 5 minutes BEFORE the slot: not due yet.
        Carbon::setTestNow(Carbon::parse('2026-09-16 13:15:00', 'America/Chicago'));
        $this->runTick();

        // Tick AT the slot: fires.
        Carbon::setTestNow(Carbon::parse('2026-09-16 13:20:00', 'America/Chicago'));
        $this->runTick();

        // Tick 5 minutes AFTER: already recorded, must not fire again.
        Carbon::setTestNow(Carbon::parse('2026-09-16 13:25:00', 'America/Chicago'));
        $this->runTick();

        Carbon::setTestNow();

        $setting = Tenancy::for($site, fn () => SocialAutomationSetting::where('platform', 'instagram')->first());
        $this->assertSame('2026-09-16 13:20', $setting->last_dispatched_slot);
    }

    public function test_disabled_settings_never_fire(): void
    {
        $this->fakeConfigured();

        $site = Site::where('slug', config('sites.default', 'gsc'))->firstOrFail();
        $setting = $this->settingWithFixedSlot($site, 'facebook', 3, '13:20', [], enabled: false);

        Artisan::shouldReceive('call')->never();

        Carbon::setTestNow(Carbon::parse('2026-09-16 13:20:00', 'America/Chicago'));
        $this->runTick();
        Carbon::setTestNow();

        $this->assertNull(Tenancy::for($site, fn () => $setting->fresh())->last_dispatched_slot);
    }

    public function test_unconfigured_platforms_never_fire(): void
    {
        $this->fakeConfigured(instagram: false);

        $site = Site::where('slug', config('sites.default', 'gsc'))->firstOrFail();
        $setting = $this->settingWithFixedSlot($site, 'instagram', 3, '13:20', ['location_tag' => true]);

        Artisan::shouldReceive('call')->never();

        Carbon::setTestNow(Carbon::parse('2026-09-16 13:20:00', 'America/Chicago'));
        $this->runTick();
        Carbon::setTestNow();

        $this->assertNull(Tenancy::for($site, fn () => $setting->fresh())->last_dispatched_slot);
    }

    public function test_only_the_enabled_sites_row_fires(): void
    {
        $this->fakeConfigured();

        $gsc = Site::where('slug', 'gsc')->firstOrFail();
        $ss = Site::where('slug', 'ss')->firstOrFail(); // active, per seed_additional_sites migration

        $this->settingWithFixedSlot($gsc, 'facebook', 3, '13:20', [], enabled: true);
        $this->settingWithFixedSlot($ss, 'facebook', 3, '13:20', [], enabled: false);

        Artisan::shouldReceive('call')
            ->once()
            ->with('social:post', Mockery::on(fn ($params) => $params['--platform'] === 'facebook'))
            ->andReturn(0);

        Carbon::setTestNow(Carbon::parse('2026-09-16 13:20:00', 'America/Chicago'));
        $this->runTick();
        Carbon::setTestNow();

        $gscSetting = Tenancy::for($gsc, fn () => SocialAutomationSetting::where('platform', 'facebook')->first());
        $ssSetting = Tenancy::for($ss, fn () => SocialAutomationSetting::where('platform', 'facebook')->first());

        $this->assertSame('2026-09-16 13:20', $gscSetting->last_dispatched_slot);
        $this->assertNull($ssSetting->last_dispatched_slot);
    }

    public function test_google_business_dispatch_uses_queue_and_themed(): void
    {
        $this->fakeConfigured();

        $site = Site::where('slug', config('sites.default', 'gsc'))->firstOrFail();
        $setting = $this->settingWithFixedSlot($site, 'google_business', 3, '13:20', ['themed' => true]);

        Artisan::shouldReceive('call')
            ->once()
            ->with('social:post', Mockery::on(function ($params) {
                return $params['--platform'] === 'google_business'
                    && $params['--queue'] === true
                    && $params['--themed'] === true;
            }))
            ->andReturn(0);

        Carbon::setTestNow(Carbon::parse('2026-09-16 13:20:00', 'America/Chicago'));
        $this->runTick();
        Carbon::setTestNow();

        $this->assertSame('2026-09-16 13:20', Tenancy::for($site, fn () => $setting->fresh())->last_dispatched_slot);
    }

    public function test_gbp_catch_up_fires_once_a_day_when_stale(): void
    {
        $this->fakeConfigured();

        $site = Site::where('slug', config('sites.default', 'gsc'))->firstOrFail();
        // Weekly slot pinned to Monday 03:00 so it can never collide with
        // the 10:20 catch-up check used below.
        $this->settingWithFixedSlot($site, 'google_business', 1, '03:00', [
            'themed' => false,
            'catch_up_after_days' => 6,
        ]);

        $tick = Carbon::parse('2026-09-16 10:20:00', 'America/Chicago');
        $this->publishedPostFor($site, daysAgo: 10, reference: $tick);

        Artisan::shouldReceive('call')
            ->once()
            ->with('social:post', ['--platform' => 'google_business', '--queue' => true])
            ->andReturn(0);

        Carbon::setTestNow($tick);
        $this->runTick();

        // A second tick the same day must not re-fire.
        Carbon::setTestNow(Carbon::parse('2026-09-16 10:25:00', 'America/Chicago'));
        $this->runTick();
        Carbon::setTestNow();

        $fresh = Tenancy::for($site, fn () => SocialAutomationSetting::where('platform', 'google_business')->first());
        $this->assertSame('2026-09-16 catch-up', $fresh->last_dispatched_slot);
    }

    public function test_gbp_catch_up_does_not_fire_when_not_stale(): void
    {
        $this->fakeConfigured();

        $site = Site::where('slug', config('sites.default', 'gsc'))->firstOrFail();
        $this->settingWithFixedSlot($site, 'google_business', 1, '03:00', [
            'themed' => false,
            'catch_up_after_days' => 6,
        ]);

        $tick = Carbon::parse('2026-09-16 10:20:00', 'America/Chicago');
        $this->publishedPostFor($site, daysAgo: 2, reference: $tick);

        Artisan::shouldReceive('call')->never();

        Carbon::setTestNow($tick);
        $this->runTick();
        Carbon::setTestNow();

        $fresh = Tenancy::for($site, fn () => SocialAutomationSetting::where('platform', 'google_business')->first());
        $this->assertNull($fresh->last_dispatched_slot);
    }
}
