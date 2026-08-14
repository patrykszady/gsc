<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Read and edit this site's Forge deploy script over the Forge API.
 *
 *   php artisan forge:deploy-script                  show the current script
 *   php artisan forge:deploy-script --add-seo        append the sitemap block
 *   php artisan forge:deploy-script --add-seo --dry-run
 *
 * Exists so the deploy script is manageable from the repo instead of only via
 * the Forge dashboard. The --add-seo block regenerates the sitemap against the
 * newly deployed code and asks Google to re-read it (sitemaps.submit — the
 * supported nudge; the ping endpoint died in 2023 and IndexNow never reaches
 * Google). Idempotent: a marker comment prevents double-append.
 */
class ForgeDeployScript extends Command
{
    protected $signature = 'forge:deploy-script
        {--add-seo : Append the sitemap regenerate + submit block if absent}
        {--add-maintenance : Wrap the deploy in maintenance mode so the swap window serves 503, not 500}
        {--dry-run : Show the would-be script without saving}';

    protected $description = 'Show or update the Forge deploy script for this site via the Forge API';

    private const API = 'https://forge.laravel.com/api/v1';

    private const MARKER = '# --- seo: ask Google to re-read the sitemap (managed by forge:deploy-script) ---';

    /**
     * Inserted AFTER the script's existing sitemap:generate line — the live
     * script already regenerates the sitemap and pings IndexNow on deploy, so
     * appending our own generate would run it twice. Only the Google half is
     * missing: IndexNow reaches Bing/Yandex but never Google, and the ping
     * endpoint died June 2023; sitemaps.submit is the supported nudge.
     */
    private const SEO_INSERT = <<<'BASH'
# --- seo: ask Google to re-read the sitemap (managed by forge:deploy-script) ---
# IndexNow (below/above) never reaches Google. `|| true`: until
# search-console:auth has been re-run once for the write scope, this reports a
# 403 — which must never fail a deploy.
$FORGE_PHP artisan seo:gsc-submit-sitemaps || true
BASH;

    private const SEO_BLOCK = <<<'BASH'

# --- seo: ask Google to re-read the sitemap (managed by forge:deploy-script) ---
# Regenerate against the just-deployed code/data, then ask Google to re-read.
# sitemaps.submit is the supported nudge (the ping endpoint died June 2023 and
# IndexNow never reaches Google). `|| true`: until search-console:auth has been
# re-run once for the write scope, the submit reports a 403 — that must never
# fail a deploy.
$FORGE_PHP artisan sitemap:generate
$FORGE_PHP artisan seo:gsc-submit-sitemaps || true
BASH;

    private const MAINT_MARKER = '# --- deploy window: serve 503 instead of 500s (managed by forge:deploy-script) ---';

    /**
     * This site deploys IN PLACE: `current` is a symlink that has pointed at
     * the same release directory since January, and the script `git reset
     * --hard`s inside it. So the entire codebase swaps under live traffic, and
     * composer/migrate/npm then run for ~90s while requests keep arriving.
     * Requests landing in that window execute new code against the old
     * database — which is exactly where the "Table 'sites' doesn't exist",
     * "Unknown column hive_project_zip_counts.site_id" and "Unable to locate
     * component [cta]" 500s on /, /about and /contact came from: every one of
     * them is timestamped inside a deploy.
     *
     * A 503 with Retry-After is the correct answer for a crawler mid-deploy;
     * a 500 on the homepage is not. The trap guarantees the site comes back up
     * even if a later deploy step fails.
     */
    private const MAINT_HEAD = <<<'BASH'
# --- deploy window: serve 503 instead of 500s (managed by forge:deploy-script) ---
# In-place deploy: the code swaps instantly, then composer/migrate/npm run for
# ~90s. Requests in that window hit new code against the old schema. The trap
# brings the site back even if a step below fails.
trap '$FORGE_PHP artisan up || true' EXIT
$FORGE_PHP artisan down --retry=60 || true
BASH;

    private const MAINT_TAIL = <<<'BASH'
# --- deploy window ends (managed by forge:deploy-script) ---
$FORGE_PHP artisan up
BASH;

