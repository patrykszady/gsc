<?php

namespace App\Console\Commands;

use App\Models\Citation;
use App\Models\Site;
use App\Services\Citations\CitationSessionService;
use App\Services\Citations\VerificationInbox;
use App\Support\Citations\LinkCheck;
use Illuminate\Console\Command;

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

    public function handle(CitationSessionService $sessions, VerificationInbox $inbox): int
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
                $our = preg_replace('#^https?://(www\.)?#', '', rtrim((string) config('app.url'), '/')) ?: 'gs.construction';
                $names = array_filter([(string) config('brand.display_name'), (string) config('brand.name')]);
                $rows = Citation::query()->where('site_id', $siteId)->whereNotNull('listing_url')->get();
                foreach ($rows as $citation) {
                    $r = LinkCheck::run((string) $citation->listing_url, $our, $names);
                    $citation->links_to_us = $r['links_to_us'] === null ? null : (bool) $r['links_to_us'];
                    $citation->nofollow = $r['nofollow'] === null ? null : (bool) $r['nofollow'];
                    $citation->last_checked_at = now();
                    if ($r['links_to_us'] === 1 && in_array($citation->status, [Citation::STATUS_SUBMITTED, Citation::STATUS_PENDING_VERIFICATION, Citation::STATUS_NEEDS_HUMAN], true)) {
                        $citation->status = Citation::STATUS_LIVE;
                        $citation->live_at = $citation->live_at ?: now();
                    }
                    if (in_array($r['status'], [404, 410], true) && $citation->status === Citation::STATUS_LIVE) {
                        $citation->status = Citation::STATUS_FAILED;
                        $citation->note = 'The listing URL returns HTTP ' . $r['status'] . '.';
                    }
                    $citation->addLog(sprintf('Link check: HTTP %d, links to us: %s%s', $r['status'], $r['links_to_us'] === null ? '?' : ($r['links_to_us'] ? 'yes' : 'no'), $r['note'] ? ' — ' . $r['note'] : ''), 'check');
                    $citation->save();
                    $this->line(sprintf('  %-28s HTTP %d  links=%s', $citation->name, $r['status'], $r['links_to_us'] === null ? '?' : ($r['links_to_us'] ? 'yes' : 'no')));
                }
                $this->info('Checked ' . $rows->count() . ' listing(s).');

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
