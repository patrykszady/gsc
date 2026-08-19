<?php

namespace Tests\Feature;

use App\Models\Site;
use App\Models\User;
use Tests\TestCase;

class AdminSitePersistenceTest extends TestCase
{
    /** The site segment must survive a Livewire update, not just first paint. */
    public function test_site_context_resolves_from_path_when_route_param_absent(): void
    {
        $mw = new \App\Http\Middleware\ResolveAdminSite();
        $ref = new \ReflectionMethod($mw, 'keyFromPath');
        $ref->setAccessible(true);

        $cases = [
            ['admin/gs.construction/projects', 'gs.construction'],
            ['admin/jpeterson-design.com', 'jpeterson-design.com'],
            ['livewire/update', null],   // path alone gives nothing
        ];

        foreach ($cases as [$path, $expected]) {
            $req = \Illuminate\Http\Request::create('/' . $path, 'GET');
            $this->assertSame($expected, $ref->invoke($mw, $req), "path: {$path}");
        }

        // Livewire POST: recoverable from the Referer of the originating page
        $req = \Illuminate\Http\Request::create('/livewire/update', 'POST');
        $req->headers->set('Referer', 'https://ss.systems/admin-legacy/jpeterson-design.com/projects');
        $this->assertSame('jpeterson-design.com', $ref->invoke($mw, $req));
    }

    public function test_admin_pages_bind_the_site_from_the_url(): void
    {
        // Provision rather than assume: the suite runs on a fresh
        // in-memory DB, so there is no pre-existing user to find.
        $user = User::factory()->create();

        foreach ([['gs.construction', 'gsc'], ['jpeterson-design.com', 'jpeterson']] as [$host, $slug]) {
            $this->actingAs($user)->get("/admin-legacy/{$host}/projects")->assertOk();
            $this->assertSame($slug, Site::current()->slug, "admin/{$host} must bind {$slug}");
        }
    }

    public function test_persistent_middleware_is_registered(): void
    {
        $persistent = (new \ReflectionClass(\Livewire\Mechanisms\HandleRequests\HandleRequests::class));
        // Registration lives on the Livewire manager; assert via the public API surface.
        $this->assertTrue(
            in_array(\App\Http\Middleware\ResolveAdminSite::class, \Livewire\Livewire::getPersistentMiddleware(), true),
            'ResolveAdminSite must be persistent or admin actions run against the default site'
        );
    }
}
