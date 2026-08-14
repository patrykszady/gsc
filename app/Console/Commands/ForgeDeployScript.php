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
        {--dry-run : With --add-seo, show the would-be script without saving}';

    protected $description = 'Show or update the Forge deploy script for this site via the Forge API';

    private const API = 'https://forge.laravel.com/api/v1';

    private const MARKER = '# --- seo: sitemap regenerate + Google re-read (managed by forge:deploy-script) ---';

    private const SEO_BLOCK = <<<'BASH'

# --- seo: sitemap regenerate + Google re-read (managed by forge:deploy-script) ---
# Regenerate against the just-deployed code/data, then ask Google to re-read.
# sitemaps.submit is the supported nudge (the ping endpoint died June 2023 and
# IndexNow never reaches Google). `|| true`: until search-console:auth has been
# re-run once for the write scope, the submit reports a 403 — that must never
# fail a deploy.
$FORGE_PHP artisan sitemap:generate
$FORGE_PHP artisan seo:gsc-submit-sitemaps || true
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

        if (! $this->option('add-seo')) {
            $this->newLine();
            $this->line($script);

            return self::SUCCESS;
        }

        if (str_contains($script, self::MARKER)) {
            $this->info('SEO block already present — nothing to do.');

            return self::SUCCESS;
        }

        $updated = rtrim($script, "\n") . "\n" . self::SEO_BLOCK . "\n";

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

        // Read back rather than trusting the 200.
        $verify = (string) $client->get($scriptUrl)->body();
        if (! str_contains($verify, self::MARKER)) {
            $this->error('Update reported success but the block is not in the script on re-read.');

            return self::FAILURE;
        }

        $this->info('Deploy script updated and verified. Next deploy will regenerate + submit the sitemap.');

        return self::SUCCESS;
    }
}
