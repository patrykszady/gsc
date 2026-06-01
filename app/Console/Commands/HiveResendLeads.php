<?php

namespace App\Console\Commands;

use App\Jobs\SendLeadToHive;
use App\Models\ContactSubmission;
use Illuminate\Console\Command;

class HiveResendLeads extends Command
{
    protected $signature = 'hive:resend-leads
        {--id= : Resend a single submission by id (overrides other filters)}
        {--last : Resend just the most recent pending submission}
        {--limit=100 : Max submissions to redispatch in this run}
        {--include-failed : Also resend rows with hive_send_error set (exhausted retries)}
        {--force : Resend even if already accepted by Hive (clears hive_sent_at first)}
        {--sync : Run the job synchronously instead of dispatching to the queue}';

    protected $description = 'Re-dispatch SendLeadToHive jobs for non-spam submissions never accepted by Hive.';

    public function handle(): int
    {
        $q = ContactSubmission::query()->where('status', 'pending');

        if ($id = $this->option('id')) {
            $q->where('id', (int) $id);
        } elseif ($this->option('last')) {
            $q->orderByDesc('id')->limit(1);
        } else {
            $q->whereNull('hive_sent_at');
            if (! $this->option('include-failed')) {
                $q->whereNull('hive_send_error');
            }
            $q->orderBy('id')->limit((int) $this->option('limit'));
        }

        $rows = $q->get();
        if ($rows->isEmpty()) {
            $this->info('No leads matched.');
            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            if ($this->option('force') && $row->hive_sent_at) {
                $row->forceFill(['hive_sent_at' => null, 'hive_lead_id' => null])->save();
            }

            $line = sprintf(
                '  → submission #%d  %s  <%s>  city=%s  hive_sent_at=%s  err=%s',
                $row->id,
                $row->name ?: '(no name)',
                $row->email ?: '-',
                $row->city ?: '-',
                $row->hive_sent_at?->toDateTimeString() ?: 'null',
                $row->hive_send_error ? mb_substr($row->hive_send_error, 0, 60) : '-'
            );
            $this->line($line);

            if ($this->option('sync')) {
                SendLeadToHive::dispatchSync($row->id);
                $row->refresh();
                $this->info("    sent → hive_lead_id={$row->hive_lead_id}");
            } else {
                SendLeadToHive::dispatch($row->id);
            }
        }

        $verb = $this->option('sync') ? 'Sent' : 'Dispatched';
        $this->info("{$verb} {$rows->count()} lead(s) to hive.contractors.");
        return self::SUCCESS;
    }
}
