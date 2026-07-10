<?php

namespace App\Services\Seo;

use App\Models\SeoAction;

/**
 * Contract for a category-specific autopilot change. Every applier MUST be
 * reversible: apply() stores whatever it needs into the action payload so that
 * revert() can restore the prior state exactly. This is what makes full-auto
 * mode safe — nothing the autopilot does is one-way.
 */
interface ActionApplier
{
    /** The seo_actions.category this applier handles. */
    public function category(): string;

    /**
     * Perform the change. Throws on failure (the command marks the action
     * failed and moves on). Must persist any prior-state needed for revert()
     * into $action->payload before/while mutating.
     */
    public function apply(SeoAction $action): void;

    /** Undo the change using the prior-state captured in the payload. */
    public function revert(SeoAction $action): void;
}
