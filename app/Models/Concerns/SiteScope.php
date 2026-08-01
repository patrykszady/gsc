<?php

namespace App\Models\Concerns;

use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope applied by BelongsToSite. Rows with a NULL site_id are visible
 * to every site so a partially-backfilled table never silently empties the UI.
 */
class SiteScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $column = $model->qualifyColumn('site_id');

        $builder->where(function (Builder $q) use ($column) {
            $q->where($column, Site::current()->id)->orWhereNull($column);
        });
    }
}
