<?php

namespace App\Console\Commands;

use App\Jobs\SendLeadToHive;
use App\Models\ContactSubmission;
use Illuminate\Console\Command;

class HiveResendLeads extends Command
{
    protected $signature = 'hive:resend-leads
        {--limit=100 : Max submissions to redispatch in this run}
        {--include-failed : Also resend rows with hive_send_error set (exhausted retries)}';

    protected $description = 'Re-dispatch SendLeadToHive jobs for non-spam submissions never accepted by Hive.';

    public function handle(): int
    {
        $q = ContactSubmission::query()
            ->where('status', 'pending')
            ->whereNull('hive_sent_at');

        if (! $this->option('include-failed')) {
            $q->whereNull('hive_send_error');
        }

        $rows = $q->orderBy('id')->limit((int) $this->option('limit'))->get();

        if ($rows->isEmpty()) {
            $this->info('No leads to resend.');
            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            SendLeadToHive::dispatch($row->id);
            $this->line("  → queued submission #{$row->id} ({$row->email})");
        }

        $this->info("Dispatched {$rows->count()} lead(s) to hive.contractors.");
        return self::SUCCESS;
    }
}
