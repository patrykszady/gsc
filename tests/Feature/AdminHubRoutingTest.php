<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class AdminHubRoutingTest extends TestCase
{
    public function test_admin_routes_are_site_scoped(): void
    {
        $user = User::first();
        $this->assertNotNull($user, 'no user to authenticate as');

        $cases = [
            ['/admin', [200, 302]],
            ['/admin/gs.construction', [200]],
            ['/admin/gs.construction/projects', [200]],
            ['/admin/gs.construction/autopilot', [200]],
            ['/admin/projects', [301]],
            ['/admin/jpeterson-design.com', [200]],
            ['/admin/nope.example', [404]],
        ];

        foreach ($cases as [$url, $expected]) {
            $r = $this->actingAs($user)->get($url);
            fwrite(STDERR, sprintf("  %-40s %s %s\n", $url, $r->status(), $r->headers->get('Location') ?? ''));
            $this->assertContains($r->status(), $expected, "unexpected status for {$url}");
        }
    }

    public function test_admin_urls_generate_with_site_segment(): void
    {
        $user = User::first();
        $this->actingAs($user)->get('/admin/gs.construction/projects');
        $url = route('admin.projects.index', [], false);
        fwrite(STDERR, "  route('admin.projects.index') = {$url}\n");
        $this->assertStringContainsString('gs.construction', $url);
    }
}
