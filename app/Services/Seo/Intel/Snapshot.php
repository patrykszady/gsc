<?php

namespace App\Services\Seo\Intel;

/**
 * One measurement a source took this run: what it measured (kind), about
 * whom (subject — a domain, keyword, page URL or place), the numbers worth
 * charting (metrics, a flat name => number map) and the detail behind them
 * (payload, anything JSON). Stored once per kind/subject/day.
 */
final class Snapshot
{
    /**
     * @param  array<string, int|float|null>  $metrics
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $subject,
        public readonly array $metrics = [],
        public readonly array $payload = [],
    ) {}
}
