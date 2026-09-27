<?php

namespace App\Support\Citations;

use App\Models\Citation;
use App\Models\Site;
use SsSystems\Platform\Citations\Contracts\PendingCitationRepository;

/**
 * gsc's tenant-scoped adapter for the kit's `VerificationInbox` (kit
 * 0.13.0). **This `pending()` method is THE FIX for gsc's live bug**:
 * the site's own former `App\Services\Citations\VerificationInbox::run()`
 * queried `Citation::query()->where('status', ...PENDING_VERIFICATION)`
 * with no `site_id` filter at all — the only query in that whole class
 * missing the scope every other citations query on this site carries.
 * `pending()` now adds it, matching `SiteCitationLinkStore`/
 * `CitationsControl`'s other actions. See `VerificationInboxIsolationTest`.
 */
class SitePendingCitationRepository implements PendingCitationRepository
{
    public function pending(): array
    {
        return Citation::query()
            ->where('site_id', Site::current()?->id)
            ->where('status', Citation::STATUS_PENDING_VERIFICATION)
            ->get()
            ->map(fn (Citation $c) => [
                'slug' => $c->slug,
                'homepage' => $c->homepage,
                'messages_seen' => (array) ($c->verification['messages_seen'] ?? []),
            ])
            ->all();
    }

    public function recordNoLink(string $slug, array $messagesSeen, string $logMessage): void
    {
        $citation = $this->find($slug);
        if (! $citation) {
            return;
        }
        $verification = $citation->verification ?? [];
        $verification['messages_seen'] = $messagesSeen;
        $citation->verification = $verification;
        $citation->addLog($logMessage, 'verify');
        $citation->save();
    }

    public function recordVerified(string $slug, array $messagesSeen, string $logMessage): void
    {
        $citation = $this->find($slug);
        if (! $citation) {
            return;
        }
        $verification = $citation->verification ?? [];
        $verification['messages_seen'] = $messagesSeen;
        $verification['email'] = 'done';
        $verification['email_verified_at'] = now()->toDateTimeString();
        $citation->verification = $verification;
        $citation->status = Citation::STATUS_SUBMITTED;
        $citation->addLog($logMessage, 'verify');
        $citation->save();
    }

    private function find(string $slug): ?Citation
    {
        return Citation::query()->where('site_id', Site::current()?->id)->where('slug', $slug)->first();
    }
}
