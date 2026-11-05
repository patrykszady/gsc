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
    /**
     * 2026-11-05: `timeout N node …` only sends SIGTERM, Puppeteer's own
     * SIGTERM handler waits for a graceful close, and the 02:35 Instagram
     * check ran 2 h 14 m with a 283-process Chrome until hive-prod stopped
     * answering. Any shell_exec of a scripts/*.mjs must use `timeout -k`, so
     * Node is SIGKILLed (and its pipe-mode Chrome with it) when it ignores
     * the SIGTERM. Symfony Process timeouts already end in SIGKILL.
     */
    public function test_every_shell_launch_of_a_node_script_hard_kills_on_timeout(): void
    {
        $offenders = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/app', FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // Method by method: the command is usually built a few lines
            // above the shell_exec()/spawn() that runs it.
            $methods = preg_split('/\bfunction\s+(?=\w+\s*\()/', (string) file_get_contents($file->getPathname()));

            foreach ($methods as $method) {
                if (! preg_match("#scripts/[\\w-]+\\.mjs#", $method) || str_contains($method, 'new Process')) {
                    continue;
                }

                preg_match_all('/shell_exec\((.{0,300}?)\);/s', $method, $calls);
                $launchesByShell = collect($calls[1])->contains(fn (string $call) => ! preg_match('/pkill|pgrep|rm -rf|command -v/', $call));

                if (($launchesByShell || str_contains($method, '->spawn(')) && ! str_contains($method, 'timeout -k')) {
                    $offenders[] = basename($file->getPathname()).'::'.strtok($method, '(');
                }
            }
        }

        $this->assertSame([], $offenders, "Launches a Node script without `timeout -k`:\n".implode("\n", $offenders));
    }

    /** The session check ends itself from its first line, even when launch() hangs. */
    public function test_the_instagram_session_check_arms_its_deadline_before_launching(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/scripts/instagram-check-session.mjs');

        $deadline = strpos($source, 'process.exit(124)');
        $launch = strpos($source, 'puppeteer.launch(');

        $this->assertNotFalse($deadline);
        $this->assertLessThan($launch, $deadline);
    }
}
