<?php

namespace Tests\Feature;

use App\Jobs\SendLeadToHive;
use App\Models\ContactSubmission;
use App\Models\EmailLeadIngest;
use App\Services\HiveProjectsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * What hive is told about a lead this site read out of an inbox: the email's
 * own identity (so hive's earlier crew@ reader and this one never make
 * twins), the origin, the extracted detail and the files.
 */
class SendLeadToHiveEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Config::set('services.hive.url', 'https://hive.test');
        Config::set('services.hive.token', 'hive-token');
    }

    public function test_an_email_lead_is_sent_under_its_message_identity_with_its_detail_and_files(): void
    {
        Storage::disk('public')->put('email-leads/1/plan.pdf', '%PDF');
        $submission = ContactSubmission::create([
            'name' => 'William Johnson', 'email' => 'willjohn1089@gmail.com', 'phone' => '8322571204',
            'address' => '7815 Kenton Ave', 'city' => 'Skokie', 'state' => 'IL', 'zip' => '60076',
            'subject' => 'Bathroom remodel', 'message' => 'We would like to remodel the hall bathroom.',
            'source' => 'crew-email', 'status' => 'pending', 'email_message_id' => str_repeat('a', 40),
            'extracted' => ['mailbox' => 'crew@gs.construction', 'project_type' => 'Bathroom remodel', 'is_lead' => true, 'confidence' => 0.96],
            'attachments' => [['path' => 'email-leads/1/plan.pdf', 'name' => 'plan.pdf', 'mime' => 'application/pdf', 'size' => 4]],
        ]);
        EmailLeadIngest::create([
            'mailbox' => 'crew@gs.construction', 'grant_id' => 'grant-p', 'nylas_message_id' => 'msg-1',
            'rfc_message_id' => '<CAJx1234@mail.gmail.com>', 'status' => 'lead', 'submission_id' => $submission->id,
        ]);
        Http::fake(['https://hive.test/api/v1/leads' => Http::response(['data' => ['id' => 501]], 201)]);

        (new SendLeadToHive($submission->id))->handle(app(HiveProjectsClient::class));

        Http::assertSent(function (Request $r) {
            return $r['external_id'] === str_repeat('a', 40)
                && $r['source'] === 'crew-email'
                && $r['subject'] === 'Bathroom remodel'
                && $r['state'] === 'IL'
                && $r['zip'] === '60076'
                && $r['extracted']['project_type'] === 'Bathroom remodel'
                && $r['extracted']['is_lead'] === true
                && $r['in_reply_to'] === '<CAJx1234@mail.gmail.com>'
                && $r['attachments'][0]['name'] === 'plan.pdf'
                && str_ends_with($r['attachments'][0]['url'], '/storage/email-leads/1/plan.pdf');
        });
        $this->assertSame(501, $submission->fresh()->hive_lead_id);
    }

    public function test_a_web_form_lead_is_sent_as_before(): void
    {
        $submission = ContactSubmission::create([
            'name' => 'Jane Doe', 'email' => 'jane@example.com', 'phone' => '5551212',
            'message' => 'Looking for a quote.', 'source' => 'web', 'status' => 'pending',
        ]);
        Http::fake(['https://hive.test/api/v1/leads' => Http::response(['data' => ['id' => 502]], 201)]);

        (new SendLeadToHive($submission->id))->handle(app(HiveProjectsClient::class));

        Http::assertSent(fn (Request $r) => $r['external_id'] === (string) $submission->id
            && $r['source'] === 'gs.construction'
            && ! isset($r['attachments'])
            && ! isset($r['extracted'])
            && ! isset($r['in_reply_to']));
    }
}
