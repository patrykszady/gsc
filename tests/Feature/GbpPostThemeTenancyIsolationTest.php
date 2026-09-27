<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use App\Models\Project;
use App\Models\Site;
use App\Support\Social\EloquentRankedTowns;
use App\Support\Social\SeoIntelTrendsIntel;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Kit 0.13.0 moves GbpPostTheme's two data lookups (core towns, rising
 * trend phrase) behind the kit's new RankedTowns/TrendsIntel contracts.
 * gsc is multi-tenant, so this is written BEFORE the adapters exist (they
 * are the very next thing this unit adds): it proves the adapters inherit
 * AreaServed/Project's BelongsToSite scope and IntelStore's Tenancy::table()
 * scope correctly — one tenant's towns/rising-service answer must never
 * include, or be shifted by, another tenant's rows.
 */
class GbpPostThemeTenancyIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_core_towns_do_not_leak_between_tenants(): void
    {
        $gsc = Site::where('slug', 'gsc')->firstOrFail();
        $jpeterson = Site::where('slug', 'jpeterson')->firstOrFail();

        Tenancy::for($gsc, function () {
            AreaServed::create(['city' => 'Palatine', 'slug' => 'palatine-gsc-iso']);
            Project::create(['title' => 'Palatine Kitchen Iso', 'slug' => 'palatine-kitchen-gsc-iso', 'project_type' => 'kitchen', 'location' => 'Palatine, IL', 'is_published' => true, 'completed_at' => now()]);
        });
        Tenancy::for($jpeterson, function () {
            AreaServed::create(['city' => 'Naperville', 'slug' => 'naperville-jp-iso']);
            Project::create(['title' => 'Naperville Kitchen Iso', 'slug' => 'naperville-kitchen-jp-iso', 'project_type' => 'kitchen', 'location' => 'Naperville, IL', 'is_published' => true, 'completed_at' => now()]);
        });

        $gscTowns = Tenancy::for($gsc, fn () => (new EloquentRankedTowns)->coreTowns(6));
        $jpTowns = Tenancy::for($jpeterson, fn () => (new EloquentRankedTowns)->coreTowns(6));

        $this->assertSame(['Palatine'], $gscTowns, 'gsc must not see jpeterson\'s town');
        $this->assertSame(['Naperville'], $jpTowns, 'jpeterson must not see gsc\'s town');
    }

    public function test_rising_phrase_does_not_leak_between_tenants(): void
    {
        $gsc = Site::where('slug', 'gsc')->firstOrFail();
        $jpeterson = Site::where('slug', 'jpeterson')->firstOrFail();

        DB::table('seo_intel_snapshots')->insert([
            'site_id' => $gsc->id, 'family' => 'trends', 'kind' => 'phrase', 'subject' => 'bathroom remodel',
            'taken_on' => now()->toDateString(), 'run_id' => 'r',
            'metrics' => json_encode(['interest_avg_12m' => 50, 'interest_4w_avg' => 80]),
            'payload' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('seo_intel_snapshots')->insert([
            'site_id' => $jpeterson->id, 'family' => 'trends', 'kind' => 'phrase', 'subject' => 'basement remodel',
            'taken_on' => now()->toDateString(), 'run_id' => 'r',
            'metrics' => json_encode(['interest_avg_12m' => 50, 'interest_4w_avg' => 90]),
            'payload' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $gscRising = Tenancy::for($gsc, fn () => (new SeoIntelTrendsIntel)->risingPhrase());
        $jpRising = Tenancy::for($jpeterson, fn () => (new SeoIntelTrendsIntel)->risingPhrase());

        $this->assertSame(['service' => 'bathroom', 'phrase' => 'bathroom remodel'], $gscRising, 'gsc must see only its own rising phrase');
        $this->assertSame(['service' => 'basement', 'phrase' => 'basement remodel'], $jpRising, 'jpeterson must see only its own rising phrase, not gsc\'s');
    }
}
