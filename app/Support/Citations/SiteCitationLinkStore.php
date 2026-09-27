<?php

namespace App\Support\Citations;

use App\Models\Citation;
use App\Models\Site;
use SsSystems\Platform\Citations\Contracts\CitationLinkStore;

/**
 * gsc's tenant-scoped read/write for the kit's `LinkCheckRunner` (kit
 * 0.13.0) — the same `where('site_id', Site::current()?->id)` scope
 * `CitationsControl`'s `check` sub-action already applied before this
 * port (see `LinkCheckRunnerIsolationTest`), and the exact log line and
 * status/human_reason/note fields that sub-action wrote directly onto
 * the `Citation` model.
 */
class SiteCitationLinkStore implements CitationLinkStore
{
    public function withListingUrl(): array
    {
        return Citation::query()
            ->where('site_id', Site::current()?->id)
            ->whereNotNull('listing_url')
            ->get()
            ->map(fn (Citation $c) => ['slug' => $c->slug, 'name' => (string) $c->name, 'status' => (string) $c->status, 'listing_url' => (string) $c->listing_url])
            ->all();
    }

    public function recordLinkCheck(string $slug, array $result, ?string $status, ?string $humanReason, ?string $note): void
    {
        $citation = Citation::query()->where('site_id', Site::current()?->id)->where('slug', $slug)->first();
        if (! $citation) {
            return;
        }

        $citation->links_to_us = $result['links_to_us'] === null ? null : (bool) $result['links_to_us'];
        $citation->nofollow = $result['nofollow'] === null ? null : (bool) $result['nofollow'];
        $citation->last_checked_at = now();

        if ($status !== null) {
            $citation->status = $status;
            if ($status === Citation::STATUS_LIVE) {
                $citation->live_at = $citation->live_at ?: now();
                $citation->human_reason = null;
            }
            if ($humanReason !== null) {
                $citation->human_reason = $humanReason;
            }
            if ($note !== null) {
                $citation->note = $note;
            }
        }

        $citation->addLog(sprintf(
            'Link check: HTTP %d, links to us: %s%s',
            $result['status'],
            $result['links_to_us'] === null ? '?' : ($result['links_to_us'] ? 'yes' : 'no'),
            $result['note'] ? ' — '.$result['note'] : ''
        ), 'check');
        $citation->save();
    }
}
