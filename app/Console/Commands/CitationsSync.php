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
        foreach ((array) config('citations.directories', []) as $slug => $def) {
            $row = Citation::query()->where('site_id', $siteId)->where('slug', $slug)->first();
            $attrs = [
                'name' => $def['name'] ?? $slug, 'tier' => (int) ($def['tier'] ?? 2), 'mechanism' => (string) ($def['mechanism'] ?? 'form'),
                'homepage' => $def['homepage'] ?? null, 'start_url' => $def['start_url'] ?? ($def['homepage'] ?? null),
            ];
            if ($row) {
                $row->fill($attrs)->save();
            } else {
                Citation::create($attrs + ['site_id' => $siteId, 'slug' => $slug, 'status' => Citation::STATUS_PLANNED, 'note' => $def['note'] ?? null]);
                $created++;
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
