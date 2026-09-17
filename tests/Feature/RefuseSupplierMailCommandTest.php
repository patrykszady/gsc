<?php

namespace Tests\Feature;

use App\Models\ContactSubmission;
use App\Models\EmailLeadIngest;
use App\Services\EmailLeadReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Email submissions filed before the reader could tell a supplier's quote
 * from an enquiry are judged again and taken back: here, on hive, and in
 * the ledger.
 */
class RefuseSupplierMailCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.nylas.api_key', 'nylas-test');
        Config::set('services.nylas.api_uri', 'https://api.us.nylas.com');
        Config::set('services.hive.url', 'https://hive.test');
        Config::set('services.hive.token', 'test-token');
        Config::set('services.email_leads.inboxes', [['mailbox' => 'crew@gs.construction', 'grant_id' => 'grant-p']]);
        Config::set('services.email_leads.internal_domains', ['gs.construction']);
    }

    private function emailSubmission(array $attributes, array $ledger = []): ContactSubmission
    {
        $submission = ContactSubmission::create($attributes + ['source' => EmailLeadReader::SOURCE, 'status' => 'pending', 'phone' => null]);
        EmailLeadIngest::create($ledger + [
            'mailbox' => 'patryk@gs.construction', 'grant_id' => 'grant-p', 'nylas_message_id' => 'msg-'.$submission->id,
            'from_email' => $submission->email, 'subject' => $submission->subject, 'status' => EmailLeadIngest::STATUS_LEAD,
            'is_lead' => true, 'submission_id' => $submission->id,
        ]);

        return $submission;
    }

    public function test_a_suppliers_quote_is_taken_back_here_on_hive_and_in_the_ledger_while_an_enquiry_stays(): void
    {
        Http::fake([
            'https://api.us.nylas.com/v3/grants/grant-p' => Http::response(['data' => ['id' => 'grant-p', 'email' => 'patryk@gs.construction']]),
            'hive.test/api/v1/leads/*' => Http::response(['deleted' => true]),
        ]);

        $quote = $this->emailSubmission([
            'name' => 'Quote Team', 'email' => 'quotes@ezebreezewindows.com', 'hive_lead_id' => 501,
            'subject' => 'REVISED Eze Breeze Quote for BRODSON job from the Quote Team at EzeBreezeWindows.com',
            'message' => "Hi Patryk,\n\nThanks for your interest in the unique Eze-Breeze panels.\n\nYour revised quote for the BRODSON job is $7,949.85. Ready to order? Reply to this email.",
        ]);
        $enquiry = $this->emailSubmission([
            'name' => 'Dana Kowalski', 'email' => 'dana.kowalski@example.test', 'hive_lead_id' => 502,
            'subject' => 'Kitchen', 'message' => "Hi Patryk,\n\nOur neighbor referred you. We would like a quote for a kitchen remodel.\n\nThanks, Dana Kowalski",
        ]);

        $this->artisan('leads:refuse-supplier-mail')
            ->expectsOutputToContain('1 supplier message would be removed.')
            ->assertSuccessful();
        $this->assertSame(2, ContactSubmission::withoutSiteScope()->count());
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'hive.test'));

        $this->artisan('leads:refuse-supplier-mail', ['--apply' => true])
            ->expectsOutputToContain('1 supplier message found, 1 removed.')
            ->assertSuccessful();

        $this->assertNull(ContactSubmission::withoutSiteScope()->find($quote->id));
        $this->assertNotNull(ContactSubmission::withoutSiteScope()->find($enquiry->id));
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/api/v1/leads/501'));
        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/api/v1/leads/502'));

        $row = EmailLeadIngest::where('nylas_message_id', 'msg-'.$quote->id)->sole();
        $this->assertSame(EmailLeadIngest::STATUS_SKIPPED, $row->status);
        $this->assertSame('supplier', $row->skip_reason);
        $this->assertNull($row->submission_id);

        // Nothing left to take back.
        $this->artisan('leads:refuse-supplier-mail', ['--apply' => true])
            ->expectsOutputToContain('0 supplier messages found, 0 removed.')
            ->assertSuccessful();
    }

    public function test_a_submission_hive_will_not_give_up_is_kept_for_another_run(): void
    {
        Http::fake([
            'https://api.us.nylas.com/v3/grants/grant-p' => Http::response(['data' => ['id' => 'grant-p', 'email' => 'patryk@gs.construction']]),
            'hive.test/api/v1/leads/*' => Http::response(['message' => 'nope'], 500),
        ]);

        $quote = $this->emailSubmission([
            'name' => 'Quote Team', 'email' => 'quotes@ezebreezewindows.com', 'hive_lead_id' => 501,
            'subject' => 'Your revised quote', 'message' => "Hi Patryk,\n\nYour revised quote is attached. Ready to order?",
        ]);

        $this->artisan('leads:refuse-supplier-mail', ['--apply' => true])
            ->expectsOutputToContain('1 supplier message found, 0 removed.')
            ->assertSuccessful();

        $this->assertNotNull(ContactSubmission::withoutSiteScope()->find($quote->id));
        $this->assertSame(EmailLeadIngest::STATUS_LEAD, EmailLeadIngest::where('submission_id', $quote->id)->sole()->status);
    }
}
