<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\Project;
use App\Models\ProjectArea;
use App\Models\ProjectImage;
use App\Models\Site;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

/**
 * Project areas — adopted from jpeterson-design.com via ss-platform-kit
 * 0.16.0 (see that kit's docs/PROJECT-AREAS.md). Areas ride the existing
 * projects resource (PUT .../projects/{id} "areas", POST .../images
 * "area_id", the "areas"/"images[].area_id" keys on every payload) plus the
 * routes of their own in routes/api-admin/project-areas.php.
 */
class ProjectAreasTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
        Storage::fake('public');
    }

    private function project(array $extra = []): Project
    {
        return Project::create(['title' => 'Mount Prospect Home Remodel', 'project_type' => 'home-remodel', 'location' => 'Mount Prospect, IL', 'is_published' => true] + $extra);
    }

    private function photo(Project $project, string $name, ?ProjectArea $area = null): ProjectImage
    {
        $path = "projects/{$project->id}/{$name}.jpg";
        Storage::disk('public')->put($path, "original {$name}");

        return ProjectImage::create([
            'project_id' => $project->id,
            'project_area_id' => $area?->id,
            'filename' => "{$name}.jpg", 'original_filename' => "{$name}.jpg", 'path' => $path,
            'mime_type' => 'image/jpeg', 'size' => 1, 'sort_order' => 1,
        ]);
    }

    public function test_asking_for_a_merged_away_project_answers_where_it_went(): void
    {
        $target = Project::create(['title' => 'Mount Prospect Home Remodel', 'project_type' => 'kitchen', 'location' => 'Mount Prospect, IL', 'is_published' => true]);
        $source = Project::create(['title' => 'Mount Prospect Mudroom', 'project_type' => 'mudroom', 'location' => 'Mount Prospect, IL', 'is_published' => true]);
        $sourceId = $source->id;

        $this->artisan('projects:merge', ['target' => $target->id, 'sources' => [$sourceId]])->assertSuccessful();

        $this->getJson("/api/admin/v1/projects/{$sourceId}", $this->adminApiHeaders())
            ->assertStatus(409)
            ->assertJsonPath('moved_to.id', $target->id)
            ->assertJsonPath('moved_to.title', $target->title)
            ->assertJsonPath('moved_to.anchor', 'area-mudroom');

        // A project that never existed is still a plain 404.
        $this->getJson('/api/admin/v1/projects/999999', $this->adminApiHeaders())->assertNotFound();
    }

    public function test_a_new_area_is_created_holding_the_chosen_photos_and_nothing_else_changes(): void
    {
        $project = $this->project(['description' => 'Overview.']);
        $kitchen = ProjectArea::create(['project_id' => $project->id, 'project_type' => 'kitchen', 'description' => 'Kitchen words.', 'sort_order' => 0]);
        $a = $this->photo($project, 'a', $kitchen);
        $b = $this->photo($project, 'b');
        $c = $this->photo($project, 'c');
        $kitchen->update(['cover_image_id' => $a->id]);

        $response = $this->postJson("/api/admin/v1/projects/{$project->id}/areas", [
            'project_type' => 'bathroom', 'title' => 'Powder room', 'image_ids' => [$a->id, $b->id],
        ], $this->adminApiHeaders())->assertCreated();

        $new = ProjectArea::query()->where('project_id', $project->id)->where('project_type', 'bathroom')->sole();
        $this->assertSame('Powder room', $new->title);
        $this->assertSame(1, $new->sort_order);
        $this->assertSame([$a->id, $b->id], $new->images()->orderBy('id')->pluck('id')->all());
        $this->assertNull($c->fresh()->project_area_id);
        $this->assertNull($kitchen->fresh()->cover_image_id, 'its cover moved to the new area');
        $this->assertSame('Kitchen words.', $kitchen->fresh()->description);
        $this->assertSame('Overview.', $project->fresh()->description);
        $response->assertJsonPath('data.areas.1.project_type', 'bathroom');
    }

    public function test_a_new_area_refuses_an_unknown_category_or_another_projects_photo(): void
    {
        $project = $this->project();
        $other = $this->project(['title' => 'Other Project']);
        $foreign = $this->photo($other, 'x');

        $this->postJson("/api/admin/v1/projects/{$project->id}/areas", ['project_type' => 'not-a-category'], $this->adminApiHeaders())
            ->assertUnprocessable()->assertJsonValidationErrors('project_type');
        $this->postJson("/api/admin/v1/projects/{$project->id}/areas", ['project_type' => 'kitchen', 'image_ids' => [$foreign->id]], $this->adminApiHeaders())
            ->assertUnprocessable()->assertJsonValidationErrors('image_ids.0');
        $this->assertSame(0, ProjectArea::query()->where('project_id', $project->id)->count());
    }

    public function test_areas_are_created_updated_and_deleted_through_the_project_update_payload(): void
    {
        $project = $this->project();

        $response = $this->putJson("/api/admin/v1/projects/{$project->id}", [
            'title' => $project->title, 'project_type' => $project->project_type,
            'areas' => [
                ['project_type' => 'kitchen', 'title' => null, 'description' => 'The kitchen write-up.'],
                ['project_type' => 'mudroom', 'title' => 'Mudroom', 'description' => 'The mudroom write-up.'],
            ],
        ], $this->adminApiHeaders())->assertOk();

        $areas = $response->json('data.areas');
        $this->assertCount(2, $areas);
        $this->assertSame('kitchen', $areas[0]['project_type']);
        $this->assertNull($areas[0]['title']);
        $this->assertSame('The kitchen write-up.', $areas[0]['description']);
        $this->assertSame(0, $areas[0]['image_count']);
        $this->assertNull($areas[0]['cover_url']);
        $this->assertSame('Mudroom', $areas[1]['title']);
        $this->assertSame(1, $areas[1]['sort_order']);

        $kitchenId = $areas[0]['id'];
        $mudroomId = $areas[1]['id'];

        // Update the kitchen area in place, drop mudroom, add basement.
        $response = $this->putJson("/api/admin/v1/projects/{$project->id}", [
            'title' => $project->title, 'project_type' => $project->project_type,
            'areas' => [
                ['id' => $kitchenId, 'project_type' => 'kitchen', 'title' => 'The Kitchen', 'description' => 'Updated.'],
                ['project_type' => 'basement', 'description' => 'The basement.'],
            ],
        ], $this->adminApiHeaders())->assertOk();

        $areas = $response->json('data.areas');
        $this->assertCount(2, $areas);
        $this->assertSame($kitchenId, $areas[0]['id']);
        $this->assertSame('The Kitchen', $areas[0]['title']);
        $this->assertSame('basement', $areas[1]['project_type']);
        $this->assertSame(0, ProjectArea::where('id', $mudroomId)->count(), 'the dropped area is gone');

        // Omitting "areas" entirely leaves the current set untouched.
        $this->putJson("/api/admin/v1/projects/{$project->id}", ['title' => $project->title, 'project_type' => $project->project_type], $this->adminApiHeaders())->assertOk();
        $this->assertSame(2, $project->areas()->count());
    }

    public function test_deleting_an_area_keeps_its_images_area_less_rather_than_deleting_them(): void
    {
        $project = $this->project();
        $area = ProjectArea::create(['project_id' => $project->id, 'project_type' => 'kitchen']);
        $image = $this->photo($project, 'a', $area);

        $this->putJson("/api/admin/v1/projects/{$project->id}", ['title' => $project->title, 'project_type' => $project->project_type, 'areas' => []], $this->adminApiHeaders())->assertOk();

        $this->assertSame(0, $project->areas()->count());
        $this->assertNull($image->fresh()->project_area_id);
        Storage::disk('public')->assertExists("projects/{$project->id}/a.jpg");
    }

    public function test_area_validation(): void
    {
        $project = $this->project();

        $this->putJson("/api/admin/v1/projects/{$project->id}", [
            'areas' => [['title' => 'No type given']],
        ], $this->adminApiHeaders())->assertStatus(422)->assertJsonValidationErrors(['areas.0.project_type']);

        $this->putJson("/api/admin/v1/projects/{$project->id}", [
            'areas' => [['project_type' => 'not-a-real-service-slug']],
        ], $this->adminApiHeaders())->assertStatus(422)->assertJsonValidationErrors(['areas.0.project_type']);

        $this->putJson("/api/admin/v1/projects/{$project->id}", [
            'areas' => [['project_type' => 'kitchen', 'description' => str_repeat('a', 10001)]],
        ], $this->adminApiHeaders())->assertStatus(422)->assertJsonValidationErrors(['areas.0.description']);

        $this->assertSame(0, $project->areas()->count(), 'nothing was written by any of the rejected payloads');
    }

    public function test_uploading_a_photo_straight_into_an_area(): void
    {
        $project = $this->project();
        $area = ProjectArea::create(['project_id' => $project->id, 'project_type' => 'kitchen']);

        $image = $this->postJson("/api/admin/v1/projects/{$project->id}/images", [
            'image' => UploadedFile::fake()->image('a.jpg', 800, 600),
            'area_id' => $area->id,
        ], $this->adminApiHeaders())->assertCreated()->json('data');

        $this->assertSame($area->id, $image['area_id']);

        $otherProject = $this->project(['title' => 'Other Project']);
        $otherArea = ProjectArea::create(['project_id' => $otherProject->id, 'project_type' => 'kitchen']);

        // area_id must be one of THIS project's own areas.
        $this->postJson("/api/admin/v1/projects/{$project->id}/images", [
            'image' => UploadedFile::fake()->image('b.jpg', 800, 600),
            'area_id' => $otherArea->id,
        ], $this->adminApiHeaders())->assertStatus(422)->assertJsonValidationErrors(['area_id']);
    }

    public function test_assigning_and_unassigning_images_to_an_area(): void
    {
        $project = $this->project();
        $area = ProjectArea::create(['project_id' => $project->id, 'project_type' => 'kitchen']);
        $a = $this->photo($project, 'a');
        $b = $this->photo($project, 'b');

        $response = $this->postJson("/api/admin/v1/projects/{$project->id}/images/area", [
            'image_ids' => [$a->id, $b->id],
            'area_id' => $area->id,
        ], $this->adminApiHeaders())->assertOk();

        $this->assertSame($area->id, $a->fresh()->project_area_id);
        $this->assertSame($area->id, $b->fresh()->project_area_id);
        $this->assertSame(2, collect($response->json('data.areas'))->firstWhere('id', $area->id)['image_count']);

        $this->postJson("/api/admin/v1/projects/{$project->id}/images/area", [
            'image_ids' => [$a->id],
            'area_id' => null,
        ], $this->adminApiHeaders())->assertOk();

        $this->assertNull($a->fresh()->project_area_id, 'null puts it back to belonging to the project as a whole');
        $this->assertSame($area->id, $b->fresh()->project_area_id);

        $other = $this->project(['title' => 'Other Project']);
        $theirs = $this->photo($other, 'c');

        $this->postJson("/api/admin/v1/projects/{$project->id}/images/area", [
            'image_ids' => [$theirs->id],
            'area_id' => $area->id,
        ], $this->adminApiHeaders())->assertStatus(422)->assertJsonValidationErrors(['image_ids']);
    }

    public function test_moving_a_photo_to_another_project_clears_its_area(): void
    {
        $project = $this->project();
        $target = $this->project(['title' => 'Target Project']);
        $area = ProjectArea::create(['project_id' => $project->id, 'project_type' => 'kitchen']);
        $image = $this->photo($project, 'a', $area);

        $this->postJson("/api/admin/v1/projects/{$project->id}/images/move", [
            'image_ids' => [$image->id],
            'project_id' => $target->id,
        ], $this->adminApiHeaders())->assertOk();

        $this->assertSame($target->id, $image->fresh()->project_id);
        $this->assertNull($image->fresh()->project_area_id);
    }

    public function test_ping_declares_the_capability(): void
    {
        $this->getJson('/api/admin/v1/ping', $this->adminApiHeaders())->assertOk()
            ->assertJsonFragment(['project-areas']);
    }

    // --- 2026-10-01 addendum: area covers -----------------------------------

    public function test_area_payload_includes_cover_image_id_and_details(): void
    {
        $project = $this->project();
        $area = ProjectArea::create(['project_id' => $project->id, 'project_type' => 'kitchen']);

        $data = $this->getJson("/api/admin/v1/projects/{$project->id}", $this->adminApiHeaders())
            ->assertOk()->json('data');

        $this->assertSame(['id' => $area->id, 'cover_image_id' => null], [
            'id' => $data['areas'][0]['id'],
            'cover_image_id' => $data['areas'][0]['cover_image_id'],
        ]);
        $this->assertSame(['generating' => false, 'error' => null], $data['areas'][0]['details']);
    }

    public function test_setting_and_clearing_an_areas_own_cover(): void
    {
        $project = $this->project();
        $area = ProjectArea::create(['project_id' => $project->id, 'project_type' => 'kitchen']);
        $this->photo($project, 'a', $area);
        $b = $this->photo($project, 'b', $area);

        $response = $this->putJson("/api/admin/v1/projects/{$project->id}/areas/{$area->id}/cover", [
            'image_id' => $b->id,
        ], $this->adminApiHeaders())->assertOk();

        $this->assertSame($b->id, $area->fresh()->cover_image_id);
        $areaData = collect($response->json('data.areas'))->firstWhere('id', $area->id);
        $this->assertSame($b->id, $areaData['cover_image_id']);
        $this->assertSame($b->url, $areaData['cover_url']);

        // null clears back to the automatic choice (is_cover, else first).
        $this->putJson("/api/admin/v1/projects/{$project->id}/areas/{$area->id}/cover", [
            'image_id' => null,
        ], $this->adminApiHeaders())->assertOk()->assertJsonPath('data.areas.0.cover_image_id', null);

        $this->assertNull($area->fresh()->cover_image_id);
    }

    public function test_setting_an_areas_cover_to_a_foreign_image_is_rejected(): void
    {
        $project = $this->project();
        $area = ProjectArea::create(['project_id' => $project->id, 'project_type' => 'kitchen']);

        // Belongs to the same project, but not to this area.
        $wholeProjectPhoto = $this->photo($project, 'whole');

        $otherProject = $this->project(['title' => 'Other Project']);
        $otherArea = ProjectArea::create(['project_id' => $otherProject->id, 'project_type' => 'kitchen']);
        $otherPhoto = $this->photo($otherProject, 'other', $otherArea);

        foreach ([$wholeProjectPhoto, $otherPhoto] as $foreign) {
            $this->putJson("/api/admin/v1/projects/{$project->id}/areas/{$area->id}/cover", [
                'image_id' => $foreign->id,
            ], $this->adminApiHeaders())->assertStatus(422)->assertJsonValidationErrors(['image_id']);
        }

        $this->assertNull($area->fresh()->cover_image_id);
    }

    public function test_reassigning_the_cover_photo_to_another_area_clears_it(): void
    {
        $project = $this->project();
        $area = ProjectArea::create(['project_id' => $project->id, 'project_type' => 'kitchen']);
        $otherArea = ProjectArea::create(['project_id' => $project->id, 'project_type' => 'bathroom']);
        $cover = $this->photo($project, 'cover', $area);

        $area->update(['cover_image_id' => $cover->id]);

        $this->postJson("/api/admin/v1/projects/{$project->id}/images/area", [
            'image_ids' => [$cover->id],
            'area_id' => $otherArea->id,
        ], $this->adminApiHeaders())->assertOk();

        $this->assertNull($area->fresh()->cover_image_id, 'the old area loses its chosen cover once the photo leaves it');
        $this->assertSame($otherArea->id, $cover->fresh()->project_area_id);
    }

    public function test_unassigning_the_cover_photo_back_to_the_whole_project_clears_it(): void
    {
        $project = $this->project();
        $area = ProjectArea::create(['project_id' => $project->id, 'project_type' => 'kitchen']);
        $cover = $this->photo($project, 'cover', $area);
        $area->update(['cover_image_id' => $cover->id]);

        $this->postJson("/api/admin/v1/projects/{$project->id}/images/area", [
            'image_ids' => [$cover->id],
            'area_id' => null,
        ], $this->adminApiHeaders())->assertOk();

        $this->assertNull($area->fresh()->cover_image_id);
        $this->assertNull($cover->fresh()->project_area_id);
    }

    public function test_moving_the_cover_photo_to_another_project_clears_it(): void
    {
        $project = $this->project();
        $target = $this->project(['title' => 'Target Project']);
        $area = ProjectArea::create(['project_id' => $project->id, 'project_type' => 'kitchen']);
        $cover = $this->photo($project, 'cover', $area);
        $area->update(['cover_image_id' => $cover->id]);

        $this->postJson("/api/admin/v1/projects/{$project->id}/images/move", [
            'image_ids' => [$cover->id],
            'project_id' => $target->id,
        ], $this->adminApiHeaders())->assertOk();

        $this->assertNull($area->fresh()->cover_image_id);
        $this->assertSame($target->id, $cover->fresh()->project_id);
    }

    public function test_deleting_the_cover_photo_clears_the_areas_choice(): void
    {
        $project = $this->project();
        $area = ProjectArea::create(['project_id' => $project->id, 'project_type' => 'kitchen']);
        $cover = $this->photo($project, 'cover', $area);
        $area->update(['cover_image_id' => $cover->id]);

        $this->deleteJson("/api/admin/v1/projects/{$project->id}/images/{$cover->id}", [], $this->adminApiHeaders())
            ->assertNoContent();

        $this->assertNull($area->fresh()->cover_image_id, 'the FK is null-on-delete, not cascade');
    }

    // --- Cross-tenant isolation ----------------------------------------------

    /**
     * ProjectArea has no site_id of its own (reached only through a scoped
     * Project — see that model's docblock); PinAdminApiTenant hardcodes this
     * whole API to the 'gsc' tenant (see that middleware's docblock), so a
     * project/area belonging to ANY other site must be completely invisible
     * through every one of these endpoints — not just omitted from a list,
     * but a plain 404, through the same Project::findOrFail() every one of
     * them starts with.
     */
    public function test_a_project_and_area_belonging_to_another_site_are_404_through_every_area_endpoint(): void
    {
        $other = Site::query()->firstOrCreate(['slug' => 'jpeterson'], [
            'name' => 'J. Peterson Design', 'theme' => 'jpeterson', 'hosts' => ['jpeterson-design.com'], 'primary_host' => 'jpeterson-design.com',
        ]);
        $other->forceFill(['is_active' => true])->save();

        [$theirProjectId, $theirAreaId, $theirImageId] = Tenancy::for($other->fresh(), function () {
            $project = Project::create(['title' => 'Their Project', 'project_type' => 'kitchen', 'is_published' => true]);
            $area = ProjectArea::create(['project_id' => $project->id, 'project_type' => 'kitchen']);
            $image = $this->photo($project, 'theirs', $area);

            return [$project->id, $area->id, $image->id];
        });

        Site::forgetActive();

        $headers = $this->adminApiHeaders();
        $this->getJson("/api/admin/v1/projects/{$theirProjectId}", $headers)->assertNotFound();
        $this->putJson("/api/admin/v1/projects/{$theirProjectId}", ['title' => 'Hijacked', 'project_type' => 'kitchen'], $headers)->assertNotFound();
        $this->postJson("/api/admin/v1/projects/{$theirProjectId}/areas", ['project_type' => 'bathroom'], $headers)->assertNotFound();
        $this->putJson("/api/admin/v1/projects/{$theirProjectId}/areas/{$theirAreaId}/cover", ['image_id' => $theirImageId], $headers)->assertNotFound();
        $this->postJson("/api/admin/v1/projects/{$theirProjectId}/areas/{$theirAreaId}/generate-description", [], $headers)->assertNotFound();
        $this->postJson("/api/admin/v1/projects/{$theirProjectId}/images/area", ['image_ids' => [$theirImageId], 'area_id' => null], $headers)->assertNotFound();

        // The row is untouched — not just hidden from THIS request.
        $this->assertSame('Their Project', Project::withoutGlobalScopes()->find($theirProjectId)?->title);
    }
}
