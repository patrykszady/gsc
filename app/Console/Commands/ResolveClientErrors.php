<?php

namespace App\Console\Commands;

use App\Models\ClientError;
use Illuminate\Console\Command;

class ResolveClientErrors extends Command
{
    protected $signature = 'js-errors:resolve
                            {--id=* : Only resolve these client_error row IDs}
                            {--kind= : Only resolve a single kind (error|promise)}
                            {--stale= : Only resolve rows last seen more than N days ago}
                            {--delete : Delete the rows instead of marking them resolved}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Mark logged front-end JS errors (/admin/js-errors) as resolved (or delete them)';

    public function handle(): int
    {
        $query = ClientError::query()->whereNull('resolved_at');

        if ($ids = $this->option('id')) {
            $query->whereIn('id', $ids);
        }

        if ($kind = $this->option('kind')) {
            $query->where('kind', $kind);
        }

        if ($stale = $this->option('stale')) {
            $query->where('last_seen_at', '<', now()->subDays((int) $stale));
        }

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info('No open client errors match the given criteria. Nothing to do.');

            return self::SUCCESS;
        }

        $delete = (bool) $this->option('delete');
        $action = $delete ? 'delete' : 'mark resolved';

        if (! $this->option('force') && ! $this->confirm("This will {$action} {$count} open client error(s). Continue?", true)) {
            $this->warn('Aborted.');

            return self::SUCCESS;
        }

        $affected = $delete
            ? $query->delete()
            : $query->update(['resolved_at' => now()]);

        $this->info(($delete ? 'Deleted ' : 'Resolved ').$affected.' client error(s).');

        return self::SUCCESS;
    }
}
