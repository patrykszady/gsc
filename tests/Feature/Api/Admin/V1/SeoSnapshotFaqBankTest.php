<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\Site;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use SsSystems\Platform\Seo\Faq\Bank\FaqBankStore;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

/**
 * GET /api/admin/v1/seo/snapshot's geo.faq_bank (kit 0.24.0): the FAQ
 * bank's summary — answers, pages, when a page was last built — for the
 * hub's GEO card, this tenant's rows only (PinAdminApiTenant pins the API
 * to gsc).
 */
class SeoSnapshotFaqBankTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
        Cache::flush();
    }

    private function geo(): array
    {
        return $this->getJson('/api/admin/v1/seo/snapshot', $this->adminApiHeaders())->assertOk()->json('data.geo');
    }

    public function test_the_geo_section_carries_an_empty_bank_summary_before_the_first_run(): void
    {
        $geo = $this->geo();

        $this->assertSame(['answers' => 0, 'pages' => 0, 'last_generated_at' => null], $geo['faq_bank']);
        $this->assertSame(['llms.txt', 'llms-full.txt', 'ai-feed.json (dynamic)', 'geo/answers.json (dynamic)'], array_column($geo['feeds'], 'label'), 'the feeds list is untouched');
    }

    public function test_the_summary_counts_this_tenants_bank_only(): void
    {
        app(FaqBankStore::class)->replacePage('https://gs.construction/about', 'page', sha1('about'), [
            ['q' => 'Who runs GS Construction?', 'a' => 'A father-son team.', 'q_key' => 'who runs gs construction', 'a_key' => 'a father son team'],
            ['q' => 'Where do you work?', 'a' => 'Chicagoland.', 'q_key' => 'where do you work', 'a_key' => 'chicagoland'],
        ]);
        app(FaqBankStore::class)->markChecked('https://gs.construction/contact', 'page', sha1('thin'), 'Too thin to mine: 12 characters of text.');

        $other = Site::query()->where('slug', 'jpeterson')->firstOrFail();
        Tenancy::for($other, fn () => app(FaqBankStore::class)->replacePage('https://jpeterson-design.com/services', 'service', sha1('x'), [
            ['q' => 'What does the studio do?', 'a' => 'Interior design.', 'q_key' => 'what does the studio do', 'a_key' => 'interior design'],
        ]));

        $bank = $this->geo()['faq_bank'];

        $this->assertSame(2, $bank['answers']);
        $this->assertSame(2, $bank['pages'], 'the thin page counts as a page looked at');
        $this->assertNotNull($bank['last_generated_at']);
    }
}
