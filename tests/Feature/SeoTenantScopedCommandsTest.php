<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Support\Tenancy;
use Closure;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
use SsSystems\Platform\Seo\KeywordResearch\Console\KeywordResearchCommand;
use SsSystems\Platform\Seo\Visibility\Console\CompetitorBrandTrackCommand;
use Tests\TestCase;

/**
 * The kit's KeywordResearchCommand and CompetitorBrandTrackCommand read
 * gsc_query_metrics through their own tenant column and closure. Both were
 * once handed to them through when()->needs('$tenantId'), which the
 * container ignores for a ?\Closure argument: the closure arrived as null
 * and seo:competitor-brand-track crashed on every scheduled run
 * ("Value of type null is not callable", 2026-11-02 to 2026-11-08).
 */
class SeoTenantScopedCommandsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function jpeterson(): Site
    {
        $site = Site::query()->firstOrCreate(['slug' => 'jpeterson'], [
            'name' => 'J. Peterson Design', 'theme' => 'jpeterson', 'hosts' => ['jpeterson-design.com'], 'primary_host' => 'jpeterson-design.com',
        ]);
        $site->forceFill(['is_active' => true])->save();
        Site::forgetActive();

        return $site->fresh();
    }

    private function queryMetric(Site $site, string $query): void
    {
        DB::table('gsc_query_metrics')->insert([
            'site_id' => $site->id,
            'date' => now()->subDays(2)->toDateString(),
            'site_url' => 'sc-domain:'.$site->primary_host,
            'query' => $query,
            'page' => 'https://'.$site->primary_host.'/',
            'country' => 'usa',
            'impressions' => 40,
            'clicks' => 2,
            'ctr' => 0.05,
            'position' => 7.5,
            'dim_hash' => sha1($site->id.$query),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array{0: ?string, 1: mixed} the tenant column and closure the container built the command with */
    private function tenantScope(string $command): array
    {
        $instance = app($command);

        return [
            (new ReflectionProperty($instance, 'tenantColumn'))->getValue($instance),
            (new ReflectionProperty($instance, 'tenantId'))->getValue($instance),
        ];
    }

    public function test_both_commands_are_built_with_the_tenant_column_and_a_live_tenant_closure(): void
    {
        $gsc = Site::query()->where('slug', 'gsc')->firstOrFail();
        $jpeterson = $this->jpeterson();

        foreach ([KeywordResearchCommand::class, CompetitorBrandTrackCommand::class] as $command) {
            [$column, $tenantId] = $this->tenantScope($command);

            $this->assertSame('site_id', $column, $command);
            $this->assertInstanceOf(Closure::class, $tenantId, $command);
            $this->assertSame($gsc->id, Tenancy::for($gsc, fn () => $tenantId()), $command);
            $this->assertSame($jpeterson->id, Tenancy::for($jpeterson, fn () => $tenantId()), "{$command} reads the tenant at call time, not when it was built");
        }
    }

    public function test_competitor_brand_track_runs_and_reports_only_the_current_sites_queries(): void
    {
        $gsc = Site::query()->where('slug', 'gsc')->firstOrFail();
        $jpeterson = $this->jpeterson();
        $this->queryMetric($gsc, 'gs construction vs houzz pro');
        $this->queryMetric($jpeterson, 'jpeterson design versus havenly');

        $output = Tenancy::for($gsc, function () {
            $this->assertSame(0, Artisan::call('seo:competitor-brand-track', ['--days' => 28]));

            return Artisan::output();
        });

        $this->assertStringContainsString('gs construction vs houzz pro', $output);
        $this->assertStringNotContainsString('havenly', $output, "gsc's report must not list jpeterson's queries");
    }
}