    public function handle(): int
    {
        $token = (string) config('services.forge.token');
        if ($token === '') {
            $this->error('FORGE_API_TOKEN is not set. Create one at forge.laravel.com → user profile → API,');
            $this->error('then add FORGE_API_TOKEN=... to the LOCAL .env (never the repo, never production).');

            return self::FAILURE;
        }

        $client = Http::withToken($token)->acceptJson()->timeout(30);

        // ---- resolve server ------------------------------------------------
        $servers = $client->get(self::API . '/servers')->json('servers');
        if (! is_array($servers)) {
            $this->error('Could not list servers — is the token valid?');

            return self::FAILURE;
        }

        $wanted = (string) config('services.forge.server');
        $server = collect($servers)->first(
            fn ($s) => in_array($wanted, [(string) ($s['name'] ?? ''), (string) ($s['ip_address'] ?? '')], true)
        ) ?? (count($servers) === 1 ? $servers[0] : null);

        if (! $server) {
            $this->error("No server matched '{$wanted}'. Servers visible to this token:");
            foreach ($servers as $s) {
                $this->line("  - {$s['name']} ({$s['ip_address']})");
            }

            return self::FAILURE;
        }

        // ---- resolve site --------------------------------------------------
        $sites = $client->get(self::API . "/servers/{$server['id']}/sites")->json('sites', []);
        $wantedSite = (string) config('services.forge.site');
        $site = collect($sites)->first(fn ($s) => (string) ($s['name'] ?? '') === $wantedSite);

        if (! $site) {
            $this->error("No site named '{$wantedSite}' on {$server['name']}. Sites:");
            foreach ($sites as $s) {
                $this->line("  - {$s['name']}");
            }

            return self::FAILURE;
        }

        $this->info("Server: {$server['name']} ({$server['ip_address']})  Site: {$site['name']} (id {$site['id']})");

        // ---- current script ------------------------------------------------
        $scriptUrl = self::API . "/servers/{$server['id']}/sites/{$site['id']}/deployment/script";
        $script = (string) $client->get($scriptUrl)->body();

        if (! $this->option('add-seo') && ! $this->option('add-maintenance')) {
            $this->newLine();
            $this->line($script);

            return self::SUCCESS;
        }

        $updated = $script;
        $changed = false;

        if ($this->option('add-seo')) {
            if (str_contains($updated, self::MARKER)) {
                $this->info('SEO block already present — skipping.');
            } else {
                // Insert after the script's own sitemap:generate when it has
                // one (the live script does — appending our full block would
                // generate twice); append the whole block when it does not.
                $lines = preg_split('/\r?\n/', $updated);
                $generateIdx = null;
                foreach ($lines as $i => $line) {
                    if (str_contains($line, 'artisan sitemap:generate')) {
                        $generateIdx = $i;
                        break;
                    }
                }

                if ($generateIdx !== null) {
                    array_splice($lines, $generateIdx + 1, 0, explode("\n", self::SEO_INSERT));
                    $updated = rtrim(implode("\n", $lines), "\n") . "\n";
                } else {
                    $updated = rtrim($updated, "\n") . "\n" . self::SEO_BLOCK . "\n";
                }
                $changed = true;
            }
        }

        if ($this->option('add-maintenance')) {
            if (str_contains($updated, self::MAINT_MARKER)) {
                $this->info('Maintenance wrapper already present — skipping.');
            } else {
                // Head goes immediately after the `cd` into the site root, so
                // $FORGE_PHP resolves and artisan is reachable; tail goes last.
                $lines = preg_split('/\r?\n/', $updated);
                $cdIdx = null;
                foreach ($lines as $i => $line) {
                    if (preg_match('/^\s*cd\s+\S/', $line)) {
                        $cdIdx = $i;
                        break;
                    }
                }

                if ($cdIdx === null) {
                    $this->error('No `cd` line found — refusing to guess where maintenance mode should start.');

                    return self::FAILURE;
                }

                array_splice($lines, $cdIdx + 1, 0, array_merge([''], explode("\n", self::MAINT_HEAD)));
                $updated = rtrim(implode("\n", $lines), "\n") . "\n\n" . self::MAINT_TAIL . "\n";
                $changed = true;
            }
        }

        if (! $changed) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn('Dry run — script WOULD become:');
            $this->newLine();
            $this->line($updated);

            return self::SUCCESS;
        }

        $resp = $client->put($scriptUrl, ['content' => $updated]);
        if (! $resp->successful()) {
            $this->error('Update failed: HTTP ' . $resp->status() . ' ' . mb_substr($resp->body(), 0, 200));

            return self::FAILURE;
        }

        // Read back rather than trusting the 200 — verify every marker we were
        // asked to add actually landed.
        $verify = (string) $client->get($scriptUrl)->body();
        $expected = array_filter([
            $this->option('add-seo') ? self::MARKER : null,
            $this->option('add-maintenance') ? self::MAINT_MARKER : null,
        ]);

        foreach ($expected as $marker) {
            if (! str_contains($verify, $marker)) {
                $this->error('Update reported success but a block is missing on re-read: ' . mb_substr($marker, 0, 40));

                return self::FAILURE;
            }
        }

        $this->info('Deploy script updated and verified on re-read.');

        return self::SUCCESS;
    }
}
