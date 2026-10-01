<?php

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every script under scripts/ that launches Chrome does it in pipe mode
 * under scripts/lib/browser-guard.mjs, so the browser dies with its script
 * however the script dies. A WebSocket-mode Chrome outlives a SIGKILLed Node:
 * on 2026-10-01 one grew to ~8 GB after instagram-add-location.mjs hit its
 * Process timeout and took hive-prod down. Scratch files (_*, tmp-*) that
 * nothing runs are exempt.
 */
class PuppeteerScriptsDieWithTheirScriptTest extends TestCase
{
    public function test_every_chrome_launch_is_in_pipe_mode_under_the_guard(): void
    {
        $root = dirname(__DIR__, 2).'/scripts';
        $offenders = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            $name = $file->getFilename();
            $path = $file->getPathname();

            if (! preg_match('/\.(mjs|cjs|js)$/', $name)
                || str_contains($path, '/node_modules/')
                || str_starts_with($name, '_')
                || str_starts_with($name, 'tmp-')
                || $name === 'browser-guard.mjs') {
                continue;
            }

            $source = (string) file_get_contents($path);
            $guarded = str_contains($source, 'guardBrowser(') || str_contains($source, 'launchGuarded(');

            preg_match_all('/puppeteer\.launch\(/', $source, $launches, PREG_OFFSET_CAPTURE);

            foreach ($launches[0] as [, $offset]) {
                if (! $guarded || ! str_contains(substr($source, $offset, 400), 'pipe: true')) {
                    $offenders[] = substr($path, strlen($root) + 1);
                }
            }
        }

        $this->assertSame([], array_values(array_unique($offenders)), 'Launch Chrome with pipe: true under guardBrowser() (scripts/lib/browser-guard.mjs).');
    }
}
