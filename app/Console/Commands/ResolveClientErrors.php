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
                            {--message-like=* : Resolve rows where message contains one of these substrings}
                            {--source-like=* : Resolve rows where source contains one of these substrings}
                            {--path-like=* : Resolve rows where page_path contains one of these substrings}
                            {--before= : Resolve rows last seen before this datetime (Y-m-d or Y-m-d H:i:s)}
                            {--limit= : Max rows to affect (newest-first after filters)}
                            {--dry-run : Show matching rows only, do not update/delete}
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

        $this->applyLikeFilters($query, 'message', (array) $this->option('message-like'));
        $this->applyLikeFilters($query, 'source', (array) $this->option('source-like'));
        $this->applyLikeFilters($query, 'page_path', (array) $this->option('path-like'));

        if ($before = $this->option('before')) {
            $query->where('last_seen_at', '<', $before);
        }

        $query->orderByDesc('last_seen_at');

        $limit = (int) ($this->option('limit') ?: 0);
        if ($limit > 0) {
            $query->limit($limit);
        }

        $count = (clone $query)->count();

        if ($count === 0) {
            $this->info('No open client errors match the given criteria. Nothing to do.');

            return self::SUCCESS;
        }

        if ((bool) $this->option('dry-run')) {
            $rows = (clone $query)
                ->get(['id', 'kind', 'occurrences', 'last_seen_at', 'message', 'source', 'page_path']);

            $this->table(
                ['ID', 'Kind', 'Occur', 'Last Seen', 'Message', 'Source', 'Path'],
                $rows->map(fn (ClientError $row) => [
                    $row->id,
                    $row->kind,
                    $row->occurrences,
                    (string) $row->last_seen_at,
                    mb_strimwidth((string) $row->message, 0, 90, '...'),
                    mb_strimwidth((string) ($row->source ?? ''), 0, 70, '...'),
                    mb_strimwidth((string) ($row->page_path ?? ''), 0, 40, '...'),
                ])->all(),
            );

            $this->info("Dry run: {$count} matching open client error(s). No changes were made.");

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

    private function applyLikeFilters($query, string $column, array $values): void
    {
        $filters = array_values(array_filter(array_map('trim', $values), fn (string $v) => $v !== ''));
        if ($filters === []) {
            return;
        }

        $query->where(function ($nested) use ($column, $filters): void {
            foreach ($filters as $needle) {
                $nested->orWhere($column, 'like', '%'.$needle.'%');
            }
        });
    }
}
