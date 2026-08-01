<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Support\SiteConfig;
use App\Support\Tenancy;
use Tests\TestCase;

/**
 * Regression guard for tenant config bleed.
 *
 * SiteConfig::applyRuntime() only injects keys a site overrides, so without a
 * restore step a site that overrides nothing inherits whichever tenant ran
 * before it in the same process — silently, and only in console/queue where
 * one process handles many tenants.
 */
class TenancyIsolationTest extends TestCase
{
    public function test_config_does_not_bleed_between_tenants(): void
    {
        $gsc = Site::where('slug', 'gsc')->firstOrFail();
        $ss = Site::where('slug', 'ss')->firstOrFail();

        $gscEmail = Tenancy::for($gsc, fn () => config('brand.email'));
        $ssEmail = Tenancy::for($ss, fn () => config('brand.email'));

        $this->assertNotSame($gscEmail, $ssEmail, 'tenants must not share brand identity');

        // ss first, then gsc: the failing order before the fix.
        Tenancy::for($ss, fn () => null);
        $this->assertSame($gscEmail, Tenancy::for($gsc, fn () => config('brand.email')));

        // nested
        Tenancy::for($ss, function () use ($gsc, $gscEmail, $ssEmail) {
            $this->assertSame($ssEmail, config('brand.email'));
            Tenancy::for($gsc, fn () => $this->assertSame($gscEmail, config('brand.email')));
            $this->assertSame($ssEmail, config('brand.email'), 'outer context must be restored');
        });
    }

    public function test_each_binds_every_active_site(): void
    {
        $seen = Tenancy::each(fn (Site $s) => [$s->slug, config('brand.email')]);

        $this->assertGreaterThanOrEqual(2, count($seen));
        foreach ($seen as $slug => [$boundSlug, $email]) {
            $this->assertSame($slug, $boundSlug, 'Site::current() must match the iterated site');
            $this->assertNotEmpty($email);
        }

        // running twice must give identical results (no drift)
        $this->assertSame($seen, Tenancy::each(fn (Site $s) => [$s->slug, config('brand.email')]));
    }

    public function test_restore_returns_config_to_shared_defaults(): void
    {
        $base = config('brand.email');
        Tenancy::for(Site::where('slug', 'ss')->firstOrFail(), fn () => null);
        SiteConfig::restore();

        $this->assertSame($base, config('brand.email'));
    }
}
