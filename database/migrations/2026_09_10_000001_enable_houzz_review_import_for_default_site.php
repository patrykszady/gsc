<?php

use App\Models\PlatformSetting;
use App\Models\Site;
use Illuminate\Database\Migrations\Migration;

/**
 * The Houzz review import became a per-site switch on Admin → Platforms
 * (off by default, so no tenant inherits another's import). gs.construction
 * has run it weekly for years — keep it on without anyone having to notice.
 */
return new class extends Migration
{
    public function up(): void
    {
        $site = Site::query()->where('slug', config('sites.default', 'gsc'))->first();
        if (! $site) {
            return;
        }

        $exists = PlatformSetting::withoutSiteScope()
            ->where('site_id', $site->id)
            ->where('key', 'houzz.reviews.enabled')
            ->exists();
        if ($exists) {
            return;
        }

        $setting = new PlatformSetting(['key' => 'houzz.reviews.enabled', 'value' => '1']);
        $setting->site_id = $site->id;
        $setting->save();
    }

    public function down(): void
    {
        // Leave the switch as the admin last set it.
    }
};
