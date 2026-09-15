<?php

namespace App\Console\Commands;

use App\Models\EmailLeadIngest;
use App\Services\EmailLeadReader;
use Illuminate\Console\Command;

/**
 * Read the team's inboxes for new enquiries and file each as a contact
 * submission (see EmailLeadReader). Scheduled every five minutes.
 */
class IngestEmailLeads extends Command
{
    protected $signature = 'leads:ingest-email
        {--dry-run : Judge each message and report, without writing anything}
        {--limit= : Messages per inbox this run}
        {--days= : Look back this many days instead of from the last run}
        {--reprocess=* : Ledger row ids to fetch and judge again}';

    protected $description = 'Turn enquiries emailed to crew@, patryk@ and greg@ into contact submissions';

    public function handle(EmailLeadReader $reader): int
    {
        if ($reader->inboxes() === []) {
            $this->error('No inboxes configured — set EMAIL_LEADS_INBOXES to "mailbox|grant_id" pairs.');

            return self::FAILURE;
        }

        if ($ids = array_filter(array_map('intval', (array) $this->option('reprocess')))) {
            foreach (EmailLeadIngest::whereIn('id', $ids)->get() as $row) {
                $result = $reader->reprocessLedgerRow($row);
                $this->line(sprintf('  #%d %s → %s', $row->id, $row->subject ?? '', $this->describe($result)));
            }

            return self::SUCCESS;
        }

        $since = $this->option('days') !== null ? now()->subDays((int) $this->option('days')) : null;

        $result = $reader->ingest(
            dryRun: (bool) $this->option('dry-run'),
            limit: $this->option('limit') !== null ? (int) $this->option('limit') : null,
            since: $since,
        );

        $this->info(sprintf(
            '%s%d inbox(es) · %d message(s) · %d lead(s) · %d skipped · %d failed',
            $this->option('dry-run') ? '[dry run] ' : '',
            $result['inboxes'],
            $result['fetched'],
            $result['leads'],
            $result['skipped'],
            $result['failed'],
        ));

        foreach ($result['details'] as $detail) {
            $this->line(sprintf(
                '  %-24s %-9s %-16s %s  %s',
                $detail['mailbox'] ?? '',
                $detail['status'],
                $detail['reason'] ?? ($detail['submission_id'] ?? null ? '#'.$detail['submission_id'] : ''),
                $detail['from'] ?? '',
                $detail['subject'] ?? '',
            ));
        }

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $result */
    protected function describe(array $result): string
    {
        return trim($result['status'].' '.($result['reason'] ?? '').(isset($result['submission_id']) ? ' #'.$result['submission_id'] : ''));
    }
}
