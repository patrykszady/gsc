<?php

namespace Tests\Feature\Api\Admin\V1;

use App\Jobs\SendLeadToHive;
use App\Mail\ContactFormAutoReply;
use App\Mail\ContactFormSubmission;
use App\Models\ContactSubmission;
use App\Models\Site;
use App\Models\TrackedEvent;
use App\Support\Tenancy;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\Admin\V1\Concerns\WithAdminApiAuth;
use Tests\TestCase;

/**
 * "Make lead" from the hub's Analytics screen (2026-11-10): POST
 * /api/admin/v1/analytics/events/{event}/lead turns a Call, Text or Email
 * tap into a ContactSubmission through App\Services\AnalyticsEventLeadMaker,
 * made and delivered the way the contact form's own lead is (the team's
 * notification, the hand-off to hive.contractors) and never anything to the
 * visitor. The endpoint itself — validation, answering the lead already
 * made, the 404s, the lead keys on events() rows and folding one visitor's
 * identical taps into one row — is the kit's BuildsAnalyticsScreen, pinned
 * here over this site's real tracked_events and contact_submissions tables.
 * The fixture is a visitor from ChatGPT who tapped Text on 2026-11-09.
 */
class AnalyticsEventLeadTest extends TestCase
{
    use LazilyRefreshDatabase;
    use WithAdminApiAuth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminApiAuth();
        Carbon::setTestNow('2026-11-10 18:00:00');
        config(['brand.lead_email' => 'leads@example.com']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * One tap, with who made it (IP, browser, session) so identical taps fold.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function tap(string $type, array $overrides = []): TrackedEvent
    {
        return TrackedEvent::forceCreate(array_merge([
            'type' => $type,
            'label' => '2246284013',
            'page_path' => '/kitchen-remodeling',
            'referrer' => 'https://chatgpt.com/',
            'session_id' => 'visitor-a',
            'ip_address' => '203.0.113.9',
            'user_agent' => 'Mozilla/5.0 (iPhone) Safari',
            'created_at' => Carbon::parse('2026-11-09 15:22:44', 'UTC'),
        ], $overrides));
    }

    /** @param  array<string, mixed>  $input */
    protected function makeLead(TrackedEvent|int $event, array $input): TestResponse
    {
        $id = $event instanceof TrackedEvent ? $event->id : $event;

        return $this->postJson("/api/admin/v1/analytics/events/{$id}/lead", $input, $this->adminApiHeaders());
    }

    /** @return array<int, array<string, mixed>> the events() rows, by id */
    protected function rows(): array
    {
        return collect($this->getJson('/api/admin/v1/analytics/events', $this->adminApiHeaders())->assertOk()->json('data'))
            ->keyBy('id')
            ->all();
    }

    private function otherSite(): Site
    {
        $site = Site::query()->firstOrCreate(['slug' => 'jpeterson'], [
            'name' => 'J. Peterson Design', 'theme' => 'jpeterson', 'hosts' => ['jpeterson-design.com'], 'primary_host' => 'jpeterson-design.com',
        ]);
        $site->forceFill(['is_active' => true, 'hosts' => ['jpeterson-design.com'], 'primary_host' => 'jpeterson-design.com'])->save();
        Site::forgetActive();

        return $site->fresh();
    }

    public function test_make_lead_needs_the_admin_token(): void
    {
        $this->postJson('/api/admin/v1/analytics/events/1/lead', ['phone' => '630-555-0142'])->assertUnauthorized();
    }

    public function test_make_lead_stores_the_tap_as_a_form_lead_and_delivers_it_to_the_team(): void
    {
        Mail::fake();
        Bus::fake();
        $event = $this->tap(TrackedEvent::TYPE_TEXT_CLICK);

        $response = $this->makeLead($event, ['name' => 'Sarah', 'phone' => '630-555-0142', 'note' => 'Asked about a kitchen.'])
            ->assertCreated();

        $lead = ContactSubmission::query()->sole();
        $this->assertSame(['id' => $lead->id, 'contact' => '6305550142', 'event_id' => (string) $event->id], $response->json('data'));

        // The row the contact form writes, with the tap's own facts.
        $this->assertSame('Sarah', $lead->name);
        $this->assertSame('6305550142', $lead->phone, 'digits only, as the form stores a phone');
        $this->assertSame('', $lead->email);
        $this->assertSame('text', $lead->source);
        $this->assertSame('pending', $lead->status);
        $this->assertNull($lead->spam_reason);
        $this->assertSame('https://chatgpt.com/', $lead->referrer);
        $this->assertSame('203.0.113.9', $lead->ip_address, "the visitor's IP from the tap, never the hub's");
        $this->assertSame('Mozilla/5.0 (iPhone) Safari', $lead->user_agent);
        $this->assertSame((string) $event->id, $lead->analytics_event_id);
        $this->assertTrue($lead->created_at->eq(Carbon::parse('2026-11-09 15:22:44', 'UTC')), 'filed at the tap, not when the team got to it');
        $this->assertSame(Site::query()->where('slug', 'gsc')->value('id'), $lead->site_id, 'the pinned tenant, like a form lead');
        $this->assertSame(
            "Tapped Text ((224) 628-4013) on the website, Nov 9, 2026 9:22 AM CT. Came to the site from ChatGPT.\nAsked about a kitchen.",
            $lead->message,
        );

        // Delivered like a web lead: the team's notification and hive — and
        // nothing to the visitor, who never filled a form.
        Mail::assertSent(ContactFormSubmission::class, function (ContactFormSubmission $mail) use ($lead): bool {
            return $mail->hasTo('leads@example.com')
                && $mail->name === 'Sarah'
                && $mail->phone === '630-555-0142'
                && $mail->userMessage === $lead->message;
        });
        Mail::assertNotSent(ContactFormAutoReply::class);
        Bus::assertDispatched(SendLeadToHive::class, fn (SendLeadToHive $job): bool => $job->submissionId === $lead->id);
    }

    public function test_a_lead_without_a_name_is_called_by_what_the_team_typed(): void
    {
        Mail::fake();
        Bus::fake();
        $byPhone = $this->tap(TrackedEvent::TYPE_PHONE_CLICK);
        $byEmail = $this->tap(TrackedEvent::TYPE_EMAIL_CLICK, ['label' => 'jenn@gs.construction', 'session_id' => 'visitor-b']);

        $this->makeLead($byPhone, ['phone' => '(630) 555-0142'])->assertCreated()->assertJsonPath('data.contact', '6305550142');
        $this->makeLead($byEmail, ['email' => 'sarah@example.com'])->assertCreated()->assertJsonPath('data.contact', 'sarah@example.com');

        $phoneLead = ContactSubmission::query()->where('analytics_event_id', (string) $byPhone->id)->sole();
        $this->assertSame(['(630) 555-0142', '6305550142', '', 'call'], [$phoneLead->name, $phoneLead->phone, $phoneLead->email, $phoneLead->source]);

        $emailLead = ContactSubmission::query()->where('analytics_event_id', (string) $byEmail->id)->sole();
        $this->assertSame(['sarah@example.com', null, 'sarah@example.com', 'email'], [$emailLead->name, $emailLead->phone, $emailLead->email, $emailLead->source]);
        $this->assertStringStartsWith('Tapped Email (jenn@gs.construction) on the website', $emailLead->message);

        Mail::assertSent(ContactFormSubmission::class, 2);
        Mail::assertNotSent(ContactFormAutoReply::class);
    }

    public function test_the_team_notification_goes_out_for_a_lead_that_has_no_email(): void
    {
        // No Mail::fake(): the array mailer builds the real message, whose
        // reply-to and "Reply to" button used to assume every lead has an email.
        Bus::fake();
        $event = $this->tap(TrackedEvent::TYPE_PHONE_CLICK);

        $this->makeLead($event, ['phone' => '(630) 555-0142'])->assertCreated();

        $message = Mail::mailer()->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $this->assertSame('leads@example.com', $message->getTo()[0]->getAddress());
        $this->assertSame([], $message->getReplyTo());
        $this->assertSame('New Consultation Request from (630) 555-0142', $message->getSubject());
        $this->assertStringContainsString('Tapped Call ((224) 628-4013) on the website', (string) $message->getHtmlBody());
        $this->assertStringNotContainsString('mailto:', (string) $message->getHtmlBody());
    }

    public function test_asking_twice_answers_the_same_lead_and_delivers_it_once(): void
    {
        Mail::fake();
        Bus::fake();
        $event = $this->tap(TrackedEvent::TYPE_PHONE_CLICK);

        $first = $this->makeLead($event, ['phone' => '630-555-0142'])->assertCreated()->json('data');
        $again = $this->makeLead($event, ['name' => 'Someone else', 'phone' => '630-555-9999'])->assertOk()->json('data');

        $this->assertSame($first, $again);
        $this->assertSame(1, ContactSubmission::query()->count());
        Mail::assertSent(ContactFormSubmission::class, 1);
        Bus::assertDispatchedTimes(SendLeadToHive::class, 1);
    }

    public function test_make_lead_needs_a_phone_or_an_email(): void
    {
        Mail::fake();
        Bus::fake();
        $event = $this->tap(TrackedEvent::TYPE_PHONE_CLICK);

        $this->makeLead($event, ['name' => 'Sarah', 'note' => 'No number noted.'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone', 'email']);
        $this->makeLead($event, ['email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        $this->assertSame(0, ContactSubmission::query()->count());
        Mail::assertNothingSent();
        Bus::assertNotDispatched(SendLeadToHive::class);
    }

    public function test_make_lead_refuses_a_row_that_is_not_a_tap_and_an_unknown_id(): void
    {
        Mail::fake();
        Bus::fake();
        $form = $this->tap(TrackedEvent::TYPE_FORM_SUBMIT, ['label' => 'contact']);
        $cta = $this->tap(TrackedEvent::TYPE_CTA_CLICK, ['label' => 'Get a quote', 'session_id' => 'visitor-b']);

        foreach ([$form->id, $cta->id, 999999] as $id) {
            $this->makeLead($id, ['phone' => '630-555-0142'])->assertNotFound();
        }

        $this->assertSame(0, ContactSubmission::query()->count());
        Mail::assertNothingSent();
        Bus::assertNotDispatched(SendLeadToHive::class);
    }

    public function test_events_rows_say_whether_a_lead_can_be_made_and_show_the_one_made(): void
    {
        Mail::fake();
        Bus::fake();
        $call = $this->tap(TrackedEvent::TYPE_PHONE_CLICK);
        $cta = $this->tap(TrackedEvent::TYPE_CTA_CLICK, ['label' => 'Get a quote', 'session_id' => 'visitor-b']);

        $rows = $this->rows();
        $this->assertSame([true, null, null], [$rows[$call->id]['can_make_lead'], $rows[$call->id]['lead_id'], $rows[$call->id]['lead_contact']]);
        $this->assertSame([false, null], [$rows[$cta->id]['can_make_lead'], $rows[$cta->id]['lead_id']]);

        $leadId = $this->makeLead($call, ['phone' => '630-555-0142'])->assertCreated()->json('data.id');

        $rows = $this->rows();
        $this->assertSame([false, $leadId, '6305550142'], [$rows[$call->id]['can_make_lead'], $rows[$call->id]['lead_id'], $rows[$call->id]['lead_contact']]);
    }

    public function test_two_identical_taps_by_one_visitor_on_one_day_are_one_row_and_either_tap_makes_its_lead(): void
    {
        Mail::fake();
        Bus::fake();
        $first = $this->tap(TrackedEvent::TYPE_TEXT_CLICK);
        $second = $this->tap(TrackedEvent::TYPE_TEXT_CLICK, ['created_at' => Carbon::parse('2026-11-09 15:23:16', 'UTC')]);
        // The same tap by somebody else stays a row of its own.
        $other = $this->tap(TrackedEvent::TYPE_TEXT_CLICK, ['session_id' => 'visitor-b', 'ip_address' => '198.51.100.7']);

        $rows = $this->rows();
        $this->assertCount(2, $rows);
        $this->assertArrayNotHasKey($second->id, $rows, 'the second tap is inside the first row');
        $this->assertSame(2, $rows[$first->id]['count']);
        $this->assertSame('2026-11-09T15:23:16+00:00', $rows[$first->id]['last_at']);
        $this->assertSame(1, $rows[$other->id]['count']);

        $leadId = $this->makeLead($second, ['phone' => '630-555-0142'])->assertCreated()->json('data.id');

        $lead = ContactSubmission::query()->findOrFail($leadId);
        $this->assertSame((string) $first->id, $lead->analytics_event_id, 'the lead hangs off the row, i.e. its first tap');
        $this->assertStringContainsString('on the website (2 taps), Nov 9, 2026 9:22–9:23 AM CT.', $lead->message);

        $rows = $this->rows();
        $this->assertSame([false, $leadId], [$rows[$first->id]['can_make_lead'], $rows[$first->id]['lead_id']]);
        $this->assertTrue($rows[$other->id]['can_make_lead']);
        $this->assertSame(1, ContactSubmission::query()->count());
    }

    public function test_another_tenants_lead_never_claims_this_sites_tap(): void
    {
        Mail::fake();
        Bus::fake();
        $other = $this->otherSite();
        $tap = $this->tap(TrackedEvent::TYPE_PHONE_CLICK);

        Tenancy::for($other, function () use ($tap): void {
            ContactSubmission::create([
                'name' => 'Theirs', 'email' => 'theirs@example.com', 'message' => 'Their own lead.', 'analytics_event_id' => (string) $tap->id,
            ]);
        });

        $rows = $this->rows();
        $this->assertSame([true, null], [$rows[$tap->id]['can_make_lead'], $rows[$tap->id]['lead_id']]);

        $leadId = $this->makeLead($tap, ['phone' => '630-555-0142'])->assertCreated()->json('data.id');

        $this->assertSame(Site::query()->where('slug', 'gsc')->value('id'), ContactSubmission::query()->findOrFail($leadId)->site_id);
        $this->assertSame(2, ContactSubmission::withoutSiteScope()->where('analytics_event_id', (string) $tap->id)->count());
    }
}
