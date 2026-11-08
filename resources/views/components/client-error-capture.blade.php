{{-- Client-side JavaScript error beacon, included in the <head> of the
     site layout. Registered at parse time (not deferred) so it captures
     errors during Alpine/Livewire hydration. The Microsoft Clarity API only
     exposes an aggregate error COUNT, never the message or stack — this
     forwards the actual error text to the `client_errors` log channel
     (viewable in /log-viewer) and the JS errors board on ss.systems.

     What never reaches the board is decided by the kit, so every site keeps
     the same list: BeaconScript::NOISE_PATTERN (extensions, in-app browsers,
     network drops, blocked Maps libraries — matched against the message,
     source and stack) and the kit's modern-browser test, CSS color-mix()
     (BeaconScript::MODERN_BROWSER_TEST, written out here so this page never
     depends on a kit release to render): a browser too old for Tailwind 4
     and Flux cannot run these pages as built. --}}
<script>
    (function () {
        var endpoint = '{{ route('client-error') }}';
        var seen = {};
        var sent = 0;
        var MAX_PER_PAGE = 10;
        var noise = new RegExp(@json(\SsSystems\Platform\Pulse\BeaconScript::NOISE_PATTERN), 'i');
        var modern = !!(window.CSS && CSS.supports && CSS.supports('color', 'color-mix(in srgb, red, blue)'));

        function report(kind, data) {
            try {
                if (!modern) return;
                if (sent >= MAX_PER_PAGE) return;
                var msg = (data.message || '').toString();
                // Drop un-actionable noise: the browser sanitizes cross-origin
                // script errors to "Script error." with no source/stack, and a
                // rejected promise or thrown plain object stringifies to
                // "[object Object]". Neither can be diagnosed or fixed, so they
                // only pollute the dashboard.
                if (!msg) return;
                if (msg === 'Script error.' && !data.source) return;
                if (msg.indexOf('[object Object]') !== -1) return;
                if (
                    msg.indexOf("Failed to execute 'insertRule' on 'CSSStyleSheet'") !== -1
                    && msg.indexOf('@layer base') !== -1
                ) return;
                // Scripts injected by in-app browsers (Facebook/Instagram)
                // reference their own globals that don't exist outside the
                // native shell — not our code, never actionable.
                if (msg.indexOf('_AutofillCallbackHandler') !== -1) return;
                if (msg.indexOf('__gCrWeb') !== -1) return;
                if (msg.indexOf('window.webkit.messageHandlers') !== -1) return;
                // The kit's list (see the comment above). A Livewire failure's
                // "stack" is the response body, which is ours — only an
                // error's or rejection's stack can name an extension frame.
                if (noise.test(msg + ' ' + (data.source || '') + (kind === 'livewire' ? '' : ' ' + (data.stack || '')))) return;
                var sig = kind + '|' + msg + '|' + (data.source || '') + '|' + (data.line || '');
                if (seen[sig]) return; // de-dupe storms (e.g. loops)
                seen[sig] = true;
                sent++;

                var token = document.querySelector('meta[name="csrf-token"]');
                token = token ? token.content : null;
                if (!token) return;

                fetch(endpoint, {
                    method: 'POST',
                    keepalive: true,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': token,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        kind: kind,
                        message: (data.message || 'Unknown error').toString().substring(0, 500),
                        source: data.source ? data.source.toString().substring(0, 255) : null,
                        line: data.line || null,
                        column: data.column || null,
                        stack: data.stack ? data.stack.toString().substring(0, 2000) : null,
                        page_path: window.location.pathname
                    })
                }).catch(function () {});
            } catch (e) { /* never let the reporter throw */ }
        }

        window.addEventListener('error', function (e) {
            // Third-party scripts only fail in ways we cannot fix: the log's
            // standing noise was Cloudflare's analytics beacon missing
            // Array.prototype.at on a 2020-era browser, plus injected
            // in-app-browser and extension code. Keep same-origin sources,
            // inline/eval'd code (no filename), and our own bundles;
            // drop errors sourced from other origins.
            var src = e.filename || '';
            if (src && src.indexOf('://') !== -1 && src.indexOf(window.location.origin + '/') !== 0) {
                return;
            }

            // Benign and not ours to fix: Safari re-executes the Flux
            // vendor bundle after a back/forward-cache restore, and its
            // customElements.define() calls throw the second time round.
            // The elements are already registered, so the page keeps
            // working — the throw IS the whole symptom. Reported once,
            // from one Safari session, and then sat at the top of the
            // dashboard for days looking like a live defect.
            if (/Cannot define multiple custom elements/i.test(e.message || '')) {
                return;
            }
            report('error', {
                message: e.message,
                source: e.filename,
                line: e.lineno,
                column: e.colno,
                stack: e.error && e.error.stack ? e.error.stack : null
            });
        });

        window.addEventListener('unhandledrejection', function (e) {
            var reason = e.reason;
            var message = (reason && reason.message) ? reason.message : String(reason);

            // Livewire's own error dialog: when two requests fail back to
            // back it calls showModal() on a dialog that is still open and
            // throws. That throw is a symptom — the failed request is the
            // fact, and it is reported by the Livewire hook below.
            if (/showModal\(\).*(already open|open non-modal)/i.test(message)) {
                return;
            }
            // A visitor whose page URL carries credentials (user:pass@host,
            // scanners mostly) makes Livewire's link prefetch throw. Not a
            // defect in this site, and nothing here can change that URL.
            if (/URL that includes credentials/i.test(message)) {
                return;
            }

            report('promise', { message: message, stack: (reason && reason.stack) ? reason.stack : null });
        });

        // The fact behind most "dialog" and "expired" symptoms: a Livewire
        // request that did not get a 200. Report the status and the start
        // of the body (a Cloudflare challenge page, a 419, a 500 …) so the
        // dashboard shows the cause, not the modal Livewire opened for it.
        document.addEventListener('livewire:init', function () {
            if (!window.Livewire || !window.Livewire.hook) return;
            window.Livewire.hook('request', function (ctx) {
                ctx.fail(function (res) {
                    // A request that never got an answer — the connection
                    // dropped, the visitor went offline or left the page —
                    // is handed to this hook as "503" with no content
                    // (Livewire's onFailure). Nothing reached the server:
                    // the two on the board (2026-10-26/27, Safari on iCloud
                    // Private Relay) were logged by nginx as 200s. It is the
                    // visitor's network, the same as "Failed to fetch".
                    if (!res || res.content === null || res.content === undefined) return;
                    var body = (res && res.content) ? String(res.content).replace(/\s+/g, ' ').substring(0, 300) : '';
                    report('livewire', {
                        message: 'Livewire request failed: HTTP ' + (res && res.status) + ' on ' + window.location.pathname,
                        stack: body
                    });
                });
            });
        });
    })();
</script>
