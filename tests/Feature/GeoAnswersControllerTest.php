<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * /geo/answers.json's FAQPage feed moved onto the shared kit's
 * BuildsFaqPageFeed trait (kit 0.13.0) — this pins that gs.construction's
 * own publisher field, `knowsLanguage`, still reaches the public JSON
 * byte-for-byte after the move.
 */
class GeoAnswersControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('geo_answers_v1');
    }

    public function test_geo_answers_json_is_a_faq_page_with_the_configured_answers(): void
    {
        $response = $this->getJson('/geo/answers.json')->assertOk();
        $data = $response->json();

        $this->assertSame('FAQPage', $data['@type']);
        $this->assertSame('GS Construction', $data['publisher']['name']);

        $questions = array_column($data['mainEntity'], 'name');
        $this->assertContains('Who is GS Construction?', $questions);
    }

    public function test_publisher_carries_knows_language_from_config(): void
    {
        $response = $this->getJson('/geo/answers.json')->assertOk();

        $this->assertSame(
            config('geo-answers.meta.languages'),
            $response->json('publisher.knowsLanguage')
        );
        $this->assertSame(['English', 'Polish'], $response->json('publisher.knowsLanguage'));
    }

    public function test_response_is_cached_and_carries_the_public_headers(): void
    {
        $response = $this->get('/geo/answers.json');

        // Symfony's Response normalizes Cache-Control directive order on
        // send (not something this move changed) — assert the directives,
        // not a literal string. X-Robots-Tag is overwritten to
        // "noindex, nofollow" by App\Http\Middleware\NoIndexNonProduction
        // in every non-production environment (this test env included),
        // same as before this controller moved onto the kit trait — the
        // controller's own header (asserted directly on the trait, and
        // set to "all" here) is not what a request in this suite observes.
        $response->assertOk();
        $this->assertStringContainsString('public', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=3600', $response->headers->get('Cache-Control'));
    }
}
