<?php

namespace App\Services;

use App\Jobs\SendLeadToHive;
use App\Mail\ContactFormSubmission;
use App\Models\ContactSubmission;
use App\Models\Site;
use App\Models\TrackedEvent;
use App\Support\LeadInbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use SsSystems\Platform\Http\Admin\AnalyticsLeadMessage;
use SsSystems\Platform\Http\Admin\Contracts\AnalyticsLeadMaker;
use Throwable;

/**
 * "Make lead" on the hub's Analytics screen (2026-11-10): a visitor tapped
 * Call, Text or Email on the site and then reached the team off it, so the
 * team fills in who it was and the tap becomes a ContactSubmission — made
 * and delivered the way Livewire\ContactSection stores a form lead, for the
 * tenant the admin API is pinned to (BelongsToSite stamps site_id):
 *
 *   - the row the form writes: status pending and never spam-scored (the
 *     team typed it), the message AnalyticsLeadMessage words the same on
 *     every site, source call/text/email, the tap's referrer and UTM tags,
 *     the visitor's IP and browser read off the tracked event itself (the
 *     request here is the hub's, not the visitor's), filed at the first tap;
 *   - the team's lead notification to LeadInbox::recipients(), then the
 *     hand-off to hive.contractors behind the form's own default-site gate.
 *
 * Nothing addressed to the visitor runs — no ContactFormAutoReply: they
 * never filled a form, and a lead from a call may have no email at all.
 */
class AnalyticsEventLeadMaker implements AnalyticsLeadMaker
{
    public function leadsForEvents(array $eventIds): array
    {
        return ContactSubmission::query()
            ->whereIn('analytics_event_id', $eventIds)
            ->orderBy('id')
            ->get(['id', 'analytics_event_id', 'name', 'email', 'phone'])
            ->mapWithKeys(fn (ContactSubmission $lead) => [(string) $lead->analytics_event_id => $this->leadArray($lead)])
            ->all();
    }

    public function makeLead(array $event, array $input): array
    {
        $tap = TrackedEvent::query()->find($event['id']);
        $email = (string) ($input['email'] ?? '');

        $submission = ContactSubmission::create([
            'name' => $input['name'] ?? ($input['phone'] ?? $email),
            'email' => $email,
            'phone' => $this->phone($input['phone'] ?? null),
            'message' => AnalyticsLeadMessage::for($event, $input['note'] ?? null, $this->timezone()),
            'source' => AnalyticsLeadMessage::origin((string) $event['type']) ?? 'web',
            'referrer' => $event['referrer'] ?? null,
            'user_agent' => $tap?->user_agent !== null ? Str::limit((string) $tap->user_agent, 255, '') : null,
            'ip_address' => $tap?->ip_address,
            'status' => 'pending',
            'utm_source' => $event['utm_source'] ?? null,
            'utm_medium' => $event['utm_medium'] ?? null,
            'utm_campaign' => $event['utm_campaign'] ?? null,
            'analytics_event_id' => (string) $event['id'],
        ]);

        $submission->forceFill([
            'created_at' => Carbon::parse($event['created_at'])->setTimezone(config('app.timezone')),
        ])->saveQuietly();

        $this->deliver($submission, (string) ($input['phone'] ?? ''));

        return $this->leadArray($submission);
    }

    /**
     * What ContactSection::submit() does once a real lead is stored, minus
     * the visitor's auto-reply: the team's notification (a failed send is
     * logged, never a lost lead), then hive.contractors on the default site.
     */
    protected function deliver(ContactSubmission $submission, string $phoneAsTyped): void
    {
        $inbox = LeadInbox::recipients();

        if ($inbox === []) {
            Log::error('Analytics lead: no lead inbox configured for this site', [
                'site' => Site::current()->slug,
                'submission_id' => $submission->id,
            ]);
        } else {
            try {
                Mail::to($inbox)->send(new ContactFormSubmission(
                    name: $submission->name,
                    email: $submission->email,
                    phone: $phoneAsTyped,
                    address: '',
                    userMessage: $submission->message,
                ));
            } catch (Throwable $e) {
                Log::error('Analytics lead: lead notification could not be sent', [
                    'site' => Site::current()->slug,
                    'submission_id' => $submission->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (Site::current()->slug === (string) config('sites.default')) {
            SendLeadToHive::dispatch($submission->id)->afterCommit();
        }

        Log::channel('submissions')->info('Lead made from an analytics tap', [
            'submission_id' => $submission->id,
            'analytics_event_id' => $submission->analytics_event_id,
            'source' => $submission->source,
        ]);
    }

    /**
     * Digits only, sized for the column, as the form stores a phone; what
     * was typed when it holds no digits at all.
     */
    protected function phone(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $digits = (string) preg_replace('/\D+/', '', $value);

        return Str::limit($digits !== '' ? $digits : $value, 20, '');
    }

    /** This site's own zone, read the way the contact form reads it (ContactSection::businessTimezone()). */
    protected function timezone(): string
    {
        return (string) (config('brand.timezone') ?: config('services.analytics.timezone', 'America/Chicago'));
    }

    /**
     * The lead as the kit reports it: its id and the value the hub searches
     * Leads by to open it — the phone as stored (digits), else the email,
     * else the name.
     *
     * @return array{id: int, contact: ?string}
     */
    protected function leadArray(ContactSubmission $lead): array
    {
        return [
            'id' => (int) $lead->id,
            'contact' => $lead->phone ?: ($lead->email ?: ($lead->name ?: null)),
        ];
    }
}
