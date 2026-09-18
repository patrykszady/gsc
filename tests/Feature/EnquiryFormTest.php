<?php

namespace Tests\Feature;

use App\Jobs\SendLeadToHive;
use App\Livewire\EnquiryForm;
use App\Mail\ContactFormAutoReply;
use App\Mail\ContactFormSubmission;
use App\Models\ContactSubmission;
use App\Models\Site;
use App\Support\Tenancy;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The studio's own inquiry form. gs.construction's form books a crew visit —
 * street address, two days, three time windows — which is not how anyone
 * inquires about interior design. This one asks for a message.
 */
class EnquiryFormTest extends TestCase
{
    private function jpd(): Site
    {
        $site = Site::query()->where('slug', 'jpeterson')->firstOrFail();
        $site->forceFill(['is_active' => true])->save();
        Site::forgetActive();

        return $site->fresh();
    }

    public function test_the_studios_contact_page_carries_the_form(): void
    {
        $this->jpd();

        $this->get('https://jpeterson-design.com/contact')
            ->assertOk()
            ->assertSee('About your project')
            ->assertDontSee('Coming soon');
    }

    public function test_an_enquiry_is_stored_for_that_tenant_and_mailed_to_both_designers(): void
    {
        Mail::fake();
        Bus::fake();
        $site = $this->jpd();

        Tenancy::for($site, function (): void {
            Livewire::test(EnquiryForm::class)
                ->set('formLoadedAt', time() - 30)
                ->set('name', 'Test Visitor')
                ->set('email', 'visitor@example.com')
                ->set('phone', '(312) 555-0142')
                ->set('market', 'Chicago, IL')
                ->set('message', 'We are renovating a kitchen and would like help with it.')
                ->call('submit')
                ->assertHasNoErrors()
                ->assertSet('sent', true);
        });

        $lead = ContactSubmission::withoutSiteScope()->latest('id')->first();
        $this->assertNotNull($lead);
        $this->assertSame($site->id, $lead->site_id, 'the lead belongs to the site it came from');
        $this->assertSame('pending', $lead->status);

        Mail::assertSent(ContactFormSubmission::class, fn ($mail): bool => $mail->hasTo('jenn@jpeterson-design.com') && $mail->hasTo('jill@jpeterson-design.com'));
        Mail::assertNotSent(ContactFormSubmission::class, fn ($mail): bool => $mail->hasTo(config('mail.from.address')));
        Mail::assertSent(ContactFormAutoReply::class, fn ($mail): bool => $mail->hasTo('visitor@example.com'));

        // hive.contractors is gs.construction's pipeline.
        Bus::assertNotDispatched(SendLeadToHive::class);
    }

    public function test_the_auto_reply_names_the_site_that_was_written_to(): void
    {
        $this->jpd();

        Tenancy::for(Site::query()->where('slug', 'jpeterson')->firstOrFail(), function (): void {
            $this->assertSame(
                'Thanks for reaching out to J. Peterson Design',
                (new ContactFormAutoReply(name: 'Test'))->envelope()->subject,
            );
        });

        $this->assertStringContainsString(
            'GS Construction',
            (new ContactFormAutoReply(name: 'Test'))->envelope()->subject,
            'the default site keeps its own wording',
        );
    }

    public function test_a_bot_is_recorded_as_spam_and_nothing_is_mailed(): void
    {
        Mail::fake();
        $site = $this->jpd();

        Tenancy::for($site, function (): void {
            Livewire::test(EnquiryForm::class)
                ->set('formLoadedAt', time() - 30)
                ->set('name', 'Bot')
                ->set('email', 'bot@example.com')
                ->set('message', 'Buy cheap backlinks from our agency today.')
                ->set('website', 'http://spam.example')
                ->call('submit')
                ->assertSet('sent', true);
        });

        $this->assertSame('spam', ContactSubmission::withoutSiteScope()->latest('id')->first()?->status);
        Mail::assertNothingSent();
    }
}
