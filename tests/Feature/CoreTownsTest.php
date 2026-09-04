<?php

namespace Tests\Feature;

use App\Models\AreaServed;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** The core towns are the ones with the most completed projects — nothing pinned. */
class CoreTownsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_core_towns_follow_project_counts_and_skip_towns_without_projects(): void
    {
        Cache::flush();
        foreach (['Palatine', 'Arlington Heights', 'Inverness', 'Kenilworth', 'Barrington'] as $c) {
            AreaServed::create(['city' => $c, 'slug' => \Illuminate\Support\Str::slug($c)]);
        }
        $make = fn (string $loc, int $n) => collect(range(1, $n))->each(fn ($i) => Project::create(['title' => "$loc $i", 'project_type' => 'kitchen', 'location' => "$loc, IL", 'is_published' => true]));
        $make('Palatine', 3);
        $make('Arlington Heights', 2);
        $make('Inverness', 1);
        Project::create(['title' => 'Draft', 'project_type' => 'kitchen', 'location' => 'Kenilworth, IL', 'is_published' => false]);

        $this->assertSame(['Palatine', 'Arlington Heights', 'Inverness'], AreaServed::coreTowns(6), 'towns without published projects are not core');
        $this->assertSame(['Palatine', 'Arlington Heights'], AreaServed::coreTowns(2));
        $this->assertSame([], config('nav.footer.priority_areas'), 'nothing is pinned in the footer');
    }
}
