<?php

namespace App\Models\Concerns;

use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\Model;

/**
 * Scopes a model to the current Site.
 *
 * Every query is filtered to Site::current() and every new record is stamped
 * with it, so existing call sites keep working untouched — the tenant is
 * ambient rather than threaded through each query.
 *
 * Escape hatches, both explicit on purpose:
 *   Model::withoutSiteScope()      cross-site queries (admin roll-ups, jobs)
 *   Model::forSite($siteOrId)      a specific site regardless of current
 *
 * Rows with a NULL site_id are treated as belonging to every site. Nothing
 * creates them today; it keeps a partially-backfilled table from silently
 * disappearing from the UI.
 */
trait BelongsToSite
{
    public static function bootBelongsToSite(): void
    {
        static::addGlobalScope(new SiteScope());

        static::creating(function (Model $model) {
            if (empty($model->site_id)) {
                $model->site_id = Site::current()->id;
            }
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @param Builder<static> $query */
    public function scopeWithoutSiteScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(SiteScope::class);
    }

    /** @param Builder<static> $query */
    public function scopeForSite(Builder $query, Site|int $site): Builder
    {
        return $query->withoutGlobalScope(SiteScope::class)
            ->where($query->getModel()->qualifyColumn('site_id'),
                $site instanceof Site ? $site->id : $site);
    }
}
