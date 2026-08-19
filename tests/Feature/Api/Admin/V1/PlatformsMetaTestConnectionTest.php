<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\Project;
use App\Models\ProjectImage;
use App\Services\AiContentService;
use App\Services\MetaSocialService;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * POST /api/admin/v1/platforms/meta/test-connection — ported from the
 * legacy Livewire component's testMetaConnection(): picks an eligible
 * project image, generates a caption via AiContentService, and creates
 * (never publishes) an Instagram media container via MetaSocialService.
 *
 * HARD SAFETY: both services are fully mocked — never a real Gemini or
 * Meta Graph API call.
 */
class PlatformsMetaTestConnectionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function bearer(): array
    {
        config(['services.admin_api.token' => 'test-admin-api-token']);

        return ['Authorization' => 'Bearer test-admin-api-token', 'Accept' => 'application/json'];
    }

    protected function eligibleImage(): ProjectImage
    {
        Storage::fake('public');

        $project = Project::create([
            'title' => 'Kitchen Remodel',
            'slug' => 'kitchen-remodel-'.uniqid(),
            'project_type' => 'kitchen',
            'is_published' => true,
        ]);

        $image = ProjectImage::create([
            'project_id' => $project->id,
            'filename' => 'kitchen.jpg',
            'original_filename' => 'kitchen.jpg',
            'path' => 'projects/kitchen.jpg',
            'disk' => 'public',
            'alt_text' => 'Renovated kitchen with white cabinets',
        ]);

        Storage::disk('public')->put('projects/kitchen.jpg', 'fake-image-bytes');

        return $image;
    }

    public function test_it_reports_no_eligible_image_when_none_has_a_local_file(): void
    {
        $meta = Mockery::mock(MetaSocialService::class);
        $meta->shouldNotReceive('getShortLinkUrl');
        $this->app->instance(MetaSocialService::class, $meta);

        $ai = Mockery::mock(AiContentService::class);
        $ai->shouldNotReceive('generateSocialMediaContent');
        $this->app->instance(AiContentService::class, $ai);

        $this->postJson('/api/admin/v1/platforms/meta/test-connection', [], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.message', 'No eligible project image with a local source file was found for the Meta test.');
    }

    public function test_it_reports_a_caption_generation_failure(): void
    {
        $image = $this->eligibleImage();

        $meta = Mockery::mock(MetaSocialService::class);
        $meta->shouldReceive('getShortLinkUrl')->once()->andReturn('https://gs.link/abc');
        $meta->shouldNotReceive('createInstagramContainer');
        $this->app->instance(MetaSocialService::class, $meta);

        $ai = Mockery::mock(AiContentService::class);
        $ai->shouldReceive('generateSocialMediaContent')->once()->andReturn(null);
        $ai->shouldReceive('getLastError')->once()->andReturn('Gemini quota exceeded');
        $this->app->instance(AiContentService::class, $ai);

        $this->postJson('/api/admin/v1/platforms/meta/test-connection', [], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.message', 'Meta test failed during caption generation: Gemini quota exceeded');
    }

    public function test_it_reports_a_container_creation_failure(): void
    {
        $image = $this->eligibleImage();

        $meta = Mockery::mock(MetaSocialService::class);
        $meta->shouldReceive('getShortLinkUrl')->once()->andReturn('https://gs.link/abc');
        $meta->shouldReceive('getPublicImageUrl')->once()->andReturn('https://gs.construction/img/kitchen.jpg');
        $meta->shouldReceive('createInstagramContainer')->once()->andReturn(null);
        $meta->shouldReceive('getLastError')->once()->andReturn(['message' => 'Invalid OAuth access token']);
        $this->app->instance(MetaSocialService::class, $meta);

        $ai = Mockery::mock(AiContentService::class);
        $ai->shouldReceive('generateSocialMediaContent')->once()->andReturn([
            'caption' => 'Beautiful new kitchen.',
            'hashtags' => '#kitchen #remodel',
        ]);
        $this->app->instance(AiContentService::class, $ai);

        $this->postJson('/api/admin/v1/platforms/meta/test-connection', [], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.ok', false)
            ->assertJsonPath('data.message', 'Meta test failed: Invalid OAuth access token');
    }

    public function test_it_succeeds_and_never_publishes(): void
    {
        $image = $this->eligibleImage();

        $meta = Mockery::mock(MetaSocialService::class);
        $meta->shouldReceive('getShortLinkUrl')->once()->andReturn('https://gs.link/abc');
        $meta->shouldReceive('getPublicImageUrl')->once()->andReturn('https://gs.construction/img/kitchen.jpg');
        $meta->shouldReceive('createInstagramContainer')->once()->andReturn(['id' => 'container-123']);
        $this->app->instance(MetaSocialService::class, $meta);

        $ai = Mockery::mock(AiContentService::class);
        $ai->shouldReceive('generateSocialMediaContent')->once()->andReturn([
            'caption' => 'Beautiful new kitchen.',
            'hashtags' => '#kitchen #remodel',
        ]);
        $this->app->instance(AiContentService::class, $ai);

        $response = $this->postJson('/api/admin/v1/platforms/meta/test-connection', [], $this->bearer())
            ->assertOk()
            ->assertJsonPath('data.ok', true);

        $message = $response->json('data.message');
        $this->assertStringContainsString("image #{$image->id}", $message);
        $this->assertStringContainsString('Container ID: container-123', $message);
        $this->assertStringContainsString('(not published)', $message);
    }
}
