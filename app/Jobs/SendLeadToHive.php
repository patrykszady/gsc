<?php

namespace App\Jobs;

use App\Models\ContactSubmission;
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

        $payload = [
            'external_id' => (string) $submission->id,
            'name' => $submission->name,
            'email' => $submission->email,
            'phone' => $submission->phone,
            'address' => $submission->address,
            'city' => $submission->city,
            'message' => $submission->message,
            'availability' => $submission->availability,
            'source' => 'gs.construction',
            'referrer' => $submission->referrer,
            'ip_address' => $submission->ip_address,
            'user_agent' => $submission->user_agent,
            'utm_source' => $submission->utm_source,
            'utm_medium' => $submission->utm_medium,
            'utm_campaign' => $submission->utm_campaign,
            'submitted_at' => optional($submission->created_at)->toIso8601String(),
        ];

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
