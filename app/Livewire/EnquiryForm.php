<?php

namespace App\Livewire;

use App\Mail\ContactFormAutoReply;
use App\Mail\ContactFormSubmission;
use App\Models\ContactSubmission;
use App\Support\LeadInbox;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Component;

/**
 * A plain enquiry form, for a tenant that does not book crew visits.
 *
 * ContactSection is gs.construction's: it asks for a street address, resolves
 * the town, and makes the visitor pick two days and three time windows so the
 * crew can schedule a site visit. An interior design studio has none of that —
 * the enquiry is a conversation, and a form that demands a booking window
 * before it will send is a form people abandon.
 *
 * What it keeps from the bigger form: the honeypot, the "submitted within
 * three seconds" check, and storing every submission. Storage is site-scoped
 * by BelongsToSite, so the lead belongs to the tenant whose page it came from,
 * and the notification goes to that tenant's own inbox (App\Support\LeadInbox).
 */
class EnquiryForm extends Component
{
    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $market = '';

    public string $message = '';

    /** Honeypot: a human never sees this field, a bot fills everything. */
    public string $website = '';

    public int $formLoadedAt = 0;

    public bool $sent = false;

    public function mount(): void
    {
        $this->formLoadedAt = time();
    }

    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'market' => ['nullable', 'string', 'max:60'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ];
    }

    public function submit(): void
    {
        $this->validate();

        // A bot fills the hidden field, or answers faster than anyone reads.
        // Say "sent" either way: telling a spammer what caught them is how the
        // next attempt gets past it.
        if ($this->website !== '' || (time() - $this->formLoadedAt) < 3) {
            $this->store('spam', $this->website !== '' ? 'honeypot_filled' : 'submitted_too_fast');
            $this->finish();

            return;
        }

        $submission = $this->store();

        $recipients = LeadInbox::recipients();

        if ($recipients === []) {
            Log::error('Enquiry form: no lead inbox configured for this site', [
                'site' => $submission?->site_id,
            ]);
        } else {
            Mail::to($recipients)->send(new ContactFormSubmission(
                name: $this->name,
                email: $this->email,
                phone: $this->phone,
                address: $this->market,
                userMessage: $this->message,
                availability: [],
                area: $this->market ?: null,
            ));

            Mail::to($this->email)->send(new ContactFormAutoReply(name: $this->name));
        }

        $this->finish();
    }

    protected function store(string $status = 'pending', ?string $spamReason = null): ?ContactSubmission
    {
        try {
            // Deliberately no SendLeadToHive: hive.contractors is GS
            // Construction's lead pipeline, not every tenant's.
            return ContactSubmission::create([
                'name' => $this->name,
                'email' => $this->email,
                'phone' => preg_replace('/\D+/', '', $this->phone) ?: null,
                'message' => $this->message,
                'city' => $this->market ?: null,
                'source' => 'web',
                'referrer' => request()->header('referer'),
                'user_agent' => request()->userAgent(),
                'ip_address' => request()->ip(),
                'status' => $status,
                'spam_reason' => $spamReason,
                'utm_source' => session('utm_source') ?? request()->input('utm_source'),
                'utm_medium' => session('utm_medium') ?? request()->input('utm_medium'),
                'utm_campaign' => session('utm_campaign') ?? request()->input('utm_campaign'),
            ]);
        } catch (\Throwable $e) {
            // A database that refuses the row must not cost the enquiry: the
            // mail above still goes out, and this is in the log.
            Log::error('Enquiry form: could not store the submission', ['error' => $e->getMessage()]);

            return null;
        }
    }

    protected function finish(): void
    {
        $this->reset(['name', 'email', 'phone', 'market', 'message', 'website']);
        $this->sent = true;
    }

    public function render()
    {
        return view('livewire.enquiry-form', [
            'markets' => collect(config('markets.list', []))->pluck('label', 'label')->all(),
        ]);
    }
}
