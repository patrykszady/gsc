<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Seeded, driver-portable random ordering.
 *
 * ProjectsGrid and TestimonialsGrid need a shuffle that is stable for one
 * visitor across pagination/loadMore requests — hence a seed — but
 * MySQL's RAND(?) is the only seeded SQL RNG, and it broke every page
 * render under the SQLite test suite ("no such function: RAND").
 *
 * On MySQL/MariaDB this is RAND(seed). Elsewhere it falls back to a
 * deterministic linear-congruential permutation of the primary key —
 * not statistically random, but seed-dependent, stable, and a thorough
 * visual shuffle, which is all the grids need.
 */
class SeededRandom
{
    public static function order(EloquentBuilder|Builder $query, int $seed, string $column = 'id'): EloquentBuilder|Builder
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return $query->orderByRaw('RAND(?)', [$seed]);
        }

        // LCG constants (Numerical Recipes); % keeps SQLite/Postgres happy.
        $a = 1103515245;
        $m = 2147483647;

        return $query->orderByRaw("(({$column} * {$a} + ?) % {$m})", [$seed % $m]);
    }
}
