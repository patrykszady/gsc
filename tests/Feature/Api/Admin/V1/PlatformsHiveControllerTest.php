<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Models\EmailLeadIngest;
use App\Models\PlatformSetting;
use App\Services\EmailLeadReader;
use App\Services\HiveProjectsClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

/**
 * /api/admin/v1/platforms/hive — the hive.contractors connection as a
 * platform (set from the central admin's Platforms page, not the env file)
 * and the mailboxes hive has connected for the business, each with a
 * switch, shown on its Leads page.
 *
 * hive itself is always faked here; the token is asserted to be stored and
 * never echoed.
 */
class PlatformsHiveControllerTest extends TestCase
{
    use WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
        Cache::flush();
        // A dev box may carry HIVE_API_* in its env; these tests decide the connection themselves.
        Config::set('services.hive.url', null);
        Config::set('services.hive.token', null);
        Config::set('services.email_leads.inboxes', []);
    }

    private function fakeHive(array $mailboxes): void
    {
        Http::fake([
            'https://hive.test/api/v1/mailboxes' => Http::response(['data' => $mailboxes]),
        ]);
    }

    private function threeMailboxes(): array
    {
        return [
            ['email' => 'crew@gs.construction', 'grant_id' => 'grant-p', 'shared' => true],
            ['email' => 'patryk@gs.construction', 'grant_id' => 'grant-p', 'shared' => false],
            ['email' => 'greg@gs.construction', 'grant_id' => 'grant-g', 'shared' => false],
        ];
    }

    public function test_not_connected_until_credentials_are_saved(): void
    {
        Http::fake();

        $data = $this->getJson('/api/admin/v1/platforms/hive', $this->adminApiHeaders())->assertOk()->json('data');

        $this->assertFalse($data['configured']);
        $this->assertNull($data['connected']);
        $this->assertSame([], $data['mailboxes']);
        Http::assertNothingSent();
    }

    public function test_saving_credentials_connects_and_lists_hives_mailboxes_without_echoing_the_token(): void
    {
        $this->fakeHive($this->threeMailboxes());

        $data = $this->postJson('/api/admin/v1/platforms/hive/credentials', [
            'url' => 'https://hive.test/',
            'token' => 'secret-token',
        ], $this->adminApiHeaders())->assertOk()->json('data');

        $this->assertTrue($data['configured']);
        $this->assertTrue($data['connected']);
        $this->assertSame('https://hive.test', $data['url']);
        $this->assertSame('settings', $data['stored_in']);
        $this->assertTrue($data['token_set']);
        $this->assertSame(6, strlen($data['token_fingerprint']));
        $this->assertStringNotContainsString('secret-token', json_encode($data));

        // Stored encrypted, read back by the client.
        $this->assertSame('secret-token', PlatformSetting::get(HiveProjectsClient::SETTING_TOKEN));
        $this->assertSame('https://hive.test', PlatformSetting::get(HiveProjectsClient::SETTING_URL));

        // The shared inbox first, every mailbox on, nothing read yet.
        $this->assertSame(['crew@gs.construction', 'patryk@gs.construction', 'greg@gs.construction'], array_column($data['mailboxes'], 'mailbox'));
        $this->assertTrue($data['mailboxes'][0]['shared']);
        $this->assertSame([true, true, true], array_column($data['mailboxes'], 'enabled'));
        $this->assertSame('hive', $data['mailboxes'][0]['source']);
        $this->assertNull($data['mailboxes'][0]['last_read_at']);

        Http::assertSent(fn ($r) => $r->url() === 'https://hive.test/api/v1/mailboxes' && $r->hasHeader('Authorization', 'Bearer secret-token'));
    }

    public function test_saving_a_new_url_keeps_the_token_when_none_is_sent(): void
    {
        PlatformSetting::put(HiveProjectsClient::SETTING_URL, 'https://old.test');
        PlatformSetting::put(HiveProjectsClient::SETTING_TOKEN, 'keep-me');
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->postJson('/api/admin/v1/platforms/hive/credentials', ['url' => 'https://hive.test'], $this->adminApiHeaders())->assertOk();

        $this->assertSame('keep-me', PlatformSetting::get(HiveProjectsClient::SETTING_TOKEN));
        $this->assertSame('https://hive.test', PlatformSetting::get(HiveProjectsClient::SETTING_URL));
    }

    public function test_a_refused_token_shows_as_not_connected_with_the_reason(): void
    {
        PlatformSetting::put(HiveProjectsClient::SETTING_URL, 'https://hive.test');
        PlatformSetting::put(HiveProjectsClient::SETTING_TOKEN, 'bad');
        Http::fake(['https://hive.test/api/v1/mailboxes' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $data = $this->getJson('/api/admin/v1/platforms/hive', $this->adminApiHeaders())->assertOk()->json('data');

        $this->assertTrue($data['configured']);
        $this->assertFalse($data['connected']);
        $this->assertStringContainsString('401', $data['error']);
        $this->assertSame([], $data['mailboxes']);
    }

    public function test_mailboxes_carry_what_the_ledger_knows_and_can_be_switched_off(): void
    {
        PlatformSetting::put(HiveProjectsClient::SETTING_URL, 'https://hive.test');
        PlatformSetting::put(HiveProjectsClient::SETTING_TOKEN, 'secret-token');
        $this->fakeHive($this->threeMailboxes());

        EmailLeadIngest::create(['mailbox' => 'crew@gs.construction', 'grant_id' => 'grant-p', 'nylas_message_id' => 'a', 'message_at' => '2026-09-15 20:00:00', 'status' => 'lead']);
        EmailLeadIngest::create(['mailbox' => 'crew@gs.construction', 'grant_id' => 'grant-p', 'nylas_message_id' => 'b', 'message_at' => '2026-09-15 21:00:00', 'status' => 'skipped', 'skip_reason' => 'reply']);
        EmailLeadIngest::create(['mailbox' => 'greg@gs.construction', 'grant_id' => 'grant-g', 'nylas_message_id' => 'c', 'message_at' => '2026-09-15 19:00:00', 'status' => 'skipped', 'skip_reason' => 'automated']);

        $data = $this->putJson('/api/admin/v1/platforms/hive/mailboxes', ['disabled' => ['Greg@gs.construction']], $this->adminApiHeaders())
            ->assertOk()->json('data');

        $byMailbox = collect($data['mailboxes'])->keyBy('mailbox');
        $this->assertSame(2, $byMailbox['crew@gs.construction']['messages']);
        $this->assertSame(1, $byMailbox['crew@gs.construction']['leads']);
        $this->assertStringStartsWith('2026-09-15T21:00:00', $byMailbox['crew@gs.construction']['newest_message_at']);
        $this->assertNotNull($byMailbox['crew@gs.construction']['last_read_at']);
        $this->assertFalse($byMailbox['greg@gs.construction']['enabled']);
        $this->assertTrue($byMailbox['patryk@gs.construction']['enabled']);

        // The reader honours the switch.
        $this->assertSame(
            ['crew@gs.construction', 'patryk@gs.construction'],
            array_column(app(EmailLeadReader::class)->inboxes(), 'mailbox'),
        );

        // Switching it back on.
        $data = $this->putJson('/api/admin/v1/platforms/hive/mailboxes', ['disabled' => []], $this->adminApiHeaders())->assertOk()->json('data');
        $this->assertSame([true, true, true], array_column($data['mailboxes'], 'enabled'));
        $this->assertNull(PlatformSetting::get(HiveProjectsClient::SETTING_DISABLED_MAILBOXES));
    }

    public function test_read_now_runs_the_reader_and_reports_the_run(): void
    {
        PlatformSetting::put(HiveProjectsClient::SETTING_URL, 'https://hive.test');
        PlatformSetting::put(HiveProjectsClient::SETTING_TOKEN, 'secret-token');
        $this->fakeHive([]);
        $this->mock(EmailLeadReader::class, function ($mock) {
            $mock->shouldReceive('ingest')->once()->andReturn(['inboxes' => 3, 'fetched' => 4, 'leads' => 1, 'skipped' => 3, 'failed' => 0, 'details' => [['x']]]);
            $mock->shouldReceive('mailboxStatus')->andReturn([]);
        });

        $data = $this->postJson('/api/admin/v1/platforms/hive/mailboxes/read', [], $this->adminApiHeaders())->assertOk()->json('data');

        $this->assertSame(['inboxes' => 3, 'fetched' => 4, 'leads' => 1, 'skipped' => 3, 'failed' => 0], $data['run']);
    }

    public function test_disconnecting_forgets_the_connection(): void
    {
        PlatformSetting::put(HiveProjectsClient::SETTING_URL, 'https://hive.test');
        PlatformSetting::put(HiveProjectsClient::SETTING_TOKEN, 'secret-token');
        Http::fake();

        $data = $this->deleteJson('/api/admin/v1/platforms/hive', [], $this->adminApiHeaders())->assertOk()->json('data');

        $this->assertFalse($data['configured']);
        $this->assertNull(PlatformSetting::get(HiveProjectsClient::SETTING_TOKEN));
        $this->assertNull(PlatformSetting::get(HiveProjectsClient::SETTING_URL));
    }

    public function test_the_env_connection_still_counts_and_says_so(): void
    {
        Config::set('services.hive.url', 'https://hive.test');
        Config::set('services.hive.token', 'env-token');
        $this->fakeHive($this->threeMailboxes());

        $data = $this->getJson('/api/admin/v1/platforms/hive', $this->adminApiHeaders())->assertOk()->json('data');

        $this->assertTrue($data['configured']);
        $this->assertSame('env', $data['stored_in']);
        $this->assertTrue($data['connected']);
        $this->assertCount(3, $data['mailboxes']);
    }

    public function test_platforms_status_carries_the_connection_without_calling_hive(): void
    {
        PlatformSetting::put(HiveProjectsClient::SETTING_URL, 'https://hive.test');
        PlatformSetting::put(HiveProjectsClient::SETTING_TOKEN, 'secret-token');
        Http::fake();

        $hive = $this->getJson('/api/admin/v1/platforms/status', $this->adminApiHeaders())->assertOk()->json('data.hive');

        $this->assertTrue($hive['configured']);
        $this->assertNull($hive['connected']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'hive.test'));
    }
}
