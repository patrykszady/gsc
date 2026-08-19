<?php

namespace App\Console\Commands;

use App\Models\ContactSubmission;
use App\Services\GeoapifyService;
use App\Services\LeadAddressCompleter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Backfill city/state/zip on existing leads that are missing them, using the
 * same LeadAddressCompleter policy applied at capture time (stated values
 * win, an unanchored street is refused rather than mis-resolved, an
 * ambiguous street stores address_candidates for a human to pick).
 *
 * Geoapify is a paid/limited API, so this is deliberately conservative:
 * --limit caps a single run, and a short sleep sits between calls so a large
 * backfill doesn't burst the account's rate limit. --dry-run still calls
 * Geoapify (so you can see exactly what a real run would change) but never
 * writes to the database.
 */
class LeadsCompleteAddresses extends Command
{
    protected $signature = 'leads:complete-addresses
        {--limit= : Max leads to process this run}
        {--dry-run : Show what would change without saving}
        {--sleep=250 : Milliseconds to sleep between leads (rate-limit friendly)}';

    protected $description = 'Backfill city/state/zip on existing leads missing them, via LeadAddressCompleter.';

    /** @var list<string> */
    private const FIELDS = ['address', 'city', 'state', 'zip'];

    public function handle(LeadAddressCompleter $completer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $sleepMs = max(0, (int) $this->option('sleep'));

        $query = ContactSubmission::query()
            ->whereNotNull('address')
            ->where('address', '!=', '')
            ->where(function ($q) {
                $q->whereNull('city')->orWhereNull('state')->orWhereNull('zip');
            })
            ->orderBy('id');

        if ($limit = $this->option('limit')) {
            $query->limit((int) $limit);
        }

        $leads = $query->get();

        if ($leads->isEmpty()) {
            $this->info('No leads need address completion.');

            return self::SUCCESS;
        }

        $this->info(sprintf('%s%d lead(s) to process.', $dryRun ? '[DRY RUN] ' : '', $leads->count()));

        // completed: city+state+zip all filled by this pass.
        // ambiguous: a bare street matched more than one real address near
        //   the office — address_candidates stored for a human to pick.
        // refused_unanchored: the address string itself has no ZIP, state,
        //   or city segment to anchor on — GeoapifyService::addressAnchor()
        //   refuses it before ever calling Geoapify (no API call spent).
        // geocoder_miss: anchored, but Geoapify couldn't confidently resolve
        //   it (or confidently disagreed with what the sender stated).
        $completed = 0;
        $ambiguous = 0;
        $refusedUnanchored = 0;
        $geocoderMiss = 0;
        $errors = 0;
        $last = $leads->count() - 1;

        foreach ($leads as $i => $lead) {
            try {
                $before = collect(self::FIELDS)->mapWithKeys(fn (string $f) => [$f => $lead->{$f}])->all();

                $result = $completer->complete($before + ['address_candidates' => $lead->address_candidates]);

                $changes = [];
                foreach (self::FIELDS as $field) {
                    $to = $result[$field] ?? null;
                    if ($to !== $before[$field]) {
                        $changes[$field] = ['from' => $before[$field], 'to' => $to];
                    }
                }

                $candidatesTo = $result['address_candidates'] ?? null;
                if ($candidatesTo !== $lead->address_candidates) {
                    $changes['address_candidates'] = ['from' => $lead->address_candidates, 'to' => $candidatesTo];
                }

                $isComplete = filled($result['city'] ?? null) && filled($result['state'] ?? null) && filled($result['zip'] ?? null);
                $isAmbiguous = filled($candidatesTo);

                match (true) {
                    $isComplete => $completed++,
                    $isAmbiguous => $ambiguous++,
                    GeoapifyService::addressAnchor($before['address']) === null => $refusedUnanchored++,
                    default => $geocoderMiss++,
                };

                if ($changes === []) {
                    $this->line("#{$lead->id}: no change");
                } else {
                    $this->line("#{$lead->id}: ".json_encode($changes, JSON_UNESCAPED_SLASHES));

                    if (! $dryRun) {
                        $lead->fill([
                            'address' => $result['address'] ?? $lead->address,
                            'city' => $result['city'] ?? $lead->city,
                            'state' => $result['state'] ?? $lead->state,
                            'zip' => $result['zip'] ?? $lead->zip,
                            'address_candidates' => $candidatesTo ?? $lead->address_candidates,
                        ])->save();
                    }
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->error("#{$lead->id}: {$e->getMessage()}");
                Log::channel('submissions')->error('leads:complete-addresses failed for lead', [
                    'lead_id' => $lead->id,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($sleepMs > 0 && $i < $last) {
                usleep($sleepMs * 1000);
            }
        }

        $summary = sprintf(
            '%sDone: %d completed, %d ambiguous, %d refused (unanchored), %d geocoder miss, %d error(s) out of %d processed.',
            $dryRun ? '[DRY RUN] ' : '',
            $completed,
            $ambiguous,
            $refusedUnanchored,
            $geocoderMiss,
            $errors,
            $leads->count(),
        );
        $this->info($summary);
        Log::channel('submissions')->info('leads:complete-addresses summary', [
            'dry_run' => $dryRun,
            'completed' => $completed,
            'ambiguous' => $ambiguous,
            'refused_unanchored' => $refusedUnanchored,
            'geocoder_miss' => $geocoderMiss,
            'errors' => $errors,
            'processed' => $leads->count(),
        ]);

        return self::SUCCESS;
    }
}
