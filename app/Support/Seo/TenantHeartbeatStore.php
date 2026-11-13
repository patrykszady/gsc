<?php

namespace App\Support\Seo;

use App\Models\SeoSyncRun;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;
use SsSystems\Platform\Seo\Status\Contracts\HeartbeatStore;
use SsSystems\Platform\Seo\Status\SchedulerBeat;
use Throwable;

/**
 * This site's half of the kit's SsSystems\Platform\Seo\Status\Contracts\
 * HeartbeatStore (kit 0.28.0, 2026-11-12) — where every SEO collector's
 * "I ran, and this is how it went" lands and where `GET seo/status` reads
 * it back. Bound in AppServiceProvider::registerSeoTools().
 *
 * The kit's own TableHeartbeatStore would do for a single site. This one
 * exists for the two things only a multi-tenant app has to decide:
 *
 *   - A source's row is the CURRENT tenant's (App\Support\Tenancy), read at
 *     call time, so `tenants:run` writing for one site after another and a
 *     queue worker serving several all land on the right row. A scheduled
 *     command that runs outside `tenants:run` has no tenant bound and
 *     writes for the default site, like every other write it makes.
 *   - The scheduler's beat ({@see SchedulerBeat::KEY}) is nobody's: it is
 *     written by `schedule:run`, where no tenant is bound, and says the
 *     whole app's cron is alive. It is kept once, with a NULL site_id — a
 *     row that by BelongsToSite's rule belongs to every site — and read
 *     back for whichever tenant asks. Scoped like the rest it would read
 *     `never_ran` for every tenant but the default one.
 *
 * Both reads name their owner outright rather than leaning on SiteScope's
 * NULL allowance, so a tenant's row and a shared row can never stand in
 * for each other.
 *
 * As the contract asks, a missing table is not an error: reads answer null
 * and writes do nothing until the migration has run.
 */
final class TenantHeartbeatStore implements HeartbeatStore
{
    /** Keys kept once for the whole app instead of once per tenant. */
    private const SHARED_KEYS = [SchedulerBeat::KEY];

    private bool $tableExists = false;

    public function read(string $key): ?array
    {
        if (! $this->ready()) {
            return null;
        }

        $summary = $this->rows($key)->first()?->summary;

        return is_array($summary) ? $summary : null;
    }

    public function write(string $key, array $summary): void
    {
        if (! $this->ready()) {
            return;
        }

        $rows = $this->rows($key)->get();

        if ($rows->isEmpty()) {
            try {
                $this->create($key, $summary);

                return;
            } catch (UniqueConstraintViolationException) {
                $rows = $this->rows($key)->get();
            }
        }

        foreach ($rows as $run) {
            $run->fill(['summary' => $summary])->save();
        }
    }

    /**
     * Saved without model events: BelongsToSite's `creating` hook stamps
     * the current tenant on any row that has none, which is exactly what a
     * shared row must not get.
     *
     * @param  array<string, mixed>  $summary
     */
    private function create(string $key, array $summary): void
    {
        $run = new SeoSyncRun(['sync_key' => $key, 'summary' => $summary]);
        $run->site_id = $this->ownerOf($key);
        $run->saveQuietly();
    }

    /**
     * The rows holding $key for its owner, newest first. One row, except
     * that the unique index cannot keep two NULL-tenant rows apart on
     * MySQL: should a shared key ever be inserted twice at the same
     * moment, the newest is the one read and both are kept current.
     *
     * @return Builder<SeoSyncRun>
     */
    private function rows(string $key): Builder
    {
        $owner = $this->ownerOf($key);
        $query = SeoSyncRun::withoutSiteScope()->where('sync_key', $key);

        return ($owner === null ? $query->whereNull('site_id') : $query->where('site_id', $owner))
            ->orderByDesc('updated_at')
            ->orderByDesc('id');
    }

    /** The tenant whose row $key is, or null for a key every tenant shares. */
    private function ownerOf(string $key): ?int
    {
        return in_array($key, self::SHARED_KEYS, true) ? null : Tenancy::currentId();
    }

    /** Whether the table is there; asked again on every call until it is. */
    private function ready(): bool
    {
        if ($this->tableExists) {
            return true;
        }

        try {
            return $this->tableExists = Schema::hasTable((new SeoSyncRun)->getTable());
        } catch (Throwable) {
            return false;
        }
    }
}
