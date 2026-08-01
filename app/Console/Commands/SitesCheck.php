<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Support\DevSites;
use App\Support\ExclusivePaths;
use App\Support\Tenancy;
use App\Support\Theme;
use Illuminate\Console\Command;

/**
 * Validate every tenant: theme, assets, identity and navigation.
 *
 * The multi-tenant failure mode is silence. A nav link pointing at a path
 * another tenant owns does not error — it returns 200 with the other
 * business's content inside your shell, and nobody notices until a client
 * does. A missing brand override does not error either; it renders GS
 * Construction's phone number on somebody else's site.
 *
 * Read-only and environment-agnostic, so it can gate a deploy. Uses
 * App\Support\ExclusivePaths — the same code TenantRouteGuard uses — so it can
 * never disagree with the middleware that actually 404s the request.
 */
class SitesCheck extends Command
{
    protected $signature = 'sites:check {--site= : Limit to one slug or host}';

    protected $description = 'Validate every site: theme, assets, identity and nav links';

    public function handle(): int
    {
        $sites = Site::listAll();

        if ($only = $this->option('site')) {
            $sites = $sites->filter(
                fn (Site $s): bool => $s->slug === $only
                    || $s->primary_host === $only
                    || in_array($only, (array) $s->hosts, true)
            );

            if ($sites->isEmpty()) {
                $this->error("No site matches \"{$only}\".");

                return self::FAILURE;
            }
        }

        $failures = 0;
        $shared = require config_path('brand.php');

        foreach ($sites as $site) {
            $failures += Tenancy::for($site, function (Site $site) use ($shared): int {
                return $this->checkSite($site, $shared);
            });
        }

        $this->newLine();

        if ($failures > 0) {
            $this->error("{$failures} problem(s) found.");

            return self::FAILURE;
        }

        $this->info('All sites check out.');

        return self::SUCCESS;
    }

    /** @return int number of failures */
    protected function checkSite(Site $site, array $shared): int
    {
        $failures = 0;

        $this->newLine();
        $this->line(sprintf(
            '<options=bold>%s</> <fg=gray>(%s)</> %s',
            $site->name,
            $site->slug,
            $site->is_active ? '<fg=green>live</>' : '<fg=yellow>in build</>',
        ));
        $this->line("  production   {$site->primary_host}");
        $this->line("  local        http://{$site->devHost()}:8003");

        // --- theme -------------------------------------------------------
        $themeDir = Theme::path($site);
        if (is_dir($themeDir)) {
            $this->line("  theme        themes/{$site->theme}");
        } elseif ($site->slug === (string) config('sites.default')) {
            // The default site has no theme dir by design — it IS resources/views.
            $this->line("  theme        <fg=gray>none — uses resources/views directly</>");
        } else {
            $this->line("  theme        <fg=yellow>themes/{$site->theme} missing — inherits every shared view</>");
        }

        // --- assets ------------------------------------------------------
        foreach (Theme::viteEntries($site) as $entry) {
            if (! file_exists(base_path($entry))) {
                $this->line("  <fg=red>asset entry missing: {$entry}</>");
                $failures++;
            }
        }

        // --- identity ----------------------------------------------------
        if ($site->slug !== (string) config('sites.default')) {
            foreach (['legal_name', 'email', 'phone_href'] as $key) {
                $value = config("brand.{$key}");

                if ($value !== '' && $value === ($shared[$key] ?? null)) {
                    $this->line("  <fg=red>brand.{$key} still inherits gs.construction's value ({$value})</>");
                    $failures++;
                }
            }
        }

        // --- overlays / claims -------------------------------------------
        $overlays = DevSites::overlays($site);
        $this->line('  overrides    ' . ($overlays ? implode(', ', $overlays) : '<fg=gray>nothing</>'));

        $claims = (array) config("sites.exclusive_paths.{$site->slug}", []);
        $this->line('  claims       ' . ($claims ? implode(' · ', $claims) : '<fg=gray>no exclusive paths</>'));

        // --- nav ---------------------------------------------------------
        $nav = DevSites::nav($site);

        if (! $nav) {
            $this->line('  nav          <fg=yellow>none — add config/sites/' . $site->slug . '/nav.php</>');

            return $failures;
        }

        $this->line('  nav');
        foreach ($nav as $link) {
            if ($link['status'] === '404') {
                $this->line(sprintf('    <fg=red>404</> %-14s %s  <fg=red>%s</>', $link['label'], $link['href'], $link['note']));
                $failures++;
            } else {
                $this->line(sprintf('    <fg=green>%3s</> %-14s <fg=gray>%s</>', $link['status'], $link['label'], $link['href']));
            }
        }

        // A path this site claims but has no nav link to is not an error, but
        // it is usually an oversight worth surfacing.
        $linked = collect($nav)->pluck('href')->map(fn ($h) => ltrim((string) parse_url($h, PHP_URL_PATH), '/'))->filter()->all();
        $unlinked = array_values(array_diff($claims, $linked));

        if ($unlinked && $site->slug !== (string) config('sites.default')) {
            $this->line('  <fg=gray>claimed but not in nav: ' . implode(', ', $unlinked) . '</>');
        }

        // Sanity: the guard must let this site serve everything it claims.
        foreach ($claims as $claim) {
            if (! ExclusivePaths::allows($site->slug, $claim)) {
                $this->line("  <fg=red>claims /{$claim} but the tenant guard blocks it</>");
                $failures++;
            }
        }

        return $failures;
    }
}
