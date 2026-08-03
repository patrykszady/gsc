<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Let dev-jpeterson.ss.systems reach the jpeterson tenant.
 *
 * The site is still is_active=false, and Site::forHost() searches active
 * sites only — correct for production, since an unlaunched tenant must not
 * answer on a live domain. The consequence is the one forPreviewHost() exists
 * to solve: the preview hostname matched nothing and fell through to the
 * default site, so the client's preview URL silently served gs.construction.
 *
 * preview_hosts is explicit opt-in data, so adding this cannot widen
 * production resolution for anything else, and NoIndexNonProduction forces
 * noindex on preview hosts regardless of environment.
 */
return new class extends Migration
{
    private const HOST = 'dev-jpeterson.ss.systems';

    public function up(): void
    {
        $this->mutate(function (array $hosts): array {
            $hosts[] = self::HOST;

            return array_values(array_unique($hosts));
        });
    }

    public function down(): void
    {
        $this->mutate(fn (array $hosts): array => array_values(
            array_filter($hosts, fn ($h) => strtolower((string) $h) !== self::HOST),
        ));
    }

    /** @param callable(array<int, string>): array<int, string> $fn */
    private function mutate(callable $fn): void
    {
        $row = DB::table('sites')->where('slug', 'jpeterson')->first();
        if (! $row) {
            return;
        }

        // Merge into whatever settings already exist rather than replacing the
        // column — other per-site settings live here too.
        $settings = json_decode((string) ($row->settings ?? '[]'), true);
        $settings = is_array($settings) ? $settings : [];
        $settings['preview_hosts'] = $fn((array) ($settings['preview_hosts'] ?? []));

        DB::table('sites')->where('slug', 'jpeterson')->update([
            'settings' => json_encode($settings),
        ]);
    }
};
