<?php

namespace App\Services\Seo\Intel;

/**
 * Something a source concluded by comparing this run with the previous one
 * (or with what a healthy site should look like). Findings are keyed by a
 * fingerprint: the same finding reported on consecutive runs stays one open
 * row with first/last seen dates; a run that no longer reports it resolves it.
 *
 * $action is an optional hint for the autopilot, e.g.
 * ['type' => 'title_meta', 'path' => '/kitchen-remodeling'] — only types in
 * SeoAutopilotService::SAFE_ALLOWLIST are ever applied automatically.
 */
final class Finding
{
    public const CRITICAL = 'critical';

    public const WARN = 'warn';

    public const INFO = 'info';

    public const WIN = 'win';

    public readonly string $fingerprint;

    /**
     * @param  string  $code  family-prefixed, e.g. 'onpage.duplicate_title'
     * @param  array<string, array{prev: mixed, now: mixed}>  $delta
     * @param  array<string, mixed>|null  $action
     */
    public function __construct(
        public readonly string $code,
        public readonly string $severity,
        public readonly string $title,
        public readonly string $detail = '',
        public readonly ?string $subject = null,
        public readonly ?string $key = null,
        public readonly array $delta = [],
        public readonly ?array $action = null,
        ?string $fingerprint = null,
    ) {
        $this->fingerprint = $fingerprint ?? sha1(implode('|', [$code, (string) $subject, (string) $key]));
    }
}
