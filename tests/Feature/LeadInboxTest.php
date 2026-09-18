<?php

namespace Tests\Feature;

use App\Livewire\ContactSection;
use App\Mail\ContactFormSubmission;
use App\Models\Site;
use App\Support\LeadInbox;
use App\Support\Tenancy;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Leads go to the site that earned them. The contact form used to mail
 * `mail.from.address` — one inbox for the whole deployment, gs.construction's
 * — so another business's enquiries would have arrived somewhere she cannot
 * read, behind a "thank you" that says they went through.
 */
class LeadInboxTest extends TestCase
{
    /**
     * The booking test freezes the clock. If an assertion inside it fails, an
     * inline reset never runs and every later test in that paratest worker
     * sees 2026-08-03 — which is how a frozen clock from one failing test
     * turns into unrelated date failures elsewhere in the suite.
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function jpd(): Site
    {
        return Site::query()->where('slug', 'jpeterson')->firstOrFail();
    }

    public function test_the_default_site_keeps_mailing_its_from_address(): void
    {
        config(['mail.from.address' => 'crew@gs.construction', 'brand.lead_email' => null]);

        $this->assertSame('crew@gs.construction', LeadInbox::address(Site::current()));
        $this->assertFalse(LeadInbox::isSharedWithDefaultSite(Site::current()));
    }

    public function test_another_tenant_uses_its_own_address_never_the_deployments(): void
    {
        Tenancy::for($this->jpd(), function (Site $site): void {
            $address = LeadInbox::address($site);

            $this->assertSame(config('brand.email'), $address);
            $this->assertNotSame(config('mail.from.address'), $address);
            $this->assertFalse(LeadInbox::isSharedWithDefaultSite($site));
        });
    }

    public function test_an_explicit_lead_email_wins(): void
    {
        Tenancy::for($this->jpd(), function (Site $site): void {
            config(['brand.lead_email' => 'studio@example-jpd.com']);

            $this->assertSame('studio@example-jpd.com', LeadInbox::address($site));
        });
    }

    public function test_the_contact_form_mails_the_tenants_own_inbox(): void
    {
        Mail::fake();
        config(['mail.from.address' => 'crew@gs.construction']);
        // The form refuses to send without the booking minimums, and the first
        // bookable day is three business days out — a Monday, so Thursday.
        Carbon::setTestNow(Carbon::parse('2026-08-03 09:00', 'America/Chicago'));

        Tenancy::for($this->jpd(), function (): void {
            Livewire::test(ContactSection::class)
                ->call('toggleTime', '2026-08-06', '9-11 AM')
                ->call('toggleTime', '2026-08-06', '11-1 PM')
                ->call('toggleTime', '2026-08-07', 'Anytime')
                // The form treats a submit within 3 seconds of load as a bot.
                ->set('formLoadedAt', time() - 30)
                ->set('name', 'Test Visitor')
                ->set('email', 'visitor@example.com')
                ->set('phone', '(224) 555-0111')
                ->set('address', '123 Example Street, Chicago, IL')
                ->set('message', 'I would like help planning a kitchen, please.')
                ->call('submit')
                ->assertHasNoErrors();
        });

        Mail::assertSent(ContactFormSubmission::class, fn ($mail): bool => $mail->hasTo('hello@jpeterson-design.com'));
        Mail::assertNotSent(ContactFormSubmission::class, fn ($mail): bool => $mail->hasTo('crew@gs.construction'));
    }

    public function test_a_tenant_pointed_at_the_default_sites_inbox_is_caught(): void
    {
        Tenancy::for($this->jpd(), function (Site $site): void {
            config(['brand.lead_email' => config('mail.from.address')]);
            $this->assertTrue(LeadInbox::isSharedWithDefaultSite($site));

            config(['brand.lead_email' => '', 'brand.email' => '']);
            $this->assertTrue(LeadInbox::isSharedWithDefaultSite($site), 'no address at all is not "fine"');
        });
    }
}
