<?php

namespace App\Jobs;

use App\Models\ContactSubmission;
use App\Models\EmailLeadIngest;
use App\Services\EmailLeadReader;
use App\Services\HiveProjectsClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * POST a contact-form lead to hive.contractors.
 *
 * Hive's Lead model is the source of truth; we dedupe with `external_id`
 * (= gsc contact_submissions.id) so retries / resends are safe.
 *
 * Failure handling:
 *   - HTTP/network errors → re-thrown so the job retries with backoff.
 *   - Final failure (after $tries) records the error on the submission so
 *     the operator can resend via `php artisan hive:resend-leads`.
 */
class SendLeadToHive implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 30;
    /** @var array<int,int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public int $submissionId) {}

    public function handle(HiveProjectsClient $hive): void
    {
        // Quietly no-op if Hive isn't configured — keeps local/dev runs clean.
        if (! config('services.hive.token') || ! config('services.hive.url')) {
            return;
        }

        $submission = ContactSubmission::query()->find($this->submissionId);
        if (! $submission) {
            return;
        }
        // Idempotency: don't re-send if already accepted by Hive.
        if ($submission->hive_sent_at !== null && $submission->hive_lead_id) {
            return;
        }

        $isEmail = $submission->source === EmailLeadReader::SOURCE;
        $extracted = (array) ($submission->extracted ?? []);

        $payload = [
            // Hive identifies a lead by (source, external_id). An email
            // enquiry's identity is its RFC Message-ID hash — the very value
            // hive's own crew@ reader keyed its leads by, so the two readers
            // can never make twins of one email.
            'external_id' => $isEmail && $submission->email_message_id ? $submission->email_message_id : (string) $submission->id,
            'name' => $submission->name,
            'email' => $submission->email,
            'phone' => $submission->phone,
            'address' => $submission->address,
            'city' => $submission->city,
            'state' => $submission->state,
            'zip' => $submission->zip,
            'subject' => $submission->subject,
            'message' => $submission->message,
            'availability' => $submission->availability,
            // Hive shows this as the lead's origin. Website leads are the
            // site; a Yelp Request-a-Quote lead says so; an email says so.
            'source' => match ($submission->source) {
                'yelp' => 'yelp',
                EmailLeadReader::SOURCE => EmailLeadReader::SOURCE,
                default => 'gs.construction',
            },
            // Photos and documents, by public URL — hive copies them onto
            // the lead. What the classifier read out of an email (project
            // type, scope, timeline, budget, partner addresses, its own
            // verdict) rides along so hive neither re-asks the model nor
            // loses the detail.
            'attachments' => $submission->attachmentsForApi(),
            'extracted' => $extracted,
            // The email's RFC Message-ID, so hive's missing-info reply
            // threads under the enquiry in the sender's mailbox.
            'in_reply_to' => $isEmail
                ? EmailLeadIngest::where('submission_id', $submission->id)->value('rfc_message_id')
                : null,
            'referrer' => $submission->referrer,
            'ip_address' => $submission->ip_address,
            'user_agent' => $submission->user_agent,
            'utm_source' => $submission->utm_source,
            'utm_medium' => $submission->utm_medium,
            'utm_campaign' => $submission->utm_campaign,
            'submitted_at' => optional($submission->created_at)->toIso8601String(),
        ];
        $payload = array_filter($payload, fn ($v) => $v !== null && $v !== []);

        $leadId = $hive->submitLead($payload);

        $submission->forceFill([
            'hive_sent_at' => now(),
            'hive_lead_id' => $leadId,
            'hive_send_error' => null,
        ])->save();

        Log::channel('submissions')->info('Lead sent to hive.contractors', [
            'submission_id' => $submission->id,
            'hive_lead_id' => $leadId,
        ]);
    }

    public function failed(?Throwable $e = null): void
    {
        $submission = ContactSubmission::query()->find($this->submissionId);
        if (! $submission) {
            return;
        }
        $submission->forceFill([
            'hive_send_error' => mb_substr((string) ($e?->getMessage() ?? 'unknown error'), 0, 500),
        ])->save();

        Log::channel('submissions')->error('Lead post to hive.contractors gave up after retries', [
            'submission_id' => $submission->id,
            'error' => $e?->getMessage(),
        ]);
    }
}
