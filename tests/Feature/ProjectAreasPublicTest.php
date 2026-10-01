<?php

namespace Tests\Feature;

use App\Livewire\ProjectsGrid;
use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectCollaborator;
use App\Models\ProjectImage;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use SsSystems\Platform\Projects\CollaboratorLinks;
use Tests\TestCase;

/**
 * Project areas — the public-site half of the feature adopted from
 * jpeterson-design.com via ss-platform-kit 0.16.0: a project belongs to a
 * category through its own project_type OR any of its areas' (Project::
 * scopeOfType()/belongsToType()), a card listed under a category it
 * reaches only through an area presents as that area (Project::
 * presentFor()), and the project page gets one section per area.
 */
class ProjectAreasPublicTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function image(Project $project, string $name, array $extra = []): ProjectImage
    {
        $path = "projects/{$project->id}/{$name}.jpg";
        Storage::disk('public')->put($path, "original {$name}");

        return ProjectImage::create([
            'project_id' => $project->id,
            'filename' => "{$name}.jpg", 'original_filename' => "{$name}.jpg", 'path' => $path,
            'mime_type' => 'image/jpeg', 'size' => 1, 'sort_order' => 1,
        ] + $extra);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_of_type_and_belongs_to_type_include_a_project_that_reaches_a_category_only_through_an_area(): void
    {
        $project = Project::create(['title' => 'Mount Prospect Home Remodel', 'slug' => 'mount-prospect-home-remodel', 'project_type' => 'home-remodel', 'is_published' => true]);
        ProjectArea::create(['project_id' => $project->id, 'project_type' => 'kitchen', 'sort_order' => 0]);

        $this->assertTrue(Project::ofType('kitchen')->whereKey($project->id)->exists());
        $this->assertTrue(Project::ofType('home-remodel')->whereKey($project->id)->exists());
        $this->assertFalse(Project::ofType('bathroom')->whereKey($project->id)->exists());

        $project->load('areas');
        $this->assertSame(['kitchen', 'home-remodel'], $project->types());
        $this->assertTrue($project->belongsToType('kitchen'));
        $this->assertTrue($project->belongsToType('home-remodel'));
        $this->assertFalse($project->belongsToType('bathroom'));
    }

    public function test_a_single_category_project_with_no_areas_presents_as_itself_everywhere(): void
    {
        $project = Project::create(['title' => 'Arlington Heights Kitchen', 'slug' => 'arlington-heights-kitchen', 'project_type' => 'kitchen', 'is_published' => true]);
        $this->image($project, 'a', ['is_cover' => true]);

        $this->get('/projects')->assertOk()
            ->assertSee('href="'.$project->url().'"', false)
            ->assertSee($project->title);

        Livewire::test(ProjectsGrid::class, ['projectType' => 'kitchen'])
            ->assertSee($project->title)
            ->assertSee('href="'.$project->url().'"', false);
    }

    public function test_projects_grid_shows_a_project_under_an_areas_type_with_the_areas_own_cover_and_an_anchor_link(): void
    {
        $project = Project::create(['title' => 'Mount Prospect Home Remodel', 'slug' => 'mount-prospect-home-remodel', 'project_type' => 'home-remodel', 'is_published' => true]);
        $area = ProjectArea::create(['project_id' => $project->id, 'project_type' => 'kitchen', 'title' => null, 'sort_order' => 0]);
        $areaImage = $this->image($project, 'kitchen', ['project_area_id' => $area->id, 'is_cover' => true]);
        // The project's OWN cover (area-less) must never leak in as the area's.
        $wholeImage = $this->image($project, 'whole', ['is_cover' => true, 'sort_order' => 0]);

        Livewire::test(ProjectsGrid::class, ['projectType' => 'kitchen'])
            ->assertSee('Mount Prospect Home Remodel — Kitchen Remodel')
            ->assertSee('href="'.$project->url().'#area-kitchen"', false)
            ->assertSee($areaImage->url, false)
            ->assertDontSee($wholeImage->url, false);
    }

    public function test_the_unfiltered_projects_page_always_shows_the_whole_project_even_when_it_has_areas(): void
    {
        $project = Project::create(['title' => 'Mount Prospect Home Remodel', 'slug' => 'mount-prospect-home-remodel', 'project_type' => 'home-remodel', 'is_published' => true]);
        $area = ProjectArea::create(['project_id' => $project->id, 'project_type' => 'kitchen', 'sort_order' => 0]);
        $this->image($project, 'kitchen', ['project_area_id' => $area->id]);
        $whole = $this->image($project, 'whole', ['is_cover' => true]);

        Livewire::test(ProjectsGrid::class)
            ->assertSee('Mount Prospect Home Remodel')
            ->assertDontSee('Mount Prospect Home Remodel — Kitchen Remodel')
            ->assertSee('href="'.$project->url().'"', false)
            ->assertDontSee('href="'.$project->url().'#area-kitchen"', false)
            ->assertSee($whole->url, false);
    }

    /** 2026-10-01 addendum: the admin-chosen cover beats is_cover/first everywhere the area presents. */
    public function test_projects_grid_uses_the_areas_chosen_cover_over_is_cover_or_first(): void
    {
        $project = Project::create(['title' => 'Mount Prospect Home Remodel', 'slug' => 'mount-prospect-home-remodel', 'project_type' => 'home-remodel', 'is_published' => true]);
        $area = ProjectArea::create(['project_id' => $project->id, 'project_type' => 'kitchen', 'sort_order' => 0]);
        $this->image($project, 'first', ['project_area_id' => $area->id, 'is_cover' => true, 'sort_order' => 0]);
        $chosen = $this->image($project, 'chosen', ['project_area_id' => $area->id, 'sort_order' => 1]);
        $area->update(['cover_image_id' => $chosen->id]);

        Livewire::test(ProjectsGrid::class, ['projectType' => 'kitchen'])->assertSee($chosen->url, false);

        $this->assertSame($chosen->id, $project->fresh(['areas.images'])->areas->first()->cover()->id);
    }

    /**
     * ProjectImage::curatedCovers() — every slider and OG/share image pool
     * — must never answer a category request with a sibling area's photo,
     * only that area's own cover (or, for the project's own direct type,
     * the project's own designated cover).
     */
    public function test_curated_covers_never_returns_a_sibling_areas_photo(): void
    {
        $project = Project::create(['title' => 'Mount Prospect Home Remodel', 'slug' => 'mount-prospect-home-remodel', 'project_type' => 'home-remodel', 'is_published' => true, 'is_featured' => true]);
        $kitchen = ProjectArea::create(['project_id' => $project->id, 'project_type' => 'kitchen', 'sort_order' => 0]);
        $bathroom = ProjectArea::create(['project_id' => $project->id, 'project_type' => 'bathroom', 'sort_order' => 1]);
        $whole = $this->image($project, 'whole', ['is_cover' => true]);
        $kitchenPhoto = $this->image($project, 'kitchen', ['project_area_id' => $kitchen->id]);
        $bathroomPhoto = $this->image($project, 'bath', ['project_area_id' => $bathroom->id]);
        $kitchen->update(['cover_image_id' => $kitchenPhoto->id]);
        $bathroom->update(['cover_image_id' => $bathroomPhoto->id]);

        $homeRemodel = ProjectImage::curatedCovers('home-remodel', 12);
        $this->assertCount(1, $homeRemodel);
        $this->assertSame($whole->id, $homeRemodel->first()->id);

        $kitchenCovers = ProjectImage::curatedCovers('kitchen', 12);
        $this->assertCount(1, $kitchenCovers);
        $this->assertSame($kitchenPhoto->id, $kitchenCovers->first()->id);
        $this->assertFalse($kitchenCovers->contains('id', $bathroomPhoto->id), "never a sibling area's photo");

        $bathroomCovers = ProjectImage::curatedCovers('bathroom', 12);
        $this->assertCount(1, $bathroomCovers);
        $this->assertSame($bathroomPhoto->id, $bathroomCovers->first()->id);
        $this->assertFalse($bathroomCovers->contains('id', $kitchenPhoto->id), "never a sibling area's photo");
    }

    public function test_the_project_page_renders_one_section_per_area_in_order_with_a_stable_anchor(): void
    {
        $project = Project::create([
            'title' => 'Mount Prospect Home Remodel', 'slug' => 'mount-prospect-home-remodel', 'project_type' => 'home-remodel',
            'is_published' => true, 'description' => 'The whole-home overview.',
        ]);
        $this->image($project, 'whole', ['is_cover' => true]);
        $kitchen = ProjectArea::create(['project_id' => $project->id, 'project_type' => 'kitchen', 'title' => null, 'description' => 'The kitchen got new cabinetry.', 'sort_order' => 0]);
        $this->image($project, 'kitchen', ['project_area_id' => $kitchen->id]);
        $mudroom = ProjectArea::create(['project_id' => $project->id, 'project_type' => 'mudroom', 'title' => 'Mudroom', 'description' => 'Opened up the back hall.', 'sort_order' => 1]);
        $this->image($project, 'mudroom', ['project_area_id' => $mudroom->id]);

        $html = $this->get('/projects/mount-prospect-home-remodel')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<h1'), 'one H1 for the whole page');
        $this->assertStringContainsString('The whole-home overview.', $html);
        $this->assertStringContainsString('id="area-kitchen"', $html);
        $this->assertStringContainsString('id="area-mudroom"', $html);
        $this->assertStringContainsString('Kitchen Remodel', $html, 'falls back to the type label when no title was set');
        $this->assertStringContainsString('Mudroom', $html, 'the admin-entered title wins when there is one');
        $this->assertStringContainsString('The kitchen got new cabinetry.', $html);
        $this->assertStringContainsString('Opened up the back hall.', $html);
        $this->assertStringContainsString('"@type": "CreativeWork"', $html);

        $kitchenPos = strpos($html, 'id="area-kitchen"');
        $mudroomPos = strpos($html, 'id="area-mudroom"');
        $this->assertNotFalse($kitchenPos);
        $this->assertLessThan($mudroomPos, $kitchenPos, 'areas render in sort_order');
    }

    public function test_an_areas_chosen_cover_leads_its_gallery_on_the_project_page(): void
    {
        $project = Project::create(['title' => 'Mount Prospect Home Remodel', 'slug' => 'mount-prospect-home-remodel', 'project_type' => 'home-remodel', 'is_published' => true]);
        $kitchen = ProjectArea::create(['project_id' => $project->id, 'project_type' => 'kitchen', 'sort_order' => 0]);
        $first = $this->image($project, 'kitchen-first', ['project_area_id' => $kitchen->id, 'sort_order' => 1]);
        $chosen = $this->image($project, 'kitchen-chosen', ['project_area_id' => $kitchen->id, 'sort_order' => 2]);
        $kitchen->update(['cover_image_id' => $chosen->id]);

        $html = $this->get('/projects/mount-prospect-home-remodel')->assertOk()->getContent();
        $section = substr($html, strpos($html, 'id="area-kitchen"'));

        $this->assertLessThan(strpos($section, 'kitchen-first'), strpos($section, 'kitchen-chosen'));
        $this->assertNotNull($first->id);
    }

    public function test_collaborators_named_in_the_text_are_linked(): void
    {
        $project = Project::create([
            'title' => 'Mount Prospect Home', 'slug' => 'mount-prospect-home', 'project_type' => 'home-remodel', 'is_published' => true,
            'description' => 'Jane Doe Architecture worked with GS Construction on this project.',
        ]);
        ProjectCollaborator::create(['project_id' => $project->id, 'role' => 'architects', 'name' => 'Jane Doe Architecture', 'url' => 'https://janedoearch.test', 'sort_order' => 0]);
        ProjectCollaborator::create(['project_id' => $project->id, 'role' => 'other', 'name' => 'No Site Co', 'url' => null, 'sort_order' => 1]);
        $kitchen = ProjectArea::create(['project_id' => $project->id, 'project_type' => 'kitchen', 'description' => 'Cabinets built with Jane Doe Architecture.', 'sort_order' => 0]);

        $html = $this->get('/projects/mount-prospect-home')->assertOk()->getContent();

        $this->assertStringContainsString('<a href="https://janedoearch.test" target="_blank" rel="noopener"', $html);
        $this->assertStringContainsString('Jane Doe Architecture</a> worked with', $html);
        $this->assertStringContainsString('Cabinets built with <a href="https://janedoearch.test"', $html);
        $this->assertNotNull($kitchen->id);
    }

    public function test_a_linked_name_never_lets_markup_or_scripts_through(): void
    {
        $html = (string) CollaboratorLinks::linkify('<b>Acme</b> & Acme', [
            new ProjectCollaborator(['name' => 'Acme', 'url' => 'https://acme.test']),
            new ProjectCollaborator(['name' => 'Evil', 'url' => 'javascript:alert(1)']),
        ]);

        $this->assertStringContainsString('&lt;b&gt;', $html);
        $this->assertSame(2, substr_count($html, 'href="https://acme.test"'));
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_renaming_a_projects_address_keeps_the_old_one_working(): void
    {
        $project = Project::create(['title' => 'Old Name', 'slug' => 'old-name-for-this-test', 'project_type' => 'kitchen', 'is_published' => true]);

        $project->update(['slug' => 'new-name-for-this-test']);

        $this->get('/projects/old-name-for-this-test')->assertRedirect('/projects/new-name-for-this-test')->assertStatus(301);
        $this->get('/projects/new-name-for-this-test')->assertOk();
    }
}
