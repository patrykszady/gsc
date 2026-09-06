<?php

namespace App\Console\Commands;

use App\Models\Citation;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Bring the citations table in line with config/citations.php: one row per
 * directory, new ones planned, existing statuses untouched.
 *
 *   php artisan citations:sync
 *   php artisan citations:sync --list
 */
class CitationsSync extends Command
{
    protected $signature = 'citations:sync {--list : Show every directory and its status}';

    protected $description = 'Register the configured directories in the citations table and list their status';

    public function handle(): int
    {
        $siteId = Site::current()?->id;
        $created = 0;
        // Profiles we already have (config/brand.php 'profiles') seed the listing URL
        // of the matching directory, so the link check can verify them right away.
        $norm = fn ($v) => preg_replace('/[^a-z0-9]/', '', strtolower((string) $v));
        $known = [];
        foreach ((array) config('brand.profiles', []) as $label => $url) {
            $known[$norm($label)] = (string) $url;
        }
        foreach ((array) config('citations.directories', []) as $slug => $def) {
            $row = Citation::query()->where('site_id', $siteId)->where('slug', $slug)->first();
            $attrs = [
                'name' => $def['name'] ?? $slug, 'tier' => (int) ($def['tier'] ?? 2), 'mechanism' => (string) ($def['mechanism'] ?? 'form'),
                'homepage' => $def['homepage'] ?? null, 'start_url' => $def['start_url'] ?? ($def['homepage'] ?? null),
            ];
            $existingUrl = $known[$norm($slug)] ?? $known[$norm($def['name'] ?? '')] ?? null;
            if ($existingUrl && empty($row?->listing_url)) {
                $attrs['listing_url'] = $existingUrl;
            }
            if ($row) {
                $row->fill($attrs)->save();
            } else {
                Citation::create($attrs + ['site_id' => $siteId, 'slug' => $slug, 'status' => Citation::STATUS_PLANNED, 'note' => $def['note'] ?? null]);
                $created++;
            }
        }
        // A row still "running" with no live session for it is a session that
        // ended without anyone polling (browser closed, viewer expired): fold in
        // whatever the runner left behind, and otherwise put it back on the board.
        $sessions = app(\App\Services\Citations\CitationSessionService::class);
        $live = $sessions->status();
        foreach (Citation::query()->where('site_id', $siteId)->where('status', Citation::STATUS_RUNNING)->get() as $stale) {
            if (($live['running'] ?? false) && ($live['slug'] ?? null) === $stale->slug) {
                continue;
            }
            $sessions->syncCitation($stale);
            if ($stale->fresh()->status === Citation::STATUS_RUNNING) {
                $stale->status = Citation::STATUS_PLANNED;
                $stale->addLog('Session ended without a result; back on the board', 'sync');
                $stale->save();
            }
        }
        $this->info("Citations registry synced ({$created} new).");

        if ($this->option('list')) {
            $rows = Citation::query()->where('site_id', $siteId)->orderBy('tier')->orderBy('name')->get();
            $this->table(['Tier', 'Directory', 'Status', 'Listing', 'Photos', 'Links to us', 'Human step / note'], $rows->map(fn ($c) => [
                $c->tier, $c->name, $c->status, $c->listing_url ? mb_substr($c->listing_url, 0, 50) : '—', $c->photos_uploaded,
                $c->links_to_us === null ? '?' : ($c->links_to_us ? 'yes' : 'no'), mb_substr((string) ($c->human_reason ?: $c->note), 0, 60),
            ])->all());
        }

        return self::SUCCESS;
    }
}
