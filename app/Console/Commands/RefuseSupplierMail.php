<?php

namespace App\Console\Commands;

use App\Models\ContactSubmission;
use App\Models\EmailLeadIngest;
use App\Services\EmailLeadReader;
use App\Services\HiveProjectsClient;
use Illuminate\Console\Command;

/**
 * Email submissions filed before the reader learnt to recognise a
 * supplier's side of a purchase ("Hi Patryk, your revised quote…"): judge
 * them again by that rule, and take back the ones it refuses — the
 * submission here, the lead on hive, the ledger marked as skipped. Safe to
 * run again.
 */
class RefuseSupplierMail extends Command
{
    protected $signature = 'leads:refuse-supplier-mail
        {--apply : Write the changes (without this it only reports what it would do)}
        {--limit=500 : Most email submissions to examine, newest first}';

    protected $description = 'Take back email submissions that are a supplier writing to us, not an enquiry';

    public function handle(EmailLeadReader $reader, HiveProjectsClient $hive): int
    {
        $apply = (bool) $this->option('apply');
        $found = 0;
        $removed = 0;

        $submissions = ContactSubmission::withoutSiteScope()
            ->where('source', EmailLeadReader::SOURCE)
            ->latest('id')
            ->limit((int) $this->option('limit'))
            ->get();

        foreach ($submissions as $submission) {
            $row = EmailLeadIngest::where('submission_id', $submission->id)->first();
            $mailbox = (string) ($row?->mailbox ?? '');

            if (! $reader->looksLikeSupplierMail((string) $submission->subject, (string) $submission->message, $mailbox, $row?->grant_id)) {
                continue;
            }

            $found++;
            $this->line(sprintf('submission %d: %s <%s> — %s%s', $submission->id, $submission->name, $submission->email, mb_substr((string) $submission->subject, 0, 60), $apply ? '' : ' [preview]'));

            if (! $apply) {
                continue;
            }

            if ($submission->hive_lead_id && $hive->isConfigured() && ! $hive->deleteLead((int) $submission->hive_lead_id)) {
                $this->warn("  hive still has lead {$submission->hive_lead_id}; left the submission for another run");

                continue;
            }

            $row?->forceFill(['status' => EmailLeadIngest::STATUS_SKIPPED, 'skip_reason' => 'supplier', 'is_lead' => false, 'submission_id' => null])->save();
            $submission->delete();
            $removed++;
            $this->line('  removed'.($submission->hive_lead_id ? " (hive lead {$submission->hive_lead_id} too)" : ''));
        }

        $this->info($apply
            ? "{$found} supplier message".($found === 1 ? '' : 's').' found, '.$removed.' removed.'
            : "{$found} supplier message".($found === 1 ? '' : 's').' would be removed. Run with --apply to write.');

        return self::SUCCESS;
    }
}
