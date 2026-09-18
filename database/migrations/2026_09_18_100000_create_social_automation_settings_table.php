<?php

use App\Models\Site;
use App\Models\SocialAutomationSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site automatic-posting settings, replacing the hard-coded, single-
 * tenant Schedule blocks that used to live in routes/console.php (Instagram
 * + Facebook twice-weekly posts, Google Business posts, and the GBP
 * catch-up safety-net — see App\Console\Commands\SocialAutomationTick).
 *
 * Seeds ENABLED rows for the default site (config('sites.default'), i.e.
 * gs.construction) reproducing that old cadence exactly, so production
 * posting is unaffected by the switch. Every other site gets no row, which
 * SocialAutomationSetting::defaultsFor() + this table's absence report as
 * enabled=false — automation stays off for a site until someone turns it on
 * from /admin/{site}/social-media.
 *
 * Schema::hasTable/exists guards make the whole thing idempotent: running
 * up() a second time (e.g. from a test) neither errors nor duplicates rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('social_automation_settings')) {
            Schema::create('social_automation_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('site_id')->nullable()->index();
                $table->string('platform', 32);
                $table->boolean('enabled')->default(false);
                $table->json('cadence');
                $table->json('options');
                // e.g. "2026-09-18 13:40" (weekly slot) or "2026-09-18 catch-up".
                $table->string('last_dispatched_slot')->nullable();
                $table->timestamp('last_dispatched_at')->nullable();
                $table->timestamps();

                $table->unique(['site_id', 'platform']);
            });
        }

        $this->seedDefaultSite();
    }

    public function down(): void
    {
        Schema::dropIfExists('social_automation_settings');
    }

    protected function seedDefaultSite(): void
    {
        $site = Site::query()->where('slug', config('sites.default', 'gsc'))->first();
        if (! $site) {
            return;
        }

        foreach (SocialAutomationSetting::DEFAULTS as $platform => $defaults) {
            $exists = SocialAutomationSetting::withoutSiteScope()
                ->where('site_id', $site->id)
                ->where('platform', $platform)
                ->exists();

            if ($exists) {
                continue;
            }

            $setting = new SocialAutomationSetting([
                'platform' => $platform,
                'enabled' => true,
                'cadence' => $defaults['cadence'],
                'options' => $defaults['options'],
            ]);
            $setting->site_id = $site->id;
            $setting->save();
        }
    }
};
