<?php

namespace App\Services\Seo\Appliers;

use App\Models\AreaServed;
use App\Models\SeoAction;
use App\Services\AiContentService;
use App\Services\Seo\ActionApplier;
use RuntimeException;

/**
 * Rewrites (deepens) a town page's local intro around the phrases research
 * shows people actually search for that town. Reversible: the previous
 * intro is stored in the payload and revert() puts it back verbatim.
 *
 * Payload contract:
 *   phrases[]        — researched phrases to work in (set at synthesis)
 *   prev_local_intro — captured here so revert is exact
 *   new_length       — chars written, for the ledger
 */
class ContentRefreshApplier implements ActionApplier
{
    public function category(): string
    {
        return 'content_refresh';
    }

    public function apply(SeoAction $action): void
    {
        $area = $action->target_type === AreaServed::class ? AreaServed::find($action->target_id) : null;
        if (! $area) {
            throw new RuntimeException("content_refresh action #{$action->id} has no area target.");
        }
        $payload = $action->payload ?? [];
        $phrases = array_values(array_filter((array) ($payload['phrases'] ?? [])));

        $text = app(AiContentService::class)->deepenAreaLocalIntro($area, $phrases);
        if ($text === null) {
            throw new RuntimeException('Copy refresh failed: ' . (app(AiContentService::class)->getLastError() ?? 'no text'));
        }
        $lower = mb_strtolower($text);
        foreach (['nestled in', 'premier', 'your trusted', 'look no further'] as $banned) {
            if (str_contains($lower, $banned)) {
                throw new RuntimeException("Copy refresh rejected: contains \"{$banned}\".");
            }
        }
        if (! str_contains($lower, mb_strtolower(trim((string) $area->city)))) {
            throw new RuntimeException('Copy refresh rejected: does not mention the town.');
        }

        $payload['prev_local_intro'] = $area->local_intro;
        $payload['new_length'] = mb_strlen($text);
        $area->forceFill(['local_intro' => $text])->save();
        $action->payload = $payload;
    }

    public function revert(SeoAction $action): void
    {
        $area = $action->target_type === AreaServed::class ? AreaServed::find($action->target_id) : null;
        if (! $area) {
            return;
        }
        $payload = $action->payload ?? [];
        if (array_key_exists('prev_local_intro', $payload)) {
            $area->forceFill(['local_intro' => $payload['prev_local_intro']])->save();
        }
    }
}
