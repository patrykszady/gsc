<?php

namespace Tests\Feature\Api\Admin\V1;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

/**
 * Coverage for the ProjectForm parity gaps: project/tag type vocabularies,
 * gsc-only CRM fields on Project, bulk image tag sync, and the new
 * Timelapse/BeforeAfter management-API surface (routes/api-admin/
 * projects-ext.php + TimelapseController/BeforeAfterController).
 *
 * Every project/tag used here is created THROUGH the API in the test body
 * rather than via Eloquent factories (none exist for Project/Tag) — that
 * also guarantees the row is stamped with the same tenant PinAdminApiTenant
 * pins for every other request in the test.
 */
class ProjectsExtControllerTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
        Storage::fake('public');
    }

    protected function createProject(array $overrides = []): array
    {
        return $this->postJson('/api/admin/v1/projects', array_merge([
            'title' => 'Kitchen Remodel Test',
            'project_type' => 'kitchen',
        ], $overrides), $this->adminApiHeaders())
            ->assertCreated()
            ->json('data');
    }

    // -- Types -----------------------------------------------------------

    public function test_types_endpoint_returns_project_and_tag_vocabularies(): void
    {
        $data = $this->getJson('/api/admin/v1/projects/types', $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('project_types', $data);
        $this->assertArrayHasKey('tag_types', $data);
        $this->assertSame('Kitchen Remodel', $data['project_types']['kitchen']);
        $this->assertSame('General', $data['tag_types']['general']);
    }

    // -- CRM fields --------------------------------------------------------

    public function test_project_store_and_show_expose_crm_fields(): void
    {
        $project = $this->createProject([
            'client_name' => 'Jane Homeowner',
            'client_email' => 'jane@example.com',
        ]);

        $this->assertSame('Jane Homeowner', $project['client_name']);
        $this->assertSame('jane@example.com', $project['client_email']);
        $this->assertNull($project['review_request_sent_at']);
        $this->assertNull($project['yelp_portfolio_url']);

        $shown = $this->getJson("/api/admin/v1/projects/{$project['id']}", $this->adminApiHeaders())
            ->assertOk()->json('data');

        $this->assertSame('Jane Homeowner', $shown['client_name']);
    }

    public function test_project_update_can_change_crm_fields(): void
    {
        $project = $this->createProject();

        $updated = $this->putJson("/api/admin/v1/projects/{$project['id']}", [
            'title' => $project['title'],
            'project_type' => $project['project_type'],
            'client_name' => 'Updated Client',
            'client_email' => 'updated@example.com',
            'yelp_portfolio_url' => 'https://biz.yelp.com/portfolio/gsc/1/edit',
        ], $this->adminApiHeaders())
            ->assertOk()
            ->json('data');

        $this->assertSame('Updated Client', $updated['client_name']);
        $this->assertSame('updated@example.com', $updated['client_email']);
        $this->assertSame('https://biz.yelp.com/portfolio/gsc/1/edit', $updated['yelp_portfolio_url']);
    }

    public function test_project_update_rejects_an_invalid_client_email(): void
    {
        $project = $this->createProject();

        $this->putJson("/api/admin/v1/projects/{$project['id']}", [
            'title' => $project['title'],
            'project_type' => $project['project_type'],
            'client_email' => 'not-an-email',
        ], $this->adminApiHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['client_email']);
    }

    // -- Tags: images_count + image tag sync --------------------------------

    public function test_tag_index_includes_images_count(): void
    {
        $tag = $this->postJson('/api/admin/v1/tags', ['name' => 'Marble', 'type' => 'material'], $this->adminApiHeaders())
            ->assertCreated()->json('data');

        $this->assertSame(0, $tag['images_count']);

        $listed = $this->getJson('/api/admin/v1/tags', $this->adminApiHeaders())->assertOk()->json('data');
        $found = collect($listed)->firstWhere('id', $tag['id']);
        $this->assertSame(0, $found['images_count']);
    }

    public function test_image_tags_can_be_synced_and_appear_on_the_project_payload(): void
    {
        $project = $this->createProject();

        $image = $this->postJson("/api/admin/v1/projects/{$project['id']}/images", [
            'image' => UploadedFile::fake()->image('kitchen.jpg', 800, 600),
        ], $this->adminApiHeaders())->assertCreated()->json('data');

        $this->assertSame([], $image['tags']);

        $tagA = $this->postJson('/api/admin/v1/tags', ['name' => 'Modern', 'type' => 'style'], $this->adminApiHeaders())->json('data');
        $tagB = $this->postJson('/api/admin/v1/tags', ['name' => 'Island', 'type' => 'feature'], $this->adminApiHeaders())->json('data');

        $synced = $this->putJson(
            "/api/admin/v1/projects/{$project['id']}/images/{$image['id']}/tags",
            ['tag_ids' => [$tagA['id'], $tagB['id']]],
            $this->adminApiHeaders(),
        )->assertOk()->json('data');

        $this->assertCount(2, $synced['tags']);
        $this->assertEqualsCanonicalizing(['Modern', 'Island'], array_column($synced['tags'], 'name'));

        // A subsequent sync fully replaces — dropping tagA leaves only tagB.
        $resynced = $this->putJson(
            "/api/admin/v1/projects/{$project['id']}/images/{$image['id']}/tags",
            ['tag_ids' => [$tagB['id']]],
            $this->adminApiHeaders(),
        )->assertOk()->json('data');

        $this->assertSame(['Island'], array_column($resynced['tags'], 'name'));

        // The tag's images_count reflects the sync.
        $tagShown = collect($this->getJson('/api/admin/v1/tags', $this->adminApiHeaders())->json('data'))
            ->firstWhere('id', $tagB['id']);
        $this->assertSame(1, $tagShown['images_count']);

        // And the image's tags travel embedded in the project payload too.
        $projectShown = $this->getJson("/api/admin/v1/projects/{$project['id']}", $this->adminApiHeaders())->json('data');
        $this->assertSame(['Island'], array_column($projectShown['images'][0]['tags'], 'name'));
    }

    // -- Timelapses --------------------------------------------------------

    public function test_timelapse_crud_and_frame_lifecycle(): void
    {
        $project = $this->createProject();

        $timelapse = $this->postJson("/api/admin/v1/projects/{$project['id']}/timelapses", [
            'title' => 'Demo to finish',
            'display_mode' => 'accordion',
        ], $this->adminApiHeaders())->assertCreated()->json('data');

        $this->assertSame('Demo to finish', $timelapse['title']);
        $this->assertSame('accordion', $timelapse['display_mode']);
        $this->assertSame([], $timelapse['frames']);

        // Upload a frame.
        $frame = $this->postJson(
            "/api/admin/v1/projects/{$project['id']}/timelapses/{$timelapse['id']}/frames",
            ['image' => UploadedFile::fake()->image('frame1.jpg', 400, 300)],
            $this->adminApiHeaders(),
        )->assertCreated()->json('data');

        $this->assertNotNull($frame['url']);
        Storage::disk('public')->assertExists('projects/'.$project['id'].'/timelapse/'.$timelapse['id'].'/'.$frame['filename']);

        // Pick a gallery image in as a second frame.
        $galleryImage = $this->postJson("/api/admin/v1/projects/{$project['id']}/images", [
            'image' => UploadedFile::fake()->image('gallery.jpg', 500, 400),
        ], $this->adminApiHeaders())->assertCreated()->json('data');

        $frame2 = $this->postJson(
            "/api/admin/v1/projects/{$project['id']}/timelapses/{$timelapse['id']}/frames/from-gallery",
            ['image_id' => $galleryImage['id']],
            $this->adminApiHeaders(),
        )->assertCreated()->json('data');

        $listed = $this->getJson("/api/admin/v1/projects/{$project['id']}/timelapses", $this->adminApiHeaders())
            ->assertOk()->json('data');
        $this->assertCount(2, $listed[0]['frames']);

        // Reorder: put frame2 first.
        $reordered = $this->postJson(
            "/api/admin/v1/projects/{$project['id']}/timelapses/{$timelapse['id']}/frames/reorder",
            ['order' => [$frame2['id'], $frame['id']]],
            $this->adminApiHeaders(),
        )->assertOk()->json('data');
        $this->assertSame($frame2['id'], $reordered[0]['id']);

        // Redaction editor save: a tiny valid PNG data URL re-encodes cleanly.
        $pngDataUrl = 'data:image/png;base64,'.base64_encode(
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=')
        );
        $edited = $this->putJson(
            "/api/admin/v1/projects/{$project['id']}/timelapses/{$timelapse['id']}/frames/{$frame['id']}",
            ['data_url' => $pngDataUrl],
            $this->adminApiHeaders(),
        )->assertOk()->json('data');
        $this->assertSame($frame['id'], $edited['id']);

        // Delete a frame.
        $this->deleteJson(
            "/api/admin/v1/projects/{$project['id']}/timelapses/{$timelapse['id']}/frames/{$frame2['id']}",
            [],
            $this->adminApiHeaders(),
        )->assertNoContent();

        $afterDelete = $this->getJson("/api/admin/v1/projects/{$project['id']}/timelapses", $this->adminApiHeaders())
            ->json('data');
        $this->assertCount(1, $afterDelete[0]['frames']);

        // Update timelapse title/display_mode.
        $this->putJson(
            "/api/admin/v1/projects/{$project['id']}/timelapses/{$timelapse['id']}",
            ['title' => 'Renamed', 'display_mode' => 'slider'],
            $this->adminApiHeaders(),
        )->assertOk()->assertJsonPath('data.title', 'Renamed');

        // Delete the timelapse (cascades its remaining frame).
        $this->deleteJson(
            "/api/admin/v1/projects/{$project['id']}/timelapses/{$timelapse['id']}",
            [],
            $this->adminApiHeaders(),
        )->assertNoContent();

        $this->assertSame([], $this->getJson("/api/admin/v1/projects/{$project['id']}/timelapses", $this->adminApiHeaders())->json('data'));
    }

    // -- Before/Afters -------------------------------------------------------

    public function test_before_after_crud_and_slot_filling(): void
    {
        $project = $this->createProject();

        $pair = $this->postJson("/api/admin/v1/projects/{$project['id']}/before-afters", [
            'title' => 'Kitchen transformation',
        ], $this->adminApiHeaders())->assertCreated()->json('data');

        $this->assertSame('Kitchen transformation', $pair['title']);
        $this->assertNull($pair['before_url']);
        $this->assertNull($pair['after_url']);

        $filledBefore = $this->postJson(
            "/api/admin/v1/projects/{$project['id']}/before-afters/{$pair['id']}/before",
            ['image' => UploadedFile::fake()->image('before.jpg', 600, 400)],
            $this->adminApiHeaders(),
        )->assertOk()->json('data');
        $this->assertNotNull($filledBefore['before_url']);
        $this->assertNull($filledBefore['after_url']);

        // Fill "after" from an existing gallery image.
        $galleryImage = $this->postJson("/api/admin/v1/projects/{$project['id']}/images", [
            'image' => UploadedFile::fake()->image('after-source.jpg', 600, 400),
        ], $this->adminApiHeaders())->assertCreated()->json('data');

        $filledAfter = $this->postJson(
            "/api/admin/v1/projects/{$project['id']}/before-afters/{$pair['id']}/after/from-gallery",
            ['image_id' => $galleryImage['id']],
            $this->adminApiHeaders(),
        )->assertOk()->json('data');
        $this->assertNotNull($filledAfter['before_url']);
        $this->assertNotNull($filledAfter['after_url']);

        $this->putJson(
            "/api/admin/v1/projects/{$project['id']}/before-afters/{$pair['id']}",
            ['title' => 'Renamed pair'],
            $this->adminApiHeaders(),
        )->assertOk()->assertJsonPath('data.title', 'Renamed pair');

        $listed = $this->getJson("/api/admin/v1/projects/{$project['id']}/before-afters", $this->adminApiHeaders())
            ->assertOk()->json('data');
        $this->assertCount(1, $listed);

        $this->deleteJson(
            "/api/admin/v1/projects/{$project['id']}/before-afters/{$pair['id']}",
            [],
            $this->adminApiHeaders(),
        )->assertNoContent();

        $this->assertSame([], $this->getJson("/api/admin/v1/projects/{$project['id']}/before-afters", $this->adminApiHeaders())->json('data'));
    }

    public function test_before_after_rejects_an_unknown_slot(): void
    {
        $project = $this->createProject();
        $pair = $this->postJson("/api/admin/v1/projects/{$project['id']}/before-afters", [], $this->adminApiHeaders())
            ->assertCreated()->json('data');

        $this->postJson(
            "/api/admin/v1/projects/{$project['id']}/before-afters/{$pair['id']}/sideways",
            ['image' => UploadedFile::fake()->image('x.jpg')],
            $this->adminApiHeaders(),
        )->assertNotFound();
    }
}
