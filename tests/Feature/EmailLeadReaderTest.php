<?php

namespace Tests\Feature;

use App\Jobs\SendLeadToHive;
use App\Models\ContactSubmission;
use App\Models\EmailLeadIngest;
use App\Models\PlatformSetting;
use App\Services\EmailLeadReader;
use App\Services\HiveProjectsClient;
use App\Services\LeadAddressCompleter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * leads:ingest-email reads crew@ (shared, through Patryk's grant), patryk@
 * and greg@ and files each fresh enquiry as a pending contact submission —
 * where every lead starts — then hands it to hive. Nylas and OpenAI are
 * faked; these tests hold what is filed, what is refused, and that one
 * email is one lead however many inboxes it reached.
 */
class EmailLeadReaderTest extends TestCase
{
    use RefreshDatabase;

    private const NYLAS = 'https://api.us.nylas.com';

    private const MESSAGE_ID = '<CAJx1234@mail.gmail.com>';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();

        Config::set('services.nylas.api_key', 'nylas-test');
        Config::set('services.nylas.api_uri', self::NYLAS);
        // No hive connection unless a test makes one: the env pairs below are then the mailboxes.
        Config::set('services.hive.url', null);
        Config::set('services.hive.token', null);
        Config::set('services.openai.api_key', 'openai-test');
        Config::set('services.email_leads.inboxes', [
            ['mailbox' => 'crew@gs.construction', 'grant_id' => 'grant-p'],
        ]);
        Config::set('services.email_leads.internal_domains', ['gs.construction', 'hive.contractors']);

        // Geocoding is another service's business (LeadAddressCompletionTest).
        $this->mock(LeadAddressCompleter::class, fn ($mock) => $mock->shouldReceive('complete')->andReturnUsing(fn (array $d) => $d));
    }

    /** One inbox message as Nylas returns it with include_headers. */
    private function message(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'msg-1',
            'thread_id' => 'thread-1',
            'date' => 1757970000, // 2026-09-15 20:20:00 UTC
            'from' => [['name' => 'William Johnson89 wa', 'email' => 'willjohn1089@gmail.com']],
            'to' => [['name' => 'GS Construction', 'email' => 'crew@gs.construction']],
            'cc' => [],
            'subject' => 'Bathroom remodel',
            'body' => '<div>Hi,<br>We would like to remodel the hall bathroom at 7815 Kenton Ave, Skokie. Attached is the plan.<br><br>Thanks,<br>Will<br>(832) 257-1204</div>',
            'headers' => [['name' => 'Message-ID', 'value' => self::MESSAGE_ID]],
            'attachments' => [],
        ], $overrides);
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages  what the crew@ inbox returns
     * @param  array<string, mixed>  $verdict  what the classifier answers
     */
    private function fakeNylas(array $messages, array $verdict = [], array $extra = []): void
    {
        $verdict += [
            'is_lead' => true, 'confidence' => 0.96, 'reason' => 'Homeowner asking for a bathroom remodel',
            'name' => 'Will', 'phone' => '(832) 257-1204', 'address' => '7815 Kenton Ave', 'city' => 'Skokie', 'zip' => null,
            'project_type' => 'Bathroom remodel', 'scope_summary' => 'Remodel the hall bathroom.', 'timeline' => null, 'budget' => null,
        ];

        Http::fake($extra + [
            self::NYLAS.'/v3/grants/grant-p/folders*' => Http::response(['data' => [
                ['id' => 'inbox-crew', 'name' => 'Inbox', 'attributes' => ['\\Inbox']],
                ['id' => 'sent-crew', 'name' => 'Sent Items'],
            ]]),
            self::NYLAS.'/v3/grants/grant-p/messages?*' => Http::response(['data' => $messages]),
            self::NYLAS.'/v3/grants/grant-p' => Http::response(['data' => ['id' => 'grant-p', 'email' => 'patryk@gs.construction']]),
            'https://api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => json_encode($verdict)]]]]),
        ]);
    }

    /** A base64url raw_mime with one text part and the given attachments. */
    private function rawMime(array $files): string
    {
        $lines = ['From: Will <willjohn1089@gmail.com>', 'Subject: Bathroom remodel', 'MIME-Version: 1.0', 'Content-Type: multipart/mixed; boundary="b1"', '', '--b1', 'Content-Type: text/plain', '', 'see attached'];
        foreach ($files as $name => $bytes) {
            array_push($lines, '--b1', 'Content-Type: application/octet-stream; name="'.$name.'"', 'Content-Disposition: attachment; filename="'.$name.'"', 'Content-Transfer-Encoding: base64', '', base64_encode($bytes));
        }
        array_push($lines, '--b1--', '');

        return rtrim(strtr(base64_encode(implode("\r\n", $lines)), '+/', '-_'), '=');
    }

    private function openaiCalls(): int
    {
        return count(Http::recorded(fn (Request $r) => str_contains($r->url(), 'api.openai.com')));
    }

    public function test_an_enquiry_becomes_a_pending_submission_with_its_attachment_and_goes_to_hive(): void
    {
        $this->fakeNylas([
            $this->message(['attachments' => [
                ['id' => 'att-plan', 'filename' => 'plan.pdf', 'content_type' => 'application/pdf', 'size' => 1200, 'is_inline' => false],
                ['id' => 'att-logo', 'filename' => 'logo.png', 'content_type' => 'image/png', 'size' => 300, 'is_inline' => true],
                ['id' => 'att-ics', 'filename' => 'invite.ics', 'content_type' => 'text/calendar', 'size' => 300, 'is_inline' => false],
            ]]),
        ], extra: [
            self::NYLAS.'/v3/grants/grant-p/messages/msg-1*' => Http::response(['data' => ['raw_mime' => $this->rawMime(['plan.pdf' => '%PDF-1.4 fake plan'])]]),
        ]);

        $this->artisan('leads:ingest-email')
            ->expectsOutputToContain('1 lead(s)')
            ->assertSuccessful();

        $submission = ContactSubmission::withoutSiteScope()->sole();
        $this->assertSame('crew-email', $submission->source);
        $this->assertSame('pending', $submission->status);
        // First name from the sign-off, surname from the From header.
        $this->assertSame('William Johnson', $submission->name);
        $this->assertSame('willjohn1089@gmail.com', $submission->email);
        $this->assertSame('8322571204', $submission->phone);
        $this->assertSame('7815 Kenton Ave', $submission->address);
        $this->assertSame('Skokie', $submission->city);
        $this->assertSame('Bathroom remodel', $submission->subject);
        $this->assertStringContainsString('remodel the hall bathroom', $submission->message);
        $this->assertStringNotContainsString('<br>', $submission->message);
        $this->assertSame(sha1(strtolower(self::MESSAGE_ID)), $submission->email_message_id);
        $this->assertSame('Bathroom remodel', $submission->extracted['project_type']);
        $this->assertSame('crew@gs.construction', $submission->extracted['mailbox']);
        $this->assertTrue($submission->extracted['is_lead']);
        // Filed when it arrived, not when it was read.
        $this->assertSame(1757970000, $submission->created_at->getTimestamp());

        // The plan is kept; the inline logo and the calendar invite are not.
        $this->assertCount(1, $submission->attachments);
        $this->assertSame('plan.pdf', $submission->attachments[0]['name']);
        $this->assertSame('application/pdf', $submission->attachments[0]['mime']);
        Storage::disk('public')->assertExists($submission->attachments[0]['path']);
        $this->assertStringStartsWith('email-leads/'.$submission->id.'/', $submission->attachments[0]['path']);

        $ledger = EmailLeadIngest::sole();
        $this->assertSame('lead', $ledger->status);
        $this->assertSame($submission->id, $ledger->submission_id);
        $this->assertSame(self::MESSAGE_ID, $ledger->rfc_message_id);
        $this->assertSame('crew@gs.construction', $ledger->mailbox);

        Queue::assertPushed(SendLeadToHive::class, fn (SendLeadToHive $job) => $job->submissionId === $submission->id);

        // crew@ is not the grant's own mailbox, so every read says whose it is.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/messages?') && $r['shared_from'] === 'crew@gs.construction' && $r['in'] === 'inbox-crew');
        // crew@'s files come out of the raw MIME (the download endpoint refuses shared_from).
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/messages/msg-1') && $r['fields'] === 'raw_mime' && $r['shared_from'] === 'crew@gs.construction');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/attachments/'));
        // Read once, judged once: a second run neither re-reads nor re-asks.
        $this->artisan('leads:ingest-email')->assertSuccessful();
        $this->assertSame(1, ContactSubmission::withoutSiteScope()->count());
        $this->assertSame(1, $this->openaiCalls());
    }

    public function test_replies_solicitations_bulk_mail_and_our_own_mail_are_refused_without_the_classifier(): void
    {
        ContactSubmission::create([
            'name' => 'Brad Bates', 'email' => 'brad@example.test', 'phone' => '8475550100',
            'message' => 'Window order questions', 'source' => 'web', 'status' => 'pending',
        ]);

        $this->fakeNylas([
            $this->message(['id' => 'm-internal', 'from' => [['name' => 'Greg', 'email' => 'greg@gs.construction']], 'headers' => [['name' => 'Message-ID', 'value' => '<i@gs>']]]),
            $this->message(['id' => 'm-reply', 'subject' => 'Re: Your estimate', 'headers' => [['name' => 'Message-ID', 'value' => '<r@x>'], ['name' => 'In-Reply-To', 'value' => '<est@gs>']]]),
            $this->message(['id' => 'm-known', 'from' => [['name' => 'Brad Bates', 'email' => 'Brad@example.test']], 'subject' => 'Bates window order', 'headers' => [['name' => 'Message-ID', 'value' => '<k@x>']]]),
            $this->message(['id' => 'm-cc-known', 'from' => [['name' => 'B', 'email' => 'brad.other@example.test']], 'cc' => [['email' => 'brad@example.test']], 'subject' => 'Follow up', 'headers' => [['name' => 'Message-ID', 'value' => '<kc@x>']]]),
            $this->message(['id' => 'm-bulk', 'from' => [['name' => 'Deals', 'email' => 'deals@shop.example']], 'headers' => [['name' => 'Message-ID', 'value' => '<b@x>'], ['name' => 'List-Unsubscribe', 'value' => '<mailto:u@shop.example>']]]),
            $this->message(['id' => 'm-machine', 'from' => [['name' => 'Apple', 'email' => 'no_reply@insideapple.apple.com']], 'subject' => 'Review updated terms', 'headers' => [['name' => 'Message-ID', 'value' => '<m@x>']]]),
            $this->message(['id' => 'm-shipping', 'from' => [['name' => 'Amazon', 'email' => 'shipment-tracking@amazon.com']], 'subject' => 'Out for delivery', 'headers' => [['name' => 'Message-ID', 'value' => '<s@x>']]]),
            $this->message(['id' => 'm-pitch', 'from' => [['name' => 'SEO Pro', 'email' => 'sales@seo.example']], 'subject' => 'Rank #1 on Google', 'body' => 'We help contractors like you get more leads with our proven SEO packages. Book a call today.', 'headers' => [['name' => 'Message-ID', 'value' => '<p@x>']]]),
        ], ['is_lead' => false, 'confidence' => 0.97, 'reason' => 'SEO agency pitch']);

        $this->artisan('leads:ingest-email')
            ->expectsOutputToContain('8 skipped')
            ->assertSuccessful();

        $this->assertSame(1, ContactSubmission::withoutSiteScope()->count());
        $this->assertSame([
            'm-internal' => 'internal',
            'm-reply' => 'reply',
            'm-known' => 'reply',
            'm-cc-known' => 'reply',
            'm-bulk' => 'automated',
            'm-machine' => 'automated',
            'm-shipping' => 'automated',
            'm-pitch' => 'not_a_lead',
        ], EmailLeadIngest::pluck('skip_reason', 'nylas_message_id')->all());
        // Only the pitch was worth asking the model about.
        $this->assertSame(1, $this->openaiCalls());
        Queue::assertNothingPushed();
    }

    public function test_a_suppliers_quote_addressed_to_one_of_us_is_refused_without_the_classifier(): void
    {
        // As it really arrived: the logo's alt text and the addressee's name above the greeting.
        $quote = '<p>EzeBreezeWindows.com</p><p>Patryk Szady</p><p>Hi Patryk,</p><p>Thanks for your interest in the unique Eze-Breeze panels from EzeBreezeWindows.com.</p>'
            .'<p>Your revised quote for the BRODSON job including 7 outside mounted Vertical 4-Track units with screens and 8 HD Fixed units is $7,949.85.</p>'
            .'<p>Quoted price is valid for 15 days. Ready to order? Reply to this email and let us know!</p>'
            .'<p>Shipping Update: The factory is currently anticipating lead times of 20-25 business days.</p><p>Quote Team<br>EzeBreezeWindows.com (800) 579-7812</p>';

        $this->fakeNylas([
            // Their quoting system sends a fresh message: no Re:, no In-Reply-To.
            $this->message(['id' => 'm-supplier', 'from' => [['name' => 'Quote Team', 'email' => 'quotes@ezebreezewindows.com']], 'to' => [['email' => 'patryk@gs.construction']],
                'subject' => 'REVISED Eze Breeze Quote for BRODSON job from the Quote Team at EzeBreezeWindows.com', 'body' => $quote, 'headers' => [['name' => 'Message-ID', 'value' => '<q@ezebreeze>']]]),
            // A homeowner who happens to know the name is still an enquiry.
            $this->message(['id' => 'm-referred', 'from' => [['name' => 'Dana Kowalski', 'email' => 'dana.kowalski@example.test']],
                'subject' => 'Kitchen', 'body' => '<p>Hi Patryk,</p><p>Our neighbor Madeleine referred you. We would like a quote for a kitchen remodel at 12 Oak St, Park Ridge.</p><p>Thanks, Dana Kowalski (847) 555-0102</p>',
                'headers' => [['name' => 'Message-ID', 'value' => '<d@x>']]]),
        ], ['name' => 'Dana Kowalski', 'phone' => '(847) 555-0102', 'address' => '12 Oak St', 'city' => 'Park Ridge', 'project_type' => 'Kitchen remodel', 'scope_summary' => 'Kitchen remodel.']);

        $this->artisan('leads:ingest-email')
            ->expectsOutputToContain('1 lead(s)')
            ->assertSuccessful();

        $this->assertSame('supplier', EmailLeadIngest::where('nylas_message_id', 'm-supplier')->value('skip_reason'));
        $this->assertSame(1, ContactSubmission::withoutSiteScope()->count());
        $this->assertSame('dana.kowalski@example.test', ContactSubmission::withoutSiteScope()->sole()->email);
        // Only the homeowner was worth asking the model about.
        $this->assertSame(1, $this->openaiCalls());
    }

    public function test_a_forward_is_an_enquiry_not_a_reply(): void
    {
        $this->fakeNylas([
            $this->message(['subject' => 'Fwd: Bid request — hall bathroom', 'headers' => [['name' => 'Message-ID', 'value' => '<f@x>'], ['name' => 'References', 'value' => '<orig@her-mailbox>']]]),
        ]);

        $this->artisan('leads:ingest-email')->assertSuccessful();

        $this->assertSame(1, ContactSubmission::withoutSiteScope()->count());
    }

    public function test_the_same_email_in_two_inboxes_is_one_lead(): void
    {
        Config::set('services.email_leads.inboxes', [
            ['mailbox' => 'crew@gs.construction', 'grant_id' => 'grant-p'],
            ['mailbox' => 'patryk@gs.construction', 'grant_id' => 'grant-p'],
        ]);
        // Both reads answer with the same email under different Nylas ids.
        Http::fake([
            self::NYLAS.'/v3/grants/grant-p/messages?*' => Http::sequence()
                ->push(['data' => [$this->message(['id' => 'in-crew'])]])
                ->push(['data' => [$this->message(['id' => 'in-patryk'])]]),
        ]);
        $this->fakeNylas([]);

        $this->artisan('leads:ingest-email')
            ->expectsOutputToContain('2 inbox(es)')
            ->assertSuccessful();

        $this->assertSame(1, ContactSubmission::withoutSiteScope()->count());
        $this->assertSame(['in-crew' => 'lead', 'in-patryk' => 'skipped'], EmailLeadIngest::pluck('status', 'nylas_message_id')->all());
        $this->assertSame('duplicate', EmailLeadIngest::where('nylas_message_id', 'in-patryk')->value('skip_reason'));
        // The grant's own inbox is read without shared_from.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/messages?') && ! isset($r['shared_from']));
        Queue::assertPushed(SendLeadToHive::class, 1);
    }

    public function test_an_email_hive_already_pushed_here_is_not_filed_again(): void
    {
        ContactSubmission::create([
            'name' => 'William Johnson', 'email' => 'willjohn1089@gmail.com', 'message' => 'Bathroom',
            'source' => 'crew-email', 'status' => 'pending', 'hive_lead_id' => 170,
            'email_message_id' => sha1(strtolower(self::MESSAGE_ID)),
        ]);
        $this->fakeNylas([$this->message()]);

        $this->artisan('leads:ingest-email')->assertSuccessful();

        $this->assertSame(1, ContactSubmission::withoutSiteScope()->count());
        $this->assertSame('duplicate', EmailLeadIngest::sole()->skip_reason);
        $this->assertSame(0, $this->openaiCalls());
        Queue::assertNothingPushed();
    }

    public function test_an_unsure_classifier_still_files_the_enquiry(): void
    {
        $this->fakeNylas([$this->message()], ['is_lead' => false, 'confidence' => 0.4, 'reason' => 'Hard to say']);

        $this->artisan('leads:ingest-email')->assertSuccessful();

        $submission = ContactSubmission::withoutSiteScope()->sole();
        $this->assertFalse($submission->extracted['is_lead']);
        // Nothing was extracted, so the header is all the name there is.
        $this->assertSame('William Johnson', $submission->name);
    }

    public function test_a_dry_run_reports_and_writes_nothing(): void
    {
        $this->fakeNylas([$this->message()]);

        $this->artisan('leads:ingest-email --dry-run')
            ->expectsOutputToContain('[dry run]')
            ->expectsOutputToContain('willjohn1089@gmail.com')
            ->assertSuccessful();

        $this->assertSame(0, ContactSubmission::withoutSiteScope()->count());
        $this->assertSame(0, EmailLeadIngest::count());
        Queue::assertNothingPushed();
    }

    public function test_a_skipped_message_can_be_judged_again(): void
    {
        // Filed as a reply under an older rule; the message itself is a forward.
        $row = EmailLeadIngest::create([
            'mailbox' => 'crew@gs.construction', 'grant_id' => 'grant-p', 'nylas_message_id' => 'msg-1',
            'from_email' => 'willjohn1089@gmail.com', 'subject' => 'Fwd: Bid request', 'status' => 'skipped', 'skip_reason' => 'reply',
        ]);
        $this->fakeNylas([], extra: [
            self::NYLAS.'/v3/grants/grant-p/messages/msg-1*' => Http::response(['data' => $this->message(['subject' => 'Fwd: Bid request'])]),
        ]);

        $this->artisan('leads:ingest-email --reprocess='.$row->id)
            ->expectsOutputToContain('lead')
            ->assertSuccessful();

        $this->assertSame(1, ContactSubmission::withoutSiteScope()->count());
        $this->assertSame('lead', EmailLeadIngest::sole()->status);
        Queue::assertPushed(SendLeadToHive::class, 1);
    }

    public function test_only_mail_newer_than_the_newest_judged_message_is_read(): void
    {
        $this->fakeNylas([]);

        // First run: nothing judged yet, so the lookback window applies.
        Config::set('services.email_leads.lookback_days', 3);
        $this->artisan('leads:ingest-email')->assertSuccessful();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/messages?')
            && abs((int) $r['received_after'] - now()->subDays(3)->getTimestamp()) < 5);

        // Later: the ledger's newest message is the watermark, with a small overlap.
        EmailLeadIngest::create([
            'mailbox' => 'crew@gs.construction', 'grant_id' => 'grant-p', 'nylas_message_id' => 'old-1',
            'message_at' => now()->subHours(2), 'status' => 'skipped', 'skip_reason' => 'internal',
        ]);
        EmailLeadIngest::create([
            'mailbox' => 'greg@gs.construction', 'grant_id' => 'grant-g', 'nylas_message_id' => 'other-1',
            'message_at' => now()->subMinutes(5), 'status' => 'skipped', 'skip_reason' => 'internal',
        ]);
        $this->artisan('leads:ingest-email')->assertSuccessful();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/messages?')
            && abs((int) $r['received_after'] - now()->subHours(2)->subMinutes(10)->getTimestamp()) < 5);
    }

    public function test_the_mailboxes_come_from_hive_when_it_is_connected_and_a_switched_off_one_is_left_alone(): void
    {
        Config::set('services.email_leads.inboxes', []); // nothing in the env: hive decides
        PlatformSetting::put(HiveProjectsClient::SETTING_URL, 'https://hive.test');
        PlatformSetting::put(HiveProjectsClient::SETTING_TOKEN, 'secret');
        EmailLeadReader::setDisabledMailboxes(['greg@gs.construction']);

        $this->fakeNylas([$this->message()], extra: [
            'https://hive.test/api/v1/mailboxes' => Http::response(['data' => [
                ['email' => 'patryk@gs.construction', 'grant_id' => 'grant-p', 'shared' => false],
                ['email' => 'greg@gs.construction', 'grant_id' => 'grant-g', 'shared' => false],
                ['email' => 'crew@gs.construction', 'grant_id' => 'grant-p', 'shared' => true],
            ]]),
            self::NYLAS.'/v3/grants/grant-p/messages/msg-1*' => Http::response(['data' => ['raw_mime' => $this->rawMime([])]]),
        ]);

        $this->artisan('leads:ingest-email')
            ->expectsOutputToContain('2 inbox(es)')
            ->assertSuccessful();

        // Shared first, then patryk@; greg@ is off, so its grant is never read.
        $reads = collect(Http::recorded(fn (Request $r) => str_contains($r->url(), '/messages?')));
        $this->assertSame(['crew@gs.construction', null], $reads->map(fn ($pair) => $pair[0]['shared_from'] ?? null)->values()->all());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/grants/grant-g/'));
        $this->assertSame(1, ContactSubmission::withoutSiteScope()->count());
    }

    public function test_nothing_is_read_without_configured_inboxes(): void
    {
        Config::set('services.email_leads.inboxes', []);
        Http::fake();

        $this->artisan('leads:ingest-email')->assertFailed();

        Http::assertNothingSent();
    }

    public function test_an_own_inbox_downloads_files_directly_and_falls_back_to_the_raw_mime(): void
    {
        Config::set('services.email_leads.inboxes', [['mailbox' => 'patryk@gs.construction', 'grant_id' => 'grant-p']]);
        $this->fakeNylas([
            $this->message(['attachments' => [
                ['id' => 'att-jpg', 'filename' => 'damage.jpg', 'content_type' => 'image/jpeg', 'size' => 9, 'is_inline' => false],
                ['id' => 'att-pdf', 'filename' => 'plan.pdf', 'content_type' => 'application/pdf', 'size' => 9, 'is_inline' => false],
            ]]),
        ], extra: [
            self::NYLAS.'/v3/grants/grant-p/attachments/att-jpg/download*' => Http::response('JPEGBYTES'),
            self::NYLAS.'/v3/grants/grant-p/attachments/att-pdf/download*' => Http::response(['error' => 'gone'], 404),
            self::NYLAS.'/v3/grants/grant-p/messages/msg-1*' => Http::response(['data' => ['raw_mime' => $this->rawMime(['plan.pdf' => 'PDFBYTES'])]]),
        ]);

        $this->artisan('leads:ingest-email')->assertSuccessful();

        $submission = ContactSubmission::withoutSiteScope()->sole();
        $this->assertSame(['damage.jpg', 'plan.pdf'], array_column($submission->attachments, 'name'));
        $this->assertSame('JPEGBYTES', Storage::disk('public')->get($submission->attachments[0]['path']));
        $this->assertSame('PDFBYTES', Storage::disk('public')->get($submission->attachments[1]['path']));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/attachments/att-jpg/download') && ! isset($r['shared_from']));
    }
}
