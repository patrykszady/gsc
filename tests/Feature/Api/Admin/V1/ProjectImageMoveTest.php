<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\Project;
use App\Models\ProjectImage;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * POST projects/{project}/images/move — selected photos go to another
 * project, or into a new draft, files and covers following along.
 */
class ProjectImageMoveTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    protected function bearer(): array
    {
        config(['services.admin_api.token' => 'test-admin-api-token']);

        return ['Authorization' => 'Bearer test-admin-api-token', 'Accept' => 'application/json'];
    }

    private function project(string $title, array $extra = []): Project
    {
        return Project::create(['title' => $title, 'project_type' => 'kitchen', 'location' => 'Mount Prospect, IL', 'completed_at' => '2025-09-01', 'is_published' => true] + $extra);
    }

    private function photo(Project $project, string $name, int $sort, bool $cover = false): ProjectImage
    {
        $path = "projects/{$project->id}/{$name}.jpg";
        Storage::disk('public')->put($path, "original {$name}");
        Storage::disk('public')->put("projects/{$project->id}/thumbs/{$name}-sm.jpg", "sm {$name}");
        Storage::disk('public')->put("projects/{$project->id}/thumbs/{$name}-card.webp", "card {$name}");

        return ProjectImage::create([
            'project_id' => $project->id, 'filename' => "{$name}.jpg", 'original_filename' => "{$name}.jpg", 'path' => $path,
            'mime_type' => 'image/jpeg', 'size' => 10, 'is_cover' => $cover, 'sort_order' => $sort, 'alt_text' => "Alt {$name}", 'caption' => "Caption {$name}",
            'thumbnails' => ['sm' => "projects/{$project->id}/thumbs/{$name}-sm.jpg", 'card_webp' => "projects/{$project->id}/thumbs/{$name}-card.webp"],
        ]);
    }

    public function test_photos_move_to_an_existing_project_with_their_files_and_the_covers_sort_themselves_out(): void
    {
        $source = $this->project('Open-Concept Living');
        $target = $this->project('Primary Bath');
        $a = $this->photo($source, 'a', 1, cover: true);
        $b = $this->photo($source, 'b', 2);
        $c = $this->photo($source, 'c', 3);
        $t = $this->photo($target, 't', 1, cover: true);

        $response = $this->postJson("/api/admin/v1/projects/{$source->id}/images/move", ['image_ids' => [$a->id, $c->id], 'project_id' => $target->id], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.moved', 2)
            ->assertJsonPath('data.created', false)
            ->assertJsonPath('data.project.id', $target->id)
            ->assertJsonPath('data.source.id', $source->id);

        $this->assertCount(3, $response->json('data.project.images'));
        $this->assertCount(1, $response->json('data.source.images'));

        // Rows: moved, appended after the target's own, covers kept sane.
        $this->assertSame($target->id, $a->fresh()->project_id);
        $this->assertSame($target->id, $c->fresh()->project_id);
        $this->assertSame([$t->id, $a->id, $c->id], $target->images()->orderBy('sort_order')->pluck('id')->all());
        $this->assertTrue($t->fresh()->is_cover, 'the target keeps its own cover');
        $this->assertFalse($a->fresh()->is_cover);
        $this->assertTrue($b->fresh()->is_cover, 'the source promotes its first remaining photo when its cover leaves');

        // Files: originals and renditions now live under the target.
        $this->assertSame("projects/{$target->id}/a.jpg", $a->fresh()->path);
        $this->assertSame("projects/{$target->id}/thumbs/a-sm.jpg", $a->fresh()->thumbnails['sm']);
        Storage::disk('public')->assertExists("projects/{$target->id}/a.jpg");
        Storage::disk('public')->assertExists("projects/{$target->id}/thumbs/a-card.webp");
        Storage::disk('public')->assertMissing("projects/{$source->id}/a.jpg");
        Storage::disk('public')->assertMissing("projects/{$source->id}/thumbs/a-sm.jpg");
        Storage::disk('public')->assertExists("projects/{$source->id}/b.jpg");
        $this->assertSame('Caption a', $a->fresh()->caption, 'what was written about the photo travels with it');
    }

    public function test_a_name_already_taken_in_the_target_folder_is_kept_apart(): void
    {
        $source = $this->project('One');
        $target = $this->project('Two');
        $mine = $this->photo($source, 'photo', 1, cover: true);
        $this->photo($target, 'photo', 1, cover: true);

        $this->postJson("/api/admin/v1/projects/{$source->id}/images/move", ['image_ids' => [$mine->id], 'project_id' => $target->id], $this->bearer())->assertOk();

        $moved = $mine->fresh();
        $this->assertSame("projects/{$target->id}/photo-m{$mine->id}.jpg", $moved->path);
        $this->assertSame("photo-m{$mine->id}.jpg", $moved->filename);
        $this->assertSame("projects/{$target->id}/thumbs/photo-sm-m{$mine->id}.jpg", $moved->thumbnails['sm']);
        Storage::disk('public')->assertExists("projects/{$target->id}/photo.jpg");
        Storage::disk('public')->assertExists("projects/{$target->id}/photo-m{$mine->id}.jpg");
        $this->assertSame('original photo', Storage::disk('public')->get("projects/{$target->id}/photo-m{$mine->id}.jpg"));
    }

    public function test_photos_move_into_a_new_draft_that_inherits_the_facts(): void
    {
        $source = $this->project('Open-Concept Living');
        $a = $this->photo($source, 'a', 1, cover: true);
        $b = $this->photo($source, 'b', 2);

        $response = $this->postJson("/api/admin/v1/projects/{$source->id}/images/move", ['image_ids' => [$b->id]], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.created', true)
            ->assertJsonPath('data.project.title', 'New project')
            ->assertJsonPath('data.project.is_published', false)
            ->assertJsonPath('data.project.location', 'Mount Prospect, IL')
            ->assertJsonPath('data.project.project_type', 'kitchen');

        $draft = Project::find($response->json('data.project.id'));
        $this->assertNotSame($source->id, $draft->id);
        $this->assertSame($draft->id, $b->fresh()->project_id);
        $this->assertTrue($b->fresh()->is_cover, 'the first photo into an empty project is its cover');
        $this->assertTrue($a->fresh()->is_cover, 'the source keeps its cover');
        Storage::disk('public')->assertExists("projects/{$draft->id}/b.jpg");
    }

    public function test_a_titled_new_project_keeps_that_title(): void
    {
        $source = $this->project('Open-Concept Living');
        $b = $this->photo($source, 'b', 1, cover: true);

        $response = $this->postJson("/api/admin/v1/projects/{$source->id}/images/move", ['image_ids' => [$b->id], 'title' => '  Lake House Kitchen '], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.project.title', 'Lake House Kitchen')
            ->assertJsonPath('data.project.slug', 'lake-house-kitchen');

        $this->assertNotNull(Project::find($response->json('data.project.id')));
    }

    public function test_the_move_is_refused_for_photos_of_another_project_or_the_same_project(): void
    {
        $source = $this->project('One');
        $other = $this->project('Two');
        $mine = $this->photo($source, 'a', 1, cover: true);
        $theirs = $this->photo($other, 'b', 1, cover: true);

        $this->postJson("/api/admin/v1/projects/{$source->id}/images/move", ['image_ids' => [$theirs->id], 'project_id' => $other->id], $this->bearer())
            ->assertStatus(422)->assertJsonValidationErrors(['image_ids']);
        $this->postJson("/api/admin/v1/projects/{$source->id}/images/move", ['image_ids' => [$mine->id], 'project_id' => $source->id], $this->bearer())
            ->assertStatus(422)->assertJsonValidationErrors(['project_id']);
        $this->postJson("/api/admin/v1/projects/{$source->id}/images/move", ['image_ids' => []], $this->bearer())
            ->assertStatus(422)->assertJsonValidationErrors(['image_ids']);

        $this->assertSame($source->id, $mine->fresh()->project_id);
        $this->assertSame($other->id, $theirs->fresh()->project_id);
    }

    public function test_ping_declares_the_capability(): void
    {
        $this->getJson('/api/admin/v1/ping', $this->bearer())->assertOk()
            ->assertJsonFragment(['image-move']);
    }
}
