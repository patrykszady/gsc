<?php

namespace Tests\Feature\Console;

use App\Models\Project;
use App\Models\ProjectImage;
use App\Services\AiContentService;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Mockery;
use Tests\TestCase;

/**
 * images:gbp-captions (2026-09-22) — fills ProjectImage::gbp_caption for
 * published projects via AiContentService::generateGbpCaption(), mocked
 * here exactly as GenerateAiContentJob's own tests mock the same service
 * (Gemini itself is covered by AiContentServiceGbpCaptionTest).
 *
 * The gemini_api_key is set per-test, AFTER the fixture images are built,
 * never in setUp(): ProjectImageObserver::created() dispatches its own
 * GenerateAiContentJob whenever a key is configured, and QUEUE_CONNECTION
 * =sync in tests runs that job inline on ProjectImage::create() — which
 * would call the mocked AiContentService (or hit real Gemini, before it's
 * mocked) as a side effect of building fixtures, not of running the command.
 */
class GbpCaptionsBackfillTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function realJpeg(): string
    {
        return (string) (new ImageManager(new Driver()))
            ->create(10, 10)
            ->fill('#3366ff')
            ->toJpeg()
            ->toString();
    }

    private function image(bool $published, ?string $gbpCaption = null): ProjectImage
    {
        // ProjectObserver auto-creates (and geocodes, over real HTTP) an
        // AreaServed row for a project's location on save, and
        // ProjectImageObserver dispatches a real GenerateAiContentJob
        // (QUEUE_CONNECTION=sync runs it inline) whenever gemini_api_key is
        // truthy — which it is by default here (no .env.testing, so .env's
        // real key loads). Both off for fixture creation; each test sets
        // its own fake key afterward (or leaves it blank).
        config([
            'services.google.business_profile.auto_geocode_on_project_save' => false,
            'services.google.gemini_api_key' => '',
        ]);

        $project = Project::create([
            'title' => 'Kitchen Remodel',
            'slug' => 'kitchen-remodel-'.uniqid(),
            'project_type' => 'kitchen',
            'location' => 'Palatine, IL',
            'is_published' => $published,
        ]);

        $path = 'projects/kitchen-'.uniqid().'.jpg';
        Storage::disk('public')->put($path, $this->realJpeg());

        return ProjectImage::create([
            'project_id' => $project->id,
            'filename' => basename($path),
            'original_filename' => basename($path),
            'path' => $path,
            'alt_text' => 'Renovated kitchen',
            'caption' => 'A finished kitchen remodel.',
            'gbp_caption' => $gbpCaption,
        ]);
    }

    public function test_it_fills_blank_captions_for_published_projects_only(): void
    {
        $published = $this->image(true);
        $unpublished = $this->image(false);

        $mock = Mockery::mock(AiContentService::class);
        $mock->shouldReceive('generateGbpCaption')
            ->once()
            ->with(Mockery::on(fn (ProjectImage $img) => $img->is($published)))
            ->andReturn('A short GBP caption.');
        $this->app->instance(AiContentService::class, $mock);

        config(['services.google.gemini_api_key' => 'test-key']);
        $this->artisan('images:gbp-captions')->assertExitCode(0);

        $this->assertSame('A short GBP caption.', $published->refresh()->gbp_caption);
        $this->assertNull($unpublished->refresh()->gbp_caption);
    }

    public function test_it_skips_images_that_already_have_a_caption_unless_forced(): void
    {
        $image = $this->image(true, 'Existing caption.');

        $mock = Mockery::mock(AiContentService::class);
        $mock->shouldNotReceive('generateGbpCaption');
        $this->app->instance(AiContentService::class, $mock);

        config(['services.google.gemini_api_key' => 'test-key']);
        $this->artisan('images:gbp-captions')->assertExitCode(0);

        $this->assertSame('Existing caption.', $image->refresh()->gbp_caption);
    }

    public function test_force_regenerates_an_existing_caption(): void
    {
        $image = $this->image(true, 'Old caption.');

        $mock = Mockery::mock(AiContentService::class);
        $mock->shouldReceive('generateGbpCaption')->once()->andReturn('New caption.');
        $this->app->instance(AiContentService::class, $mock);

        config(['services.google.gemini_api_key' => 'test-key']);
        $this->artisan('images:gbp-captions --force')->assertExitCode(0);

        $this->assertSame('New caption.', $image->refresh()->gbp_caption);
    }

    public function test_the_project_option_scopes_to_one_project(): void
    {
        $imageA = $this->image(true);
        $imageB = $this->image(true);

        $mock = Mockery::mock(AiContentService::class);
        $mock->shouldReceive('generateGbpCaption')
            ->once()
            ->with(Mockery::on(fn (ProjectImage $img) => $img->is($imageA)))
            ->andReturn('Caption A.');
        $this->app->instance(AiContentService::class, $mock);

        config(['services.google.gemini_api_key' => 'test-key']);
        $this->artisan('images:gbp-captions --project='.$imageA->project_id)->assertExitCode(0);

        $this->assertSame('Caption A.', $imageA->refresh()->gbp_caption);
        $this->assertNull($imageB->refresh()->gbp_caption);
    }

    public function test_without_a_gemini_key_it_fails_fast(): void
    {
        config(['services.google.gemini_api_key' => '']);
        $this->image(true);

        $this->artisan('images:gbp-captions')->assertExitCode(1);
    }
}
