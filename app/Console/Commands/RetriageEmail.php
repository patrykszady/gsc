<?php

namespace App\Console\Commands;

use App\Models\ContactSubmission;
use App\Models\EmailLeadIngest;
use App\Services\EmailLeadReader;
use App\Services\HiveProjectsClient;
use Illuminate\Console\Command;

/**
 * Email submissions filed before a rule existed — a supplier's side of a
 * purchase, a retailer's return confirmation — judged again by the rules
 * that need no headers, and the refused ones taken back: the submission
 * here, the lead on hive, the ledger marked skipped with the reason. Safe
 * to run again.
 */
class RetriageEmail extends Command
{
    protected $signature = 'leads:retriage-email
        {--apply : Write the changes (without this it only reports what it would do)}
        {--limit=500 : Most email submissions to examine, newest first}';

    protected $description = 'Take back email submissions the reader would refuse today: automated senders, suppliers, our own mail';

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
            $reason = $reader->retriageStored((string) $submission->email, (string) $submission->subject, (string) $submission->message, (string) ($row?->mailbox ?? ''), $row?->grant_id);

            if ($reason === null) {
                continue;
            }

            $found++;
            $this->line(sprintf('submission %d [%s]: %s <%s> — %s%s', $submission->id, $reason, $submission->name, $submission->email, mb_substr((string) $submission->subject, 0, 60), $apply ? '' : ' [preview]'));

            if (! $apply) {
                continue;
            }

            if ($submission->hive_lead_id && $hive->isConfigured() && ! $hive->deleteLead((int) $submission->hive_lead_id)) {
                $this->warn("  hive still has lead {$submission->hive_lead_id}; left the submission for another run");

                continue;
            }

            $row?->forceFill(['status' => EmailLeadIngest::STATUS_SKIPPED, 'skip_reason' => $reason, 'is_lead' => false, 'submission_id' => null])->save();
            $submission->delete();
            $removed++;
            $this->line('  removed'.($submission->hive_lead_id ? " (hive lead {$submission->hive_lead_id} too)" : ''));
        }

        $this->info($apply
            ? "{$found} submission".($found === 1 ? '' : 's').' refused, '.$removed.' removed.'
            : "{$found} submission".($found === 1 ? '' : 's').' would be removed. Run with --apply to write.');

        return self::SUCCESS;
    }
}
