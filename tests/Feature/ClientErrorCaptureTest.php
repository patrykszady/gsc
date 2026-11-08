<?php

namespace Tests\Feature;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The client error beacon (components/client-error-capture) decides what
 * reaches the JS errors board on ss.systems. Each case below is a real row
 * the board carried on 2026-11-08 that was never the site's to fix: a
 * dropped connection Livewire reported as "HTTP 503" (nginx logged both
 * requests as 200s), a Safari extension's rejection whose frames Safari
 * masks as webkit-masked-url://hidden/, and a scanner on Chrome 83 — next
 * to the errors that must still get through.
 *
 * The script is rendered exactly as the layout includes it and run in Node
 * against a stand-in window, so this tests what visitors' browsers run.
 */
class ClientErrorCaptureTest extends TestCase
{
    private const HARNESS = <<<'JS'
        const vm = require('vm');
        const fs = require('fs');
        const script = fs.readFileSync(process.argv[2], 'utf8').replace(/^[\s\S]*?<script>/, '').replace(/<\/script>[\s\S]*$/, '');
        const scenario = JSON.parse(fs.readFileSync(process.argv[3], 'utf8'));
        const posted = [];
        const listeners = {};
        const documentListeners = {};
        const CSS = { supports: (property, value) => scenario.modern && /color-mix/.test(value) };
        const window = {
            CSS,
            location: { pathname: '/areas-served/inverness', origin: 'https://gs.construction' },
            addEventListener: (type, fn) => { listeners[type] = fn; },
        };
        const document = {
            querySelector: (selector) => selector === 'meta[name="csrf-token"]' ? { content: 'token' } : null,
            addEventListener: (type, fn) => { documentListeners[type] = fn; },
        };
        const fetch = (url, options) => { posted.push(JSON.parse(options.body)); return { catch() {} }; };
        vm.runInNewContext(script, { window, document, CSS, fetch });

        let fail = null;
        window.Livewire = { hook: (name, fn) => fn({ fail: (callback) => { fail = callback; } }) };
        documentListeners['livewire:init']();

        for (const event of scenario.events) {
            if (event.type === 'error') {
                listeners.error({ message: event.message, filename: event.filename || '', lineno: event.line || 0, colno: 0, error: event.stack ? { stack: event.stack } : null });
            } else if (event.type === 'rejection') {
                const reason = new Error(event.message);
                reason.stack = event.stack || '';
                listeners.unhandledrejection({ reason });
            } else if (event.type === 'livewire') {
                fail({ status: event.status, content: event.content, preventDefault() {} });
            }
        }

        process.stdout.write(JSON.stringify(posted));
        JS;

    /**
     * @param  list<array<string, mixed>>  $events
     * @return list<array<string, mixed>> what the beacon posted to /client-error
     */
    private function capture(array $events, bool $modernBrowser = true): array
    {
        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            $this->markTestSkipped('Node is not installed.');
        }

        $dir = sys_get_temp_dir().'/client-error-capture-'.getmypid().'-'.uniqid();
        mkdir($dir);
        file_put_contents("{$dir}/harness.cjs", self::HARNESS);
        file_put_contents("{$dir}/capture.html", (string) $this->blade('<x-client-error-capture />'));
        file_put_contents("{$dir}/scenario.json", json_encode(['modern' => $modernBrowser, 'events' => $events]));

        $process = new Process([$node, "{$dir}/harness.cjs", "{$dir}/capture.html", "{$dir}/scenario.json"]);
        $process->setTimeout(30)->mustRun();

        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);

        return json_decode($process->getOutput(), true);
    }

    public function test_a_genuine_error_from_the_sites_own_code_is_reported(): void
    {
        $posted = $this->capture([
            ['type' => 'error', 'message' => "Uncaught TypeError: Cannot read properties of null (reading 'dataset')", 'filename' => 'https://gs.construction/build/assets/app.js', 'line' => 12, 'stack' => "TypeError: …\n    at https://gs.construction/build/assets/app.js:12:5"],
        ]);

        $this->assertCount(1, $posted);
        $this->assertSame('error', $posted[0]['kind']);
        $this->assertSame(12, $posted[0]['line']);
    }

    public function test_a_dropped_connection_is_not_reported_as_a_livewire_503(): void
    {
        // Livewire's onFailure: no response at all, handed on as 503 with no content.
        $this->assertSame([], $this->capture([
            ['type' => 'livewire', 'status' => 503, 'content' => null],
        ]));
    }

    public function test_a_real_failed_livewire_response_is_still_reported_with_its_body(): void
    {
        $posted = $this->capture([
            ['type' => 'livewire', 'status' => 500, 'content' => '<html><title>Server Error</title> chrome-extension: in the body is still ours</html>'],
            ['type' => 'livewire', 'status' => 503, 'content' => '<html><title>Service Unavailable</title></html>'],
        ]);

        $this->assertSame([
            'Livewire request failed: HTTP 500 on /areas-served/inverness',
            'Livewire request failed: HTTP 503 on /areas-served/inverness',
        ], array_column($posted, 'message'));
        $this->assertStringContainsString('Server Error', $posted[0]['stack']);
    }

    public function test_a_rejection_from_a_safari_extension_is_not_reported(): void
    {
        $this->assertSame([], $this->capture([
            ['type' => 'rejection', 'message' => "null is not an object (evaluating 'document.body.appendChild')", 'stack' => "@webkit-masked-url://hidden/:9:634978\n@webkit-masked-url://hidden/:9:612984"],
        ]));
    }

    public function test_nothing_is_reported_from_a_browser_too_old_to_run_the_site(): void
    {
        $this->assertSame([], $this->capture([
            ['type' => 'error', 'message' => 'Uncaught ReferenceError: $flux is not defined', 'filename' => 'https://gs.construction/livewire-7b2d4e3f/livewire.min.js', 'line' => 5],
            ['type' => 'livewire', 'status' => 500, 'content' => '<html>Server Error</html>'],
        ], modernBrowser: false));
    }

    public function test_the_kits_noise_list_applies(): void
    {
        $this->assertSame([], $this->capture([
            ['type' => 'rejection', 'message' => 'Load failed'],
            ['type' => 'rejection', 'message' => 'Invalid call to runtime.sendMessage(). Tab not found.'],
            ['type' => 'rejection', 'message' => 'Could not load "marker".'],
        ]));
    }
}
