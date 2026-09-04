<?php

namespace App\Console\Commands;

use App\Models\Citation;
use App\Models\Site;
use App\Services\Citations\CitationSessionService;
use Illuminate\Console\Command;

/**
 * Start the citation builder for one directory in the remote browser.
 *
 *   php artisan citations:run remodelersup            prints the noVNC viewer URL
 *   php artisan citations:run remodelersup --wait     also follows the run until it needs a human or finishes
 *   php artisan citations:run remodelersup --headless no display; only for directories that need no human step
 *   php artisan citations:stop
 */
class CitationsRun extends Command
{
    protected $signature = 'citations:run {slug} {--headless} {--wait : Follow the run and print progress}';

    protected $description = 'Open a directory in the remote browser, prefill our listing, upload photos, and hand the human steps to the admin';

    public function handle(CitationSessionService $sessions): int
    {
        $slug = (string) $this->argument('slug');
        if (! config("citations.directories.{$slug}")) {
            $this->error("Unknown directory '{$slug}'. Registered: " . implode(', ', array_keys((array) config('citations.directories', []))));

            return self::FAILURE;
        }
        $this->callSilently('citations:sync');
        $citation = Citation::query()->where('site_id', Site::current()?->id)->where('slug', $slug)->firstOrFail();

        $result = $sessions->start($citation, (bool) $this->option('headless'));
        if (! ($result['ok'] ?? false)) {
            $this->error($result['error'] ?? 'Could not start the session.');

            return self::FAILURE;
        }
        $citation->status = Citation::STATUS_RUNNING;
        $citation->human_reason = null;
        $citation->last_run_at = now();
        $citation->addLog('Session started' . ($this->option('headless') ? ' (headless)' : ''), 'start');
        $citation->save();
        $this->info("Session started for {$citation->name}.");
        if (! empty($result['url'])) {
            $this->line('Viewer: ' . $result['url']);
        }

        if ($this->option('wait')) {
            $lastStep = null;
            while (true) {
                sleep(3);
                $status = $sessions->status();
                $runner = $status['runner'] ?? [];
                $step = ($runner['phase'] ?? '') . ' ' . ($runner['step'] ?? '');
                if ($step !== $lastStep) {
                    $this->line('  ' . trim($step));
                    $lastStep = $step;
                }
                $sessions->syncCitation($citation->refresh());
                if (! empty($runner['needs_human'])) {
                    $this->warn('Needs a human: ' . ($runner['reason'] ?? ''));
                    $this->line('Finish it in the admin viewer, then run: php artisan citations:control resume ' . $slug);

                    return self::SUCCESS;
                }
                if (! empty($runner['done']) || ! empty($runner['error']) || ! $status['running']) {
                    break;
                }
            }
            $sessions->syncCitation($citation->refresh());
            $this->info("Finished: status {$citation->status}" . ($citation->listing_url ? ' — ' . $citation->listing_url : '') . ($citation->note ? ' — ' . $citation->note : ''));
        }

        return self::SUCCESS;
    }
}
