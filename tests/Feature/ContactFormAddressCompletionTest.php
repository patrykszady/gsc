<?php

namespace Tests\Feature;

use App\Livewire\ContactSection;
use App\Models\ContactSubmission;
use App\Services\LeadAddressCompleter;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * Capture-time wiring: ContactSection::storeSubmission() runs the address
 * through LeadAddressCompleter, but the contact form must NEVER fail (or
 * even degrade) because Geoapify is slow, down, or refuses the address.
 */
class ContactFormAddressCompletionTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    /** A submission with a valid schedule and all required fields. */
    private function submitForm(array $overrides = [])
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 09:00', 'America/Chicago'));
        Mail::fake();

        $component = Livewire::test(ContactSection::class)
            // Anti-bot "submitted too fast" heuristic needs >=3 real seconds
            // between mount() and submit(); it reads PHP's time(), which
            // Carbon::setTestNow() (used above for the calendar) does not
            // affect, so it's backdated directly here instead of sleeping.
            ->set('formLoadedAt', time() - 10)
            ->call('toggleTime', '2026-08-06', '7-9 AM')
            ->call('toggleTime', '2026-08-06', '9-11 AM')
            ->call('toggleTime', '2026-08-07', '1-3 PM')
            ->set('name', $overrides['name'] ?? 'Jane Doe')
            ->set('email', $overrides['email'] ?? 'jane@example.com')
            ->set('phone', '(224) 555-0123')
            ->set('address', $overrides['address'] ?? '511 Sherwood Dr')
            ->set('message', $overrides['message'] ?? 'We would like a quote for our kitchen remodel please.');

        return $component->call('submit');
    }

    public function test_a_geocoder_failure_still_saves_the_lead(): void
    {
        $completer = Mockery::mock(LeadAddressCompleter::class);
        $completer->shouldReceive('complete')->once()->andThrow(new \RuntimeException('Geoapify unreachable'));
        $this->app->instance(LeadAddressCompleter::class, $completer);

        $this->submitForm(['address' => '511 Sherwood Dr'])->assertHasNoErrors();

        $submission = ContactSubmission::where('email', 'jane@example.com')->firstOrFail();
        // Saved with exactly what the sender typed — completion failed silently.
        $this->assertSame('511 Sherwood Dr', $submission->address);
        $this->assertNull($submission->city);
        $this->assertNull($submission->state);
        $this->assertNull($submission->zip);
    }

    public function test_completed_parts_are_persisted_on_the_new_lead(): void
    {
        $completer = Mockery::mock(LeadAddressCompleter::class);
        $completer->shouldReceive('complete')->once()->andReturnUsing(function (array $data) {
            $data['address'] = '511 Sherwood Dr';
            $data['city'] = 'Addison';
            $data['state'] = 'IL';
            $data['zip'] = '60101';

            return $data;
        });
        $this->app->instance(LeadAddressCompleter::class, $completer);

        $this->submitForm(['address' => '511 Sherwood Dr', 'email' => 'jane2@example.com'])->assertHasNoErrors();

        $submission = ContactSubmission::where('email', 'jane2@example.com')->firstOrFail();
        $this->assertSame('Addison', $submission->city);
        $this->assertSame('IL', $submission->state);
        $this->assertSame('60101', $submission->zip);
        $this->assertSame('511 Sherwood Dr, Addison, IL 60101', $submission->formattedAddress());
    }

    public function test_a_spam_caught_submission_never_calls_the_completer(): void
    {
        // Not worth a paid Geoapify call for a honeypot-caught bot.
        $completer = Mockery::mock(LeadAddressCompleter::class);
        $completer->shouldNotReceive('complete');
        $this->app->instance(LeadAddressCompleter::class, $completer);

        Carbon::setTestNow(Carbon::parse('2026-08-03 09:00', 'America/Chicago'));
        Mail::fake();

        Livewire::test(ContactSection::class)
            ->set('formLoadedAt', time() - 10)
            ->call('toggleTime', '2026-08-06', '7-9 AM')
            ->call('toggleTime', '2026-08-06', '9-11 AM')
            ->call('toggleTime', '2026-08-07', '1-3 PM')
            ->set('name', 'Spam Bot')
            ->set('email', 'spambot@example.com')
            ->set('phone', '(224) 555-0123')
            ->set('address', '511 Sherwood Dr')
            ->set('message', 'We would like a quote for our kitchen remodel please.')
            ->set('website', 'https://spam.example') // honeypot
            ->call('submit');

        $this->assertSame('spam', ContactSubmission::where('email', 'spambot@example.com')->firstOrFail()->status);
    }
}
