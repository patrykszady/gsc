<?php

namespace Tests\Unit;

use App\Models\Project;
use App\Models\ProjectImage;
use App\Services\AiContentService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Tests\TestCase;

/**
 * AiContentService::generateGbpCaption() (0.3.0 kit, 2026-09-22): the one
 * Google Business Profile photo-caption prompt every tenant shares
 * (SsSystems\Platform\Media\GooglePhotoCaptionPrompt), Gemini faked through
 * Http::fake() exactly as the other AiContentService generator tests do
 * (see AiContentServiceSplitAreaIntroTest). A reply over the 250-character
 * Google limit must come back cut at a sentence boundary, never mid-word —
 * GooglePhotoCaptionPrompt::clean()'s job, exercised here end to end.
 *
 * The Gemini key is set AFTER each test's fixture image is created, never
 * in setUp(): ProjectImageObserver::created() dispatches GenerateAiContentJob
 * itself whenever a gemini_api_key is configured, and QUEUE_CONNECTION=sync
 * in tests runs that job inline — which would make ProjectImage::create()
 * fire a second, unrelated Gemini call (and, on the real AiContentService,
 * a real outbound HTTP attempt) before the test ever calls
 * generateGbpCaption() itself.
 */
class AiContentServiceGbpCaptionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function realJpeg(): string
    {
        return (string) (new ImageManager(new Driver()))
            ->create(40, 30)
            ->fill('#3366ff')
            ->toJpeg()
            ->toString();
    }

    private function image(): ProjectImage
    {
        // ProjectObserver auto-creates (and geocodes, over real HTTP) an
        // AreaServed row for a project's location on save, and
        // ProjectImageObserver dispatches a real GenerateAiContentJob
        // (QUEUE_CONNECTION=sync runs it inline) whenever gemini_api_key is
        // truthy — which it is by default here (no .env.testing, so .env's
        // real key loads). Both off for fixture creation; each test sets
        // its own fake key afterward.
        config([
            'services.google.business_profile.auto_geocode_on_project_save' => false,
            'services.google.gemini_api_key' => '',
        ]);

        $project = Project::create([
            'title' => 'Kitchen Remodel',
            'slug' => 'kitchen-remodel-'.uniqid(),
            'project_type' => 'kitchen',
            'location' => 'Palatine, IL',
            'completed_at' => '2020-06-15',
            'is_published' => true,
        ]);

        $path = 'projects/kitchen-'.uniqid().'.jpg';
        Storage::disk('public')->put($path, $this->realJpeg());

        return ProjectImage::create([
            'project_id' => $project->id,
            'filename' => basename($path),
            'original_filename' => basename($path),
            'path' => $path,
            'alt_text' => 'Renovated kitchen with white cabinets',
            'caption' => 'A finished kitchen remodel.',
        ]);
    }

    public function test_a_reply_over_the_limit_is_cut_at_a_sentence_boundary(): void
    {
        $image = $this->image();

        // 331 characters, three complete sentences: the first two total
        // exactly 217 — over 250 forces clean() to cut, and the cut must
        // land on the ". " after the second sentence, not mid-word into
        // the third.
        $s1 = 'GS Construction finished this Palatine kitchen remodel with white shaker cabinets and a large center island.';
        $s2 = ' The space now includes a farmhouse sink, quartz countertops, and designer pendant lighting above the island.';
        $s3 = ' Oak flooring and recessed lighting complete this bright, open kitchen renovation for a Palatine, Illinois family.';
        $raw = $s1.$s2.$s3;
        $this->assertGreaterThan(250, mb_strlen($raw));

        config(['services.google.gemini_api_key' => 'test-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => $raw]]]]],
        ], 200)]);

        $caption = app(AiContentService::class)->generateGbpCaption($image);

        $this->assertNotNull($caption);
        $this->assertLessThanOrEqual(250, mb_strlen($caption));
        $this->assertSame('.', mb_substr($caption, -1));
        $this->assertSame($s1.$s2, $caption);
    }

    public function test_a_reply_within_the_limit_is_kept_as_is_once_cleaned(): void
    {
        $image = $this->image();
        $raw = '  "GS Construction remodeled this Palatine kitchen with a large island."  ';

        config(['services.google.gemini_api_key' => 'test-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => $raw]]]]],
        ], 200)]);

        $caption = app(AiContentService::class)->generateGbpCaption($image);

        $this->assertSame('GS Construction remodeled this Palatine kitchen with a large island.', $caption);
    }

    public function test_no_gemini_key_returns_null_without_a_request(): void
    {
        $image = $this->image();

        config(['services.google.gemini_api_key' => '']);
        Http::fake();

        $this->assertNull(app(AiContentService::class)->generateGbpCaption($image));
        Http::assertNothingSent();
    }

    public function test_an_image_without_a_project_is_refused(): void
    {
        $image = $this->image();
        // project_id is not nullable in this model's own schema, so simulate
        // "no project" the way the rest of AiContentService checks it: unset
        // the loaded relation without touching the DB row.
        $image->setRelation('project', null);

        config(['services.google.gemini_api_key' => 'test-key']);
        Http::fake();
        $service = app(AiContentService::class);

        $this->assertNull($service->generateGbpCaption($image));
        $this->assertSame('Image has no associated project', $service->getLastError());
        Http::assertNothingSent();
    }
}
