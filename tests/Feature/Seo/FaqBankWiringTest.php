<?php

namespace Tests\Feature\Seo;

use App\Models\Site;
use App\Support\Seo\FaqBank\GscFaqBankSource;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use SsSystems\Platform\Seo\Faq\Bank\Contracts\FaqBankSource;
use SsSystems\Platform\Seo\Faq\Bank\Contracts\TextGenerator;
use SsSystems\Platform\Seo\Faq\Bank\FaqBankStore;
use SsSystems\Platform\Seo\Faq\Bank\Generators\FirstConfiguredTextGenerator;
use Tests\TestCase;

/**
 * The FAQ bank (kit 0.24.0) wired into this site's container and console
 * — AppServiceProvider's FaqBankSource/TextGenerator/FaqBankStore bindings
 * and bootstrap/app.php's command registration; the schedule entry is
 * tests/Feature/Console/FaqBankScheduleTest, the run itself
 * FaqBankCommandTest.
 */
class FaqBankWiringTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_source_is_this_sites_own(): void
    {
        $this->assertInstanceOf(GscFaqBankSource::class, app(FaqBankSource::class));
    }

    public function test_the_generator_is_gemini_first_then_openai_and_unconfigured_without_either_key(): void
    {
        $this->assertInstanceOf(FirstConfiguredTextGenerator::class, app(TextGenerator::class));
        $this->assertFalse(app(TextGenerator::class)->isConfigured(), 'phpunit.xml blanks both keys');

        config(['services.google.gemini_api_key' => 'g-key', 'services.openai.api_key' => 'o-key']);
        $this->assertTrue(app(TextGenerator::class)->isConfigured());
        $this->assertSame('Gemini gemini-2.5-flash', app(TextGenerator::class)->label());

        config(['services.google.gemini_api_key' => null, 'services.openai.content_model' => 'gpt-4.1-mini']);
        $this->assertSame('OpenAI gpt-4.1-mini', app(TextGenerator::class)->label());
    }

    public function test_the_generator_goes_through_the_http_facade_so_a_fake_applies(): void
    {
        config(['services.google.gemini_api_key' => 'g-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => '{"items":[{"q":"What do we do?","a":"We remodel kitchens in the suburbs."}]}']]]]],
        ])]);

        $text = app(TextGenerator::class)->generate('prompt');

        $this->assertStringContainsString('We remodel kitchens', (string) $text);
        Http::assertSentCount(1);
    }

    public function test_the_store_is_one_tenant_scoped_singleton_writing_this_sites_id(): void
    {
        $store = app(FaqBankStore::class);

        $this->assertSame($store, app(FaqBankStore::class));
        $this->assertTrue($store->exists());

        $store->markChecked('https://gs.construction/about', 'page', sha1('text'));

        $row = DB::table('geo_faq_bank_pages')->first();
        $this->assertSame(Site::where('slug', 'gsc')->firstOrFail()->id, (int) $row->site_id);
        $this->assertSame('https://gs.construction/about', $row->url);
    }

    public function test_the_command_is_registered(): void
    {
        $this->assertContains('seo:faq-bank', array_keys(Artisan::all()));
    }
}
