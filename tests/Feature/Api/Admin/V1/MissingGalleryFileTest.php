<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\ProjectImage;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

/**
 * A gallery row can outlive its file: an upload the disk refused, a purge, a
 * restore that missed the disk. Copying that image into a timelapse frame or a
 * before/after slot then read null and handed it to a string parameter — a
 * TypeError, so the admin saw a bare 500 and the log said nothing about the
 * missing file.
 */
class MissingGalleryFileTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
        Storage::fake('public');
    }

    /** @return array{project: array, image: array} */
    private function projectWithOrphanedImage(): array
    {
        $project = $this->postJson('/api/admin/v1/projects', [
            'title' => 'Kitchen Remodel Test',
            'project_type' => 'kitchen',
        ], $this->adminApiHeaders())->assertCreated()->json('data');

        $image = $this->postJson("/api/admin/v1/projects/{$project['id']}/images", [
            'image' => UploadedFile::fake()->image('gallery.jpg', 500, 400),
        ], $this->adminApiHeaders())->assertCreated()->json('data');

        // The row stays, the file goes. (The API does not expose the stored
        // path, so take it from the model the upload created.)
        $stored = ProjectImage::findOrFail($image['id']);
        Storage::disk('public')->assertExists($stored->path);
        Storage::disk('public')->delete($stored->path);

        return ['project' => $project, 'image' => $image];
    }

    public function test_a_timelapse_frame_from_a_missing_gallery_file_is_a_clear_422(): void
    {
        ['project' => $project, 'image' => $image] = $this->projectWithOrphanedImage();

        $timelapse = $this->postJson("/api/admin/v1/projects/{$project['id']}/timelapses", [
            'title' => 'Kitchen build',
        ], $this->adminApiHeaders())->assertCreated()->json('data');

        $this->postJson(
            "/api/admin/v1/projects/{$project['id']}/timelapses/{$timelapse['id']}/frames/from-gallery",
            ['image_id' => $image['id']],
            $this->adminApiHeaders(),
        )->assertStatus(422)->assertSee('missing from storage');
    }

    public function test_a_before_after_slot_from_a_missing_gallery_file_is_a_clear_422(): void
    {
        ['project' => $project, 'image' => $image] = $this->projectWithOrphanedImage();

        $pair = $this->postJson("/api/admin/v1/projects/{$project['id']}/before-afters", [
            'title' => 'Island',
        ], $this->adminApiHeaders())->assertCreated()->json('data');

        $this->postJson(
            "/api/admin/v1/projects/{$project['id']}/before-afters/{$pair['id']}/after/from-gallery",
            ['image_id' => $image['id']],
            $this->adminApiHeaders(),
        )->assertStatus(422)->assertSee('missing from storage');
    }
}
