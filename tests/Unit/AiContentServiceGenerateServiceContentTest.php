<?php

namespace Tests\Unit;

use App\Models\Service;
use App\Services\AiContentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AiContentService::generateServiceContent, network mocked via Http::fake()
 * (the same transport every other AiContentService generator uses) so this
 * never makes a real Gemini call. Covers the JSON-parse contract: all four
 * keys required, faq normalised and non-empty, and a malformed reply
 * surfaces as null + getLastError().
 */
class AiContentServiceGenerateServiceContentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.gemini_api_key' => 'test-key']);
    }

    protected function fakeGeminiReply(string $text): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => $text]]]],
                ],
            ], 200),
        ]);
    }

    public function test_it_parses_a_well_formed_reply(): void
    {
        $service = Service::create(['name' => 'Kitchen & Bath Design', 'slug' => 'kitchen']);

        $this->fakeGeminiReply(json_encode([
            'intro' => 'Kitchen & Bath Design covers the layout, cabinetry and finishes for your kitchen or bathroom, start to finish.',
            'what_we_do' => 'We draw the layout, spec every fixture and finish, and hand your contractor a full set of documents. We coordinate selections so nothing stalls the build, and our project lead visits the site during construction to check the work against the drawings before the punch list closes.',
            'ideal_for' => 'Homeowners in older Chicagoland bungalows and split-levels who want a full kitchen or bath layout change, not just new finishes on the same footprint.',
            'faq' => [
                ['question' => 'Do you build it too?', 'answer' => 'No — your contractor builds; we design and document.'],
                ['question' => 'How long does design take?', 'answer' => 'Design and documents typically run several weeks before your contractor breaks ground.'],
            ],
        ]));

        $ai = app(AiContentService::class);
        $result = $ai->generateServiceContent($service);

        $this->assertNotNull($result, (string) $ai->getLastError());
        $this->assertSame(['intro', 'what_we_do', 'ideal_for', 'faq'], array_keys($result));
        $this->assertCount(2, $result['faq']);
        $this->assertSame('Do you build it too?', $result['faq'][0]['question']);
    }

    public function test_it_strips_markdown_code_fences(): void
    {
        $service = Service::create(['name' => 'Kitchen', 'slug' => 'kitchen']);

        $payload = json_encode([
            'intro' => str_repeat('Kitchen design services for your home. ', 5),
            'what_we_do' => str_repeat('We design, document and select finishes for your kitchen project from start to finish. ', 6),
            'ideal_for' => str_repeat('Older Chicagoland homes wanting a full kitchen layout change. ', 4),
            'faq' => [['question' => 'Q?', 'answer' => 'A.']],
        ]);
        $this->fakeGeminiReply("```json\n{$payload}\n```");

        $ai = app(AiContentService::class);
        $result = $ai->generateServiceContent($service);

        $this->assertNotNull($result, (string) $ai->getLastError());
    }

    public function test_a_reply_missing_a_required_field_is_rejected(): void
    {
        $service = Service::create(['name' => 'Kitchen', 'slug' => 'kitchen']);

        $this->fakeGeminiReply(json_encode([
            'intro' => 'Kitchen design services.',
            'what_we_do' => 'We design your kitchen.',
            // ideal_for missing.
            'faq' => [['question' => 'Q?', 'answer' => 'A.']],
        ]));

        $ai = app(AiContentService::class);
        $result = $ai->generateServiceContent($service);

        $this->assertNull($result);
        $this->assertStringContainsString('ideal_for', (string) $ai->getLastError());
    }

    public function test_a_reply_with_an_empty_faq_is_rejected(): void
    {
        $service = Service::create(['name' => 'Kitchen', 'slug' => 'kitchen']);

        $this->fakeGeminiReply(json_encode([
            'intro' => 'Kitchen design services.',
            'what_we_do' => 'We design your kitchen.',
            'ideal_for' => 'Older homes.',
            'faq' => [['question' => '', 'answer' => '']],
        ]));

        $ai = app(AiContentService::class);
        $result = $ai->generateServiceContent($service);

        $this->assertNull($result);
        $this->assertStringContainsString('faq', (string) $ai->getLastError());
    }

    public function test_no_api_key_fails_fast_without_a_request(): void
    {
        config(['services.google.gemini_api_key' => '']);
        Http::fake();

        $service = Service::create(['name' => 'Kitchen', 'slug' => 'kitchen']);

        $ai = app(AiContentService::class);
        $result = $ai->generateServiceContent($service);

        $this->assertNull($result);
        $this->assertSame('Gemini API key not configured', $ai->getLastError());
        Http::assertNothingSent();
    }
}
