<?php

namespace Tests\Unit;

use App\Models\Service;
use App\Services\AiContentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * When Gemini cannot answer a text-only prompt (2026-11-04: 402, prepaid
 * credits depleted, for all 132 town service pages), the same prompt goes
 * to OpenAI; without an OpenAI key the Gemini error is what the caller sees.
 */
class AiContentServiceOpenAiFallbackTest extends TestCase
{
    use RefreshDatabase;

    private const GEMINI = 'generativelanguage.googleapis.com/*';

    private const OPENAI = 'api.openai.com/*';

    private array $copy = [
        'intro' => 'Siding is the one job the whole street sees, and we run it like a kitchen.',
        'what_we_do' => 'We take the old siding off, repair the sheathing, wrap and flash the house and install the new siding, trim, soffit and fascia under one contract with one project lead.',
        'ideal_for' => 'Older Chicagoland homes whose aluminum or first-generation vinyl siding has faded, cracked or let water behind it.',
        'faq' => [
            ['question' => 'How long does it take?', 'answer' => 'Most siding jobs run four to eight weeks.'],
            ['question' => 'Do I need a permit?', 'answer' => 'Many villages require one; we pull it.'],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.google.gemini_api_key' => 'gemini-test-key']);
    }

    public function test_a_text_prompt_falls_back_to_openai_when_gemini_cannot_answer(): void
    {
        config(['services.openai.api_key' => 'sk-test', 'services.openai.content_model' => 'gpt-4.1-mini']);
        Http::fake([
            self::GEMINI => Http::response(['error' => ['message' => 'Your prepayment credits are depleted.']], 402),
            self::OPENAI => Http::response(['choices' => [['message' => ['content' => json_encode($this->copy)]]]], 200),
        ]);
        $service = Service::create(['name' => 'Exterior/Siding', 'slug' => 'exterior']);

        $ai = app(AiContentService::class);
        $result = $ai->generateServiceContent($service);

        $this->assertNotNull($result, (string) $ai->getLastError());
        $this->assertSame($this->copy['intro'], $result['intro']);
        $this->assertNull($ai->getLastError());
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.openai.com/v1/chat/completions')
            && $r->hasHeader('Authorization', 'Bearer sk-test')
            && $r['model'] === 'gpt-4.1-mini');
    }

    public function test_without_an_openai_key_the_gemini_error_is_what_the_caller_sees(): void
    {
        config(['services.openai.api_key' => '']);
        Http::fake([
            self::GEMINI => Http::response(['error' => ['message' => 'Your prepayment credits are depleted.']], 402),
        ]);
        $service = Service::create(['name' => 'Exterior/Siding', 'slug' => 'exterior']);

        $ai = app(AiContentService::class);

        $this->assertNull($ai->generateServiceContent($service));
        $this->assertStringContainsString('API error 402', (string) $ai->getLastError());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'api.openai.com'));
    }

    public function test_when_both_fail_the_error_names_both(): void
    {
        config(['services.openai.api_key' => 'sk-test']);
        Http::fake([
            self::GEMINI => Http::response(['error' => ['message' => 'Your prepayment credits are depleted.']], 402),
            self::OPENAI => Http::response(['error' => ['message' => 'Rate limit reached']], 429),
        ]);
        $service = Service::create(['name' => 'Exterior/Siding', 'slug' => 'exterior']);

        $ai = app(AiContentService::class);

        $this->assertNull($ai->generateServiceContent($service));
        $this->assertStringContainsString('API error 402', (string) $ai->getLastError());
        $this->assertStringContainsString('OpenAI error 429', (string) $ai->getLastError());
    }
}
