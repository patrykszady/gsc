<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\Site;
use App\Models\Testimonial;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * TestimonialController is about to adopt the kit's
 * SsSystems\Platform\Reviews\Http\Concerns\ServesTestimonials, which
 * resolves the model through a `testimonialModel(): string` hook
 * (`$modelClass::query()`) instead of the `Testimonial::query()` calls it
 * replaces. Functionally identical, but this is exactly the seam
 * RULES.md's tenancy guard asks to prove BEFORE the port: tenant safety
 * rides entirely on Testimonial's own BelongsToSite global scope, not on
 * anything the kit trait does, so a two-Site isolation test at the model
 * layer covers the trait's index()/show()/destroy() just as well as an
 * HTTP-level one would — and the real admin API route pins every request
 * to the 'gsc' tenant (see PinAdminApiTenant), so an HTTP-level cross-
 * tenant test can't even be expressed against this route. Same pattern as
 * Seo\CoverageStoreTenantIsolationTest.
 */
class TestimonialTenantIsolationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function otherSite(): Site
    {
        $site = Site::query()->firstOrCreate(['slug' => 'jpeterson'], [
            'name' => 'J. Peterson Design', 'theme' => 'jpeterson', 'hosts' => ['jpeterson-design.com'], 'primary_host' => 'jpeterson-design.com',
        ]);
        $site->forceFill(['is_active' => true, 'hosts' => ['jpeterson-design.com'], 'primary_host' => 'jpeterson-design.com'])->save();
        Site::forgetActive();

        return $site->fresh();
    }

    public function test_one_tenants_testimonials_are_invisible_to_the_other(): void
    {
        $other = $this->otherSite();

        $mine = Testimonial::create(['reviewer_name' => 'Jane Doe', 'review_description' => 'Great work on our kitchen.']);

        Tenancy::for($other, function () use ($mine, $other) {
            $this->assertSame(0, Testimonial::query()->count(), 'another tenant sees no rows at all');
            $this->assertNull(Testimonial::find($mine->id));

            $theirs = Testimonial::create(['reviewer_name' => 'John Smith', 'review_description' => 'Loved our new deck.']);
            $this->assertSame(1, Testimonial::query()->count());
            $this->assertSame($other->id, $theirs->site_id);
        });

        // Back on the default tenant: the other site's write did not leak here either.
        $this->assertSame(1, Testimonial::query()->count());
        $this->assertNotNull(Testimonial::find($mine->id));
        $this->assertSame('Jane Doe', Testimonial::find($mine->id)->reviewer_name);

        // The raw table proves both rows really exist, correctly stamped,
        // and simply invisible to each other through Eloquent.
        $this->assertSame(2, Testimonial::withoutSiteScope()->count());
        $this->assertSame(
            $this->siteId('gsc'),
            Testimonial::withoutSiteScope()->where('reviewer_name', 'Jane Doe')->value('site_id')
        );
        $this->assertSame(
            $other->id,
            Testimonial::withoutSiteScope()->where('reviewer_name', 'John Smith')->value('site_id')
        );
    }

    /**
     * findOrFail() (what show()/destroy() actually call, through the kit
     * trait) must 404 on another tenant's row, not just omit it from a list.
     */
    public function test_findorfail_cannot_reach_another_tenants_row(): void
    {
        $other = $this->otherSite();
        $mine = Testimonial::create(['reviewer_name' => 'Jane Doe', 'review_description' => 'Great work.']);

        Tenancy::for($other, function () use ($mine) {
            $this->expectException(ModelNotFoundException::class);
            Testimonial::findOrFail($mine->id);
        });
    }

    private function siteId(string $slug): int
    {
        return Site::query()->where('slug', $slug)->value('id')
            ?? Site::current()->id;
    }
}
