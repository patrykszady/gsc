<?php

namespace Tests\Feature;

use App\Livewire\EnquiryForm;
use App\Mail\ContactFormAutoReply;
use App\Models\ContactSubmission;
use App\Models\Site;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use SsSystems\Platform\Traffic\Arrival;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

/**
 * Where visitors come from (kit 0.17): the beacon stores each visit's
 * arrival, forms credit the visit instead of the form page, traffic:collect
 * fills traffic_source_days per tenant, and the admin API serves it to the
 * SEO screen.
 */
class TrafficSourcesTest extends TestCase
{
    use WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
    }

    public function test_the_beacon_stores_where_the_visit_came_from(): void
    {
        $this->postJson('/t', [
            'e' => 'page',
            'p' => '/kitchens',
            'a' => ['r' => 'https://www.google.com/', 'us' => '', 'um' => '', 'uc' => '', 'l' => '/kitchens'],
            'f' => 1,
        ], ['User-Agent' => 'Mozilla/5.0 (iPhone)'])->assertNoContent();

        $row = DB::table('site_events')->latest('id')->first();
        $this->assertSame(['search', 'Google', '/kitchens', 1], [$row->channel, $row->source, $row->landing_path, (int) $row->arrival]);
        $this->assertSame(Tenancy::currentId(), (int) $row->site_id);
    }

    public function test_an_enquiry_credits_the_visits_arrival_not_the_form_page(): void
    {
        Mail::fake();
        Bus::fake();
        $site = Site::query()->where('slug', 'jpeterson')->firstOrFail();
        $site->forceFill(['is_active' => true])->save();
        Site::forgetActive();

        Tenancy::for($site->fresh(), function (): void {
            // Livewire's test requests carry cookies, not the raw header a browser sends.
            Livewire::withCookie(Arrival::COOKIE, json_encode(['r' => 'https://l.facebook.com/', 'l' => '/kitchens']))
                ->test(EnquiryForm::class)
                ->set('formLoadedAt', time() - 30)
                ->set('name', 'Test Visitor')
                ->set('email', 'visitor@example.com')
                ->set('phone', '(312) 555-0142')
                ->set('market', 'Chicago, IL')
                ->set('message', 'We are renovating a kitchen and would like help with it.')
                ->call('submit')
                ->assertHasNoErrors();
        });

        $lead = ContactSubmission::withoutSiteScope()->latest('id')->first();
        $this->assertSame('https://l.facebook.com/', $lead->referrer);
    }

    public function test_traffic_collect_fills_the_day_and_the_api_serves_it(): void
    {
        $siteId = Tenancy::currentId();
        $yesterday = now()->subDay()->setTime(15, 0)->toDateTimeString();

        DB::table('site_events')->insert([
            ['site_id' => $siteId, 'event' => 'page', 'path' => '/', 'vhash' => 'a', 'mobile' => false, 'created_at' => $yesterday, 'channel' => 'search', 'source' => 'Google', 'arrival' => true],
            ['site_id' => $siteId, 'event' => 'page', 'path' => '/', 'vhash' => 'b', 'mobile' => false, 'created_at' => $yesterday, 'channel' => 'ai', 'source' => 'ChatGPT', 'arrival' => true],
        ]);
        DB::table('tracked_events')->insert(['site_id' => $siteId, 'type' => 'phone_click', 'referrer' => 'https://www.google.com/', 'created_at' => $yesterday, 'updated_at' => $yesterday]);

        $this->artisan('traffic:collect', ['--days' => 1, '--provider' => ['beacon', 'tracked_events']])->assertSuccessful();

        $stored = DB::table('traffic_source_days')->where('site_id', $siteId)->orderBy('metric')->orderBy('source')->get(['provider', 'metric', 'source', 'value'])
            ->map(fn ($r) => "{$r->provider} {$r->metric} {$r->source} {$r->value}")->all();
        $this->assertSame(['tracked_events calls Google 1', 'beacon visits ChatGPT 1', 'beacon visits Google 1'], $stored);

        $this->getJson('/api/admin/v1/traffic/sources?days=28', $this->adminApiHeaders())
            ->assertOk()
            ->assertJsonPath('data.channels.0.channel', 'search')
            ->assertJsonPath('data.channels.0.metrics.visits', 1)
            ->assertJsonPath('data.channels.0.metrics.calls', 1)
            ->assertJsonPath('data.channels.0.sources.0.source', 'Google')
            ->assertJsonPath('data.channels.1.label', 'AI assistants')
            ->assertJsonPath('data.providers.beacon', now()->subDay()->toDateString());
    }

    public function test_ping_declares_the_traffic_domain(): void
    {
        $this->getJson('/api/admin/v1/ping', $this->adminApiHeaders())
            ->assertOk()
            ->assertJsonFragment(['traffic']);
    }

    public function test_the_auto_replys_site_link_is_tagged_so_its_clicks_count_as_email(): void
    {
        $html = (new ContactFormAutoReply('Jo'))->render();

        $this->assertStringContainsString('?utm_source=auto-reply&amp;utm_medium=email&amp;utm_campaign=contact-reply', $html);
    }

    public function test_from_loads_every_day_up_to_yesterday(): void
    {
        $siteId = Tenancy::currentId();
        foreach ([3, 2, 1] as $ago) {
            DB::table('site_events')->insert(['site_id' => $siteId, 'event' => 'page', 'path' => '/', 'vhash' => "v{$ago}", 'mobile' => false, 'created_at' => now()->subDays($ago)->setTime(12, 0)->toDateTimeString(), 'channel' => 'search', 'source' => 'Google', 'arrival' => true]);
        }

        $this->artisan('traffic:collect', ['--from' => now()->subDays(2)->toDateString(), '--provider' => ['beacon']])->assertSuccessful();

        $days = DB::table('traffic_source_days')->where('site_id', $siteId)->orderBy('date')->pluck('date')->map(fn ($d) => substr((string) $d, 0, 10))->all();
        $this->assertSame([now()->subDays(2)->toDateString(), now()->subDay()->toDateString()], $days, 'from two days ago to yesterday, not the day before');
    }

    public function test_todays_visitors_are_collected_hourly_and_the_window_includes_today(): void
    {
        DB::table('site_events')->insert(['site_id' => Tenancy::currentId(), 'event' => 'page', 'path' => '/', 'vhash' => 't', 'mobile' => false, 'created_at' => now()->subMinutes(5)->toDateTimeString(), 'channel' => 'social', 'source' => 'Facebook', 'arrival' => true]);

        $this->artisan('traffic:collect', ['--date' => 'today', '--provider' => ['beacon']])->assertSuccessful();

        $this->getJson('/api/admin/v1/traffic/sources?days=7', $this->adminApiHeaders())
            ->assertOk()
            ->assertJsonPath('data.to', now()->toDateString())
            ->assertJsonPath('data.visitors.channels.0.sources.0.source', 'Facebook')
            ->assertJsonPath('data.visitors.channels.0.sources.0.visitors', 1)
            ->assertJsonPath('data.tag_from', now()->toDateString());
    }
}
