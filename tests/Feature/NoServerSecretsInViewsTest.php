<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Server-side credentials must never be rendered into a page.
 *
 * GitHub secret scanning flagged Google API keys committed under docs/ on
 * 2026-08-02. Chasing them down surfaced the larger problem:
 * `services.google.places_api_key` — the key PHP uses for Places and
 * Geocoding — was also being printed into the Maps JavaScript loader on every
 * page. A server key cannot be HTTP-referrer restricted, so publishing it in
 * the HTML let anyone who viewed source spend against the account.
 *
 * The browser now gets its own restricted key. This test fails the moment
 * someone wires the server key back into a view.
 */
class NoServerSecretsInViewsTest extends TestCase
{
    /** Config keys that are server-only and must not appear in any Blade file. */
    private const SERVER_ONLY = [
        'services.google.places_api_key',
        'services.yelp.business.password',
        'services.yelp.business.cookie_ingest_token',
        'services.google.business_profile.client_secret',
        'services.twocaptcha.api_key',
        'services.anticaptcha.api_key',
    ];

    public function test_no_view_renders_a_server_only_credential(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $file) {
            $contents = file_get_contents($file);
            foreach (self::SERVER_ONLY as $key) {
                if (str_contains($contents, $key)) {
                    $offenders[] = str_replace(base_path().'/', '', $file)." renders {$key}";
                }
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['Server-side credentials found in Blade views:'],
            $offenders,
            ['', 'Use a separate, referrer-restricted browser key instead (e.g. services.google.maps_browser_key).'],
        )));
    }

    public function test_no_literal_google_api_key_is_committed(): void
    {
        // Catches a key pasted straight into code or docs, which is how the
        // scanning alert happened in the first place.
        $found = [];
        foreach ([resource_path(), app_path(), config_path(), base_path('docs')] as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            foreach ($this->filesIn($dir) as $file) {
                $contents = @file_get_contents($file);
                if ($contents === false) {
                    continue;
                }
                // Real keys only — the redaction placeholders are literal
                // "AIzaREDACTED.." and must keep passing.
                if (preg_match('/AIza(?!REDACTED)[0-9A-Za-z_-]{35}/', $contents)) {
                    $found[] = str_replace(base_path().'/', '', $file);
                }
            }
        }

        $this->assertSame([], $found, "Google API key literal committed in:\n".implode("\n", $found));
    }

    /**
     * Nothing log-, dump- or env-shaped may sit in the web root.
     *
     * A `laravel.log` downloaded from the log viewer was found in `public/`
     * carrying the proxy password and both captcha keys, served at HTTP 200
     * by the dev server. `.gitignore` stops it being committed; this stops it
     * being present at all, which is the part that actually matters.
     */
    public function test_no_secrets_bearing_files_in_the_web_root(): void
    {
        $offenders = [];

        foreach (['*.log', '*.sql', '*.env', '*.bak', '*.dump'] as $glob) {
            foreach (glob(public_path($glob)) ?: [] as $file) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders, implode("\n", array_merge(
            ['Files that must never live in public/ (it is web-served):'],
            $offenders,
        )));
    }

    /** @return array<int, string> */
    private function bladeFiles(): array
    {
        return array_filter(
            $this->filesIn(resource_path('views')),
            fn ($f) => str_ends_with($f, '.blade.php'),
        );
    }

    /** @return array<int, string> */
    private function filesIn(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $f) {
            if ($f->isFile() && $f->getSize() < 5_000_000) {
                $out[] = $f->getPathname();
            }
        }

        return $out;
    }
}
