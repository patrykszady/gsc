<?php

namespace App\Console\Commands;

use App\Services\LocalFalconService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Pull recent Local Falcon geo-grid scans into local_falcon_scans.
 *
 * Scans themselves are scheduled inside Local Falcon (auto-scans on their
 * side spend credits); this command only mirrors the results so the SEO
 * dashboard and the autopilot's measurement layer can see map-pack movement
 * next to organic movement.
 */
class LocalFalconSync extends Command
{
    protected $signature = 'localfalcon:sync {--limit=25 : Max recent scans to mirror}';

    protected $description = 'Mirror recent Local Falcon geo-grid scan results into the local DB.';

    public function handle(LocalFalconService $falcon): int
    {
        if (! $falcon->isConfigured()) {
            $this->warn('LOCALFALCON_API_KEY not set — skipping.');

            return self::SUCCESS;
        }

        $scans = $falcon->recentScans((int) $this->option('limit'));
        if ($scans === null) {
            $this->error('Local Falcon fetch failed: ' . ($falcon->getLastError() ?? 'unknown'));

            return self::FAILURE;
        }

        $new = 0;
        foreach ($scans as $scan) {
            $id = (string) ($scan['report_key'] ?? $scan['id'] ?? $scan['scan_id'] ?? '');
            if ($id === '') {
                continue;
            }

            $row = \App\Support\Tenancy::table('local_falcon_scans')->where('falcon_scan_id', $id)->exists();
            if ($row) {
                continue;
            }

            $scannedAt = null;
            foreach (['date', 'created', 'created_at', 'timestamp'] as $k) {
                if (! empty($scan[$k])) {
                    try {
                        $scannedAt = Carbon::parse($scan[$k]);
                    } catch (\Throwable) {
                    }
                    break;
                }
            }

            \App\Support\Tenancy::table('local_falcon_scans')->insert([
                'site_id' => \App\Models\Site::current()?->id,
                'falcon_scan_id' => $id,
                'keyword' => mb_substr((string) ($scan['keyword'] ?? $scan['term'] ?? '?'), 0, 191),
                'place_id' => mb_substr((string) ($scan['place_id'] ?? ''), 0, 128) ?: null,
                'grid_size' => (int) ($scan['grid_size'] ?? $scan['grid'] ?? 0) ?: null,
                'arp' => is_numeric($scan['arp'] ?? null) ? (float) $scan['arp'] : null,
                'atrp' => is_numeric($scan['atrp'] ?? null) ? (float) $scan['atrp'] : null,
                'solv' => is_numeric(str_replace('%', '', (string) ($scan['solv'] ?? ''))) ? (float) str_replace('%', '', (string) $scan['solv']) : null,
                'scanned_at' => $scannedAt,
                'raw' => json_encode($scan),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $new++;
            $this->line("  + {$id}  " . ($scan['keyword'] ?? '?') . '  ARP=' . ($scan['arp'] ?? '—') . ' SoLV=' . ($scan['solv'] ?? '—'));
        }

        $this->info("Mirrored {$new} new scan(s) of " . count($scans) . ' fetched.');

        return self::SUCCESS;
    }
}
