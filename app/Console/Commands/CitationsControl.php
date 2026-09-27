<?php

namespace App\Console\Commands;

use App\Models\Citation;
use App\Models\Site;
use App\Support\Citations\SiteCitationLinkStore;
use App\Support\Citations\SiteLinkTarget;
use Illuminate\Console\Command;
use SsSystems\Platform\Citations\Contracts\CitationSession;
use SsSystems\Platform\Citations\LinkCheckRunner;
use SsSystems\Platform\Citations\VerificationInbox;

/**
 * The rest of the citation builder's operations:
 *
 *   php artisan citations:control resume {slug}   let the runner continue after a human step
 *   php artisan citations:control stop            end the remote session
 *   php artisan citations:control check           verify every listing URL still links to us
 *   php artisan citations:control inbox           follow verification links in the mailbox
 *   php artisan citations:control status          session + runner state
 */
class CitationsControl extends Command
{
    protected $signature = 'citations:control {action : resume|stop|check|inbox|status} {slug?}';

    protected $description = 'Resume, stop, verify listings, read the verification inbox, or show the session status';

    public function handle(CitationSession $sessions, VerificationInbox $inbox): int
    {
        $siteId = Site::current()?->id;

        switch ((string) $this->argument('action')) {
            case 'resume':
                $citation = Citation::query()->where('site_id', $siteId)->where('slug', (string) $this->argument('slug'))->first();
                if (! $citation) {
                    $this->error('Unknown citation.');

                    return self::FAILURE;
                }
                $sessions->resume($citation);
                $citation->addLog('Resumed by the admin', 'resume');
                $citation->status = Citation::STATUS_RUNNING;
                $citation->human_reason = null;
                $citation->save();
                $this->info('Resumed.');

                return self::SUCCESS;

            case 'stop':
                $status = $sessions->status();
                $sessions->stop();
                if ($status['slug'] ?? null) {
                    $citation = Citation::query()->where('site_id', $siteId)->where('slug', $status['slug'])->first();
                    if ($citation) {
                        $sessions->syncCitation($citation);
                        if ($citation->status === Citation::STATUS_RUNNING) {
                            $citation->status = Citation::STATUS_PLANNED;
                            $citation->addLog('Session stopped by the admin', 'stop');
                            $citation->save();
                        }
                    }
                }
                $this->info('Stopped.');

                return self::SUCCESS;

            case 'check':
                // Kit 0.13.0's LinkCheckRunner (transitionsStatus defaults to
                // true, matching this action's status/human_reason/note
                // writes, unchanged since before the port).
                $runner = new LinkCheckRunner(new SiteCitationLinkStore, new SiteLinkTarget);
                $checked = $runner->run();
                foreach ($checked as $row) {
                    $r = $row['result'];
                    $this->line(sprintf('  %-28s HTTP %d  links=%s', $row['name'], $r['status'], $r['links_to_us'] === null ? '?' : ($r['links_to_us'] ? 'yes' : 'no')));
                }
                $this->info('Checked '.count($checked).' listing(s).');

                return self::SUCCESS;

            case 'inbox':
                $r = $inbox->run();
                $this->line(sprintf('Checked %d message(s); verified: %s', $r['checked'], $r['verified'] ? implode(', ', $r['verified']) : 'none'));
                foreach ($r['errors'] as $e) {
                    $this->warn($e);
                }

                return self::SUCCESS;

            case 'status':
                $status = $sessions->status();
                $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::SUCCESS;
        }
        $this->error('Unknown action. Use resume, stop, check, inbox or status.');

        return self::FAILURE;
    }
}
