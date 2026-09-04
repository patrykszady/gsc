<?php

namespace App\Services\Seo\Intel;

use App\Console\Commands\SeoDomainOverview;
use App\Models\AreaServed;
use App\Services\DataForSeoService;

/**
 * One DataForSEO API family as a scheduled collector.
 *
 * Lifecycle per run (see IntelRunner): collect() talks to the API and returns
 * Snapshots; the store persists them for today; findings() compares today's
 * snapshots with the previous run's (use latest()/previous()) and returns
 * Findings; report() shapes the stored data for the admin SEO page.
 *
 * Sources never touch the transport directly beyond $this->dfs->request() /
 * postTask() / pollUntil(), never persist anything themselves, and read their
 * knobs through config() so config/seo-intel.php can tune them per site.
 */
abstract class IntelSource
{
    public function __construct(protected DataForSeoService $dfs, protected IntelStore $store) {}

    /** Short machine name, also the config key: 'onpage', 'backlinks', … */
    abstract public function family(): string;

    /** Human label for the admin card. */
    abstract public function label(): string;

    /** Expected USD for one run, for the balance guard. */
    abstract public function estimateCost(): float;

    /**
     * Call the API and describe what was measured. Throw on a failure that
     * makes the run meaningless; return what you have on partial failures.
     *
     * @return Snapshot[]
     */
    abstract public function collect(): array;

    /**
     * Compare today's snapshots with the previous run's. Return every finding
     * that is currently true — the store opens new ones and resolves the ones
     * you no longer return.
     *
     * @return Finding[]
     */
    abstract public function findings(): array;

    /**
     * Admin card payload, one uniform shape for every family:
     *   tiles:  [['label' => 'On-page score', 'value' => 87, 'prev' => 84, 'unit' => '/100', 'good' => 'up'], …]
     *   tables: [['title' => 'Pages with issues', 'columns' => ['Page', 'Issue'], 'rows' => [['/kitchen', 'Duplicate title'], …]], …]
     *   note:   one sentence on what was measured and when.
     *
     * @return array{tiles: list<array<string, mixed>>, tables: list<array{title: string, columns: list<string>, rows: list<list<mixed>>}>, note?: string}
     */
    abstract public function report(): array;

    /** Per-family knob from config/seo-intel.php, with a code default. */
    protected function config(string $key, mixed $default = null): mixed
    {
        return config("seo-intel.families.{$this->family()}.{$key}", $default);
    }

    /** Today's snapshot for a kind/subject, or null. */
    protected function latest(string $kind, string $subject): ?array
    {
        return $this->store->latest($this->family(), $kind, $subject);
    }

    /** The run before the latest one for a kind/subject, or null on the first run. */
    protected function previous(string $kind, string $subject): ?array
    {
        return $this->store->previous($this->family(), $kind, $subject);
    }

    /** Every subject's latest-day snapshot for a kind, keyed by subject. */
    protected function latestSet(string $kind): \Illuminate\Support\Collection
    {
        return $this->store->latestSet($this->family(), $kind);
    }

    /** Every subject's previous-day snapshot for a kind, keyed by subject. */
    protected function previousSet(string $kind): \Illuminate\Support\Collection
    {
        return $this->store->previousSet($this->family(), $kind);
    }

    /** Our bare domain ('gs.construction'). */
    protected function ourDomain(): string
    {
        return preg_replace('#^https?://(www\.)?#', '', rtrim((string) config('app.url'), '/')) ?: 'gs.construction';
    }

    /** Map-pack leaders then organic page-one competitors, bare domains. */
    protected function competitorDomains(int $limit = 8): array
    {
        return SeoDomainOverview::competitorDomains($limit);
    }

    /** The six towns with the most projects — the ones the business is built on. */
    protected function coreTowns(int $n = 6): array
    {
        return array_values(array_map(fn ($a) => (string) $a->name, AreaServed::coreTowns($n)));
    }

    /** Office coordinates the geo-grid is centred on. */
    protected function center(): array
    {
        return [(float) config('seo.map_pack.center_lat', 42.102847), (float) config('seo.map_pack.center_lng', -87.9275628)];
    }

    /** A finding builder that prefixes the code with the family. */
    protected function finding(string $code, string $severity, string $title, string $detail = '', ?string $subject = null, ?string $key = null, array $delta = [], ?array $action = null): Finding
    {
        return new Finding($this->family() . '.' . $code, $severity, $title, $detail, $subject, $key, $delta, $action);
    }
}
