<?php

namespace Tests\Feature;

use App\Livewire\ContactSection;
use App\Livewire\EnquiryForm;
use App\Models\ContactSubmission;
use App\Models\Site;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * An enquiry is worth more than the email about it.
 *
 * Both forms send over SMTP inside the request. Expired credentials, an
 * unreachable host or a provider rate limit throws, and the contact form used
 * to send before it stored: the visitor got a Livewire error and the enquiry
 * was never written down. Now the row is written first and a failed send is a
 * line in the log.
 */
class LeadSurvivesMailFailureTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Every Mail::to() throws, the way a dead SMTP host does. */
    private function breakTheMailer(): void
    {
        Mail::swap(new class
        {
            public function to($address)
            {
                throw new \RuntimeException('SMTP is down');
            }
        });
    }

    public function test_the_contact_form_keeps_the_lead_when_mail_fails(): void
    {
        $this->breakTheMailer();
        Carbon::setTestNow(Carbon::parse('2026-08-03 09:00', 'America/Chicago'));

        Livewire::test(ContactSection::class)
            ->call('toggleTime', '2026-08-06', '9-11 AM')
            ->call('toggleTime', '2026-08-06', '11-1 PM')
            ->call('toggleTime', '2026-08-07', 'Anytime')
            ->set('formLoadedAt', time() - 30)
            ->set('name', 'Test Person')
            ->set('email', 'visitor@example.com')
            ->set('phone', '(224) 555-0123')
            ->set('address', '123 Example Street, Arlington Heights, IL')
            ->set('message', 'We would like a quote for our kitchen remodel please.')
            ->call('submit')
            ->assertHasNoErrors();

        $lead = ContactSubmission::withoutSiteScope()->latest('id')->first();
        $this->assertNotNull($lead, 'the enquiry is stored even though no mail could go out');
        $this->assertSame('visitor@example.com', $lead->email);
        $this->assertSame('pending', $lead->status);
    }

    public function test_the_studio_form_keeps_the_lead_when_mail_fails(): void
    {
        $this->breakTheMailer();
        $site = Site::query()->where('slug', 'jpeterson')->firstOrFail();

        Tenancy::for($site, function (): void {
            Livewire::test(EnquiryForm::class)
                ->set('formLoadedAt', time() - 30)
                ->set('name', 'Test Visitor')
                ->set('email', 'visitor@example.com')
                ->set('message', 'We are renovating a kitchen and would like help with it.')
                ->call('submit')
                ->assertHasNoErrors()
                ->assertSet('sent', true);
        });

        $lead = ContactSubmission::withoutSiteScope()->latest('id')->first();
        $this->assertNotNull($lead);
        $this->assertSame($site->id, $lead->site_id);
    }
}
