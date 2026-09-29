<?php

namespace App\Services\Seo\Appliers;

use App\Models\SeoAction;
use App\Services\GoogleBusinessProfileService;
use App\Services\Seo\ActionApplier;
use RuntimeException;

/**
 * Writes a new "From the business" description to the Google Business
 * Profile. Review-risk: the autopilot proposes three variants, a person
 * approves one in the admin panel, and only then does this run. The
 * previous description is kept in the payload so revert is exact.
 *
 * Payload contract:
 *   new_description   — the text to apply (chosen at synthesis or by the admin)
 *   prev_description  — captured here for revert
 */
class GbpDescriptionApplier implements ActionApplier
{
    public function category(): string
    {
        return 'gbp_description';
    }

    public function apply(SeoAction $action): void
    {
        $gbp = app(GoogleBusinessProfileService::class);
        if (! $gbp->isConfigured()) {
            throw new RuntimeException('Google Business Profile is not configured.');
        }
        $payload = $action->payload ?? [];
        $new = trim((string) ($payload['new_description'] ?? ''));
        if ($new === '') {
            throw new RuntimeException("gbp_description action #{$action->id} has no new_description.");
        }
        $prev = $gbp->getDescription();
        // A read that FAILED is not "the listing has no description": keeping
        // null here would make a later revert blank the live listing. Until
        // kit 0.14 a Google that did not answer threw at this point; the
        // kit's client returns null instead, so the failure is told apart by
        // getLastError() (an empty description reads back null with none).
        if ($prev === null && $gbp->getLastError() !== null) {
            throw new RuntimeException('Could not read the current description to keep for revert: '.json_encode($gbp->getLastError()));
        }
        $payload['prev_description'] = $prev;
        if ($gbp->updateDescription($new) === null) {
            throw new RuntimeException('Google rejected the description: ' . json_encode($gbp->getLastError()));
        }
        $payload['applied_description'] = mb_substr($new, 0, 750);
        $action->payload = $payload;
    }

    public function revert(SeoAction $action): void
    {
        $payload = $action->payload ?? [];
        if (! array_key_exists('prev_description', $payload)) {
            return;
        }
        // A revert Google did not take must not be recorded as reverted
        // (SeoAutopilotService::revert() marks the action once this returns).
        $gbp = app(GoogleBusinessProfileService::class);
        if ($gbp->updateDescription((string) ($payload['prev_description'] ?? '')) === null) {
            throw new RuntimeException('Google did not take the previous description back: '.json_encode($gbp->getLastError() ?? ['message' => 'Google Business Profile is not configured.']));
        }
    }
}
