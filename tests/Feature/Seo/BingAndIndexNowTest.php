<?php

namespace Tests\Feature\Seo;

use App\Models\PlatformSetting;
use App\Models\Site;
use App\Support\Seo\BingSettings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use SsSystems\Platform\Seo\Bing\BingWebmasterApi;
use SsSystems\Platform\Seo\IndexNow\Contracts\IndexNowKey;
use SsSystems\Platform\Seo\IndexNow\IndexNowClient;
use Tests\TestCase;

/**
 * Two kit features wired into gs.construction directly (0.19.4):
 *
 *  - seo:bing-submit-sitemaps (SsSystems\Platform\Seo\Bing\Console\
 *    BingSubmitSitemapsCommand) — Bing's half of what Google already gets
 *    from the hub, now that App\Providers\AppServiceProvider binds the
 *    concrete BingWebmasterApi class the command type-hints (previously only
 *    the BingWebmasterClient INTERFACE was bound, to this site's own
 *    BingWebmasterService — a different class the command never asks for).
 *
 *  - seo:indexnow (SsSystems\Platform\Seo\IndexNow\Console\IndexNowCommand)
 *    plus App\Support\Seo\IndexNowSiteKey (the IndexNowKey contract — an
 *    admin-supplied key wins, else one is generated on first use so no key
 *    ever needs to be hand-made) and the tenant-scoped IndexNowLedger this
 *    site binds in registerSeoTools().
 */
class BingAndIndexNowTest extends TestCase
{
    private function fakeSitemap(): void
    {
        Http::fake([
            'https://gs.construction/robots.txt' => Http::response(
                "User-agent: *\nSitemap: https://gs.construction/sitemap.xml\n",
                200,
            ),
            'https://gs.construction/sitemap.xml' => Http::response(
                '<?xml version="1.0" encoding="UTF-8"?>'
                .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
                .'<url><loc>https://gs.construction/projects/sample-kitchen</loc><lastmod>2026-10-01</lastmod></url>'
                .'</urlset>',
                200,
                ['Content-Type' => 'application/xml'],
            ),
        ]);
    }

    public function test_both_commands_are_registered_and_scheduled(): void
    {
        $commands = array_keys(Artisan::all());
        $this->assertContains('seo:bing-submit-sitemaps', $commands);
        $this->assertContains('seo:indexnow', $commands);

        $this->artisan('schedule:list')
            ->expectsOutputToContain('seo:bing-submit-sitemaps')
            ->expectsOutputToContain('seo:indexnow');
    }

    public function test_bing_submit_sitemaps_exits_clean_without_credentials(): void
    {
        config(['services.bing.webmaster_api_key' => null]);
        Http::fake();

        $this->artisan('seo:bing-submit-sitemaps')
            ->expectsOutputToContain('Bing Webmaster is not configured')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    public function test_bing_submit_sitemaps_submits_what_bing_is_missing(): void
    {
        config(['services.bing.webmaster_api_key' => null, 'services.bing.site_url' => 'https://gs.construction']);
        PlatformSetting::put(BingSettings::SETTING_API_KEY, 'admin-saved-key');

        Http::fake([
            'https://gs.construction/robots.txt' => Http::response("Sitemap: https://gs.construction/sitemap.xml\n", 200),
            BingWebmasterApi::API_BASE.'/GetFeeds*' => Http::response(['d' => []]),
            BingWebmasterApi::API_BASE.'/SubmitFeed*' => Http::response(['d' => null]),
        ]);

        $this->artisan('seo:bing-submit-sitemaps')
            ->expectsOutputToContain('submitted: https://gs.construction/sitemap.xml')
            ->assertExitCode(0);

        Http::assertSent(fn ($r) => str_contains($r->url(), '/SubmitFeed') && str_contains($r->url(), 'apikey=admin-saved-key'));
    }

    public function test_the_key_file_route_serves_the_generated_key_and_404s_a_wrong_one(): void
    {
        $key = app(IndexNowKey::class)->key();

        $this->assertNotNull($key, 'a key is generated on first use, with no admin setup needed');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $key);

        $this->get("/{$key}.txt")
            ->assertOk()
            ->assertSee($key, false);

        $this->get('/'.str_repeat('0', 32).'.txt')->assertNotFound();
    }

    public function test_indexnow_dry_run_computes_whats_due_and_sends_nothing(): void
    {
        $this->fakeSitemap();

        // A single expectsOutputToContain(): two substrings that both match the
        // SAME output line only let Mockery's doWrite expectation consume one
        // of them, leaving the other "never printed" — not a real failure.
        $this->artisan('seo:indexnow --dry-run')
            ->expectsOutputToContain('1 URLs due, 0 accepted, 0 failed (dry run')
            ->assertExitCode(0);

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api.indexnow.org'));
        $this->assertDatabaseCount('indexnow_submissions', 0);
    }

    public function test_a_real_run_submits_and_records_the_ledger_row_with_the_tenant_set(): void
    {
        $this->fakeSitemap();
        Http::fake([IndexNowClient::ENDPOINT => Http::response('', 200)]);

        $this->artisan('seo:indexnow')
            ->expectsOutputToContain('1 URLs due, 1 accepted, 0 failed')
            ->assertExitCode(0);

        Http::assertSent(fn ($r) => $r->url() === IndexNowClient::ENDPOINT
            && $r['host'] === 'gs.construction'
            && in_array('https://gs.construction/projects/sample-kitchen', $r['urlList'], true));

        $this->assertDatabaseHas('indexnow_submissions', [
            'site_id' => Site::current()->id,
            'url' => 'https://gs.construction/projects/sample-kitchen',
            'last_status' => 'accepted',
        ]);
    }
}
