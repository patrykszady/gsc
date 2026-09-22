@props(['title' => null, 'metaDescription' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Polyfill requestIdleCallback for Safari/iOS (< 17.4) so inline and bundled
         scripts don't throw "Can't find variable: requestIdleCallback". Must run
         before any other script. --}}
    <script>
        window.requestIdleCallback = window.requestIdleCallback || function (cb) {
            var start = Date.now();
            return setTimeout(function () {
                cb({ didTimeout: false, timeRemaining: function () { return Math.max(0, 50 - (Date.now() - start)); } });
            }, 1);
        };
        window.cancelIdleCallback = window.cancelIdleCallback || function (id) { clearTimeout(id); };
    </script>

    {{-- SEO: title, description, canonical, robots, OG, Twitter, JSON-LD (ralphjsmit/laravel-seo) --}}
    {{-- Static views may pass title/metaDescription as layout props. They are
         FALLBACKS only: Livewire #[Title] also arrives via $title, so anything
         set programmatically (SeoService/SEOBuilder) must keep precedence. --}}
    @php($__seoBuilder = app(\App\Support\SEO\SEOBuilder::class)->fallbackTitle($title)->fallbackDescription($metaDescription))
    {!! seo($__seoBuilder->build()) !!}
    @if($__kw = $__seoBuilder->keywordList())
        <meta name="keywords" content="{{ implode(', ', $__kw) }}">
    @endif

    {{-- hreflang: explicit US-English signal (single-language site, prevents Google guessing) --}}
    <link rel="alternate" hreflang="en-us" href="{{ url()->current() }}" />
    <link rel="alternate" hreflang="x-default" href="{{ url()->current() }}" />

    {{-- Additional SEO --}}
    {{-- One name everywhere. Google picks the site name from the WebSite
         schema, og:site_name, the title and headings, and shows the bare
         domain when those disagree — these used to say "GS Construction"
         while everything else said "GS Construction & Remodeling". From
         config, so another tenant never renders this business's name. --}}
    <meta name="application-name" content="{{ config('brand.display_name', config('brand.name')) }}">
    <meta name="apple-mobile-web-app-title" content="{{ config('brand.display_name', config('brand.name')) }}">
    <meta name="author" content="{{ config('brand.display_name', config('brand.name')) }}">
    <meta name="publisher" content="{{ config('brand.display_name', config('brand.name')) }}">
    <meta name="copyright" content="GS Construction">
    <meta name="geo.region" content="US-IL">
    <meta name="geo.placename" content="Chicago">
    <meta name="geo.position" content="42.0884;-87.9806">
    <meta name="ICBM" content="42.0884, -87.9806">

    {{-- AEO / GEO: AI & Answer Engine Optimization --}}
    <link rel="alternate" type="text/plain" href="{{ url('/llms.txt') }}" title="LLM Context">
    <link rel="alternate" type="text/plain" href="{{ url('/llms-full.txt') }}" title="LLM Context (Full)">
    <link rel="alternate" type="application/json" href="{{ url('/ai-feed.json') }}" title="AI Feed">
    <link rel="alternate" type="application/atom+xml" href="{{ url('/feed/updates.atom') }}" title="Recently Updated Pages">
    {{-- :reviews / :cities filled from CompanyStats — see config/brand.php. --}}
    <meta name="ai-content-description" content="{{ strtr(config('brand.ai_description', config('geo.site_description')), [
        ':reviews' => \App\Support\CompanyStats::reviewsCountLabel(),
        ':cities' => \App\Support\CompanyStats::citiesServedLabel(),
    ]) }}">

    {{-- Hreflang for bilingual support --}}
    <x-hreflang />


    {{-- This site's own icons and manifest (per tenant) — see App\Support\SiteIcons. --}}
    <x-site-icons />

    {{-- Preconnect to third-party origins for faster loading --}}
    <link rel="preconnect" href="https://challenges.cloudflare.com" crossorigin>
    @if(config('services.google.maps_browser_key'))
    <link rel="preconnect" href="https://maps.googleapis.com" crossorigin>
    <link rel="preconnect" href="https://maps.gstatic.com" crossorigin>
    @endif

    {{-- Fonts (local + preloaded - only Latin subset, ext loaded on demand via CSS) --}}
    <link rel="preload" as="font" type="font/woff2" href="{{ Vite::asset('node_modules/@fontsource-variable/source-sans-3/files/source-sans-3-latin-wght-normal.woff2') }}" crossorigin>
    <link rel="preload" as="font" type="font/woff2" href="{{ Vite::asset('node_modules/@fontsource-variable/roboto-slab/files/roboto-slab-latin-wght-normal.woff2') }}" crossorigin>

    {{-- Styles --}}
    @vite(\App\Support\Theme::viteEntries(\App\Models\Site::current()))

    {{-- Flux injects CSS rules via insertRule(). Older browsers (e.g. Chrome 79)
         can throw on @layer rules and break component bootstrapping. For legacy
         engines only, swallow that specific parse failure so the page remains
         functional. --}}
    <script>
        (function () {
            if ('CSSLayerBlockRule' in window) return;
            if (!window.CSSStyleSheet || !CSSStyleSheet.prototype) return;
            if (typeof CSSStyleSheet.prototype.insertRule !== 'function') return;

            var originalInsertRule = CSSStyleSheet.prototype.insertRule;

            CSSStyleSheet.prototype.insertRule = function (rule, index) {
                try {
                    return originalInsertRule.call(this, rule, index);
                } catch (error) {
                    var isLayerRule = typeof rule === 'string' && rule.indexOf('@layer') !== -1;
                    var message = error && error.message ? String(error.message) : '';
                    var isKnownLegacyParseError = message.indexOf('Failed to parse the rule') !== -1;

                    if (isLayerRule && isKnownLegacyParseError) {
                        return -1;
                    }

                    throw error;
                }
            };
        })();
    </script>

    @fluxAppearance

    {{-- Initialize image cache for LQIP (must be before any components) --}}
    <script>
        window.imageCache = window.imageCache || new Map();
    </script>

    {{-- Dynamic head content (preload links, etc.) --}}
    @stack('head')

    {{-- Client-side JavaScript error beacon. Registered at parse time (not
         deferred) so it captures errors during Alpine/Livewire hydration. The
         Microsoft Clarity API only exposes an aggregate error COUNT, never the
         message or stack — this forwards the actual error text to the
         `client_errors` log channel (viewable in /log-viewer). --}}
    <script>
        (function () {
            var endpoint = '{{ route('client-error') }}';
            var seen = {};
            var sent = 0;
            var MAX_PER_PAGE = 10;

            function report(kind, data) {
                try {
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
                if (/showModal.*already open/i.test(message)) {
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

    {{-- Google Maps bootstrap: set up importLibrary immediately in <head> so it's ready
         before Alpine initializes, and pre-warm both libraries so download starts at
         parse time (not at scroll-to-map time). No render-blocking cost — the API
         script is loaded async. --}}
    @if(config('services.google.maps_browser_key'))
    <script>
        (g=>{var h,a,k,p="The Google Maps JavaScript API",c="google",l="importLibrary",q="__ib__",m=document,b=window;b=b[c]||(b[c]={});var d=b.maps||(b.maps={}),r=new Set,e=new URLSearchParams,u=()=>h||(h=new Promise(async(f,n)=>{await (a=m.createElement("script"));e.set("libraries",[...r]+"");for(k in g)e.set(k.replace(/[A-Z]/g,t=>"_"+t[0].toLowerCase()),g[k]);e.set("callback",c+".maps."+q);a.src=`https://maps.googleapis.com/maps/api/js?`+e;d[q]=f;a.onerror=()=>h=n(Error(p+" could not load."));a.nonce=m.querySelector("script[nonce]")?.nonce||"";m.head.append(a)}));d[l]?console.warn(p+" only loads once. Ignoring:",g):d[l]=(f,...n)=>r.add(f)&&u().then(()=>d[l](f,...n))})({key:"{{ config('services.google.maps_browser_key') }}",v:"weekly"});
        // Pre-warm: start downloading Maps API immediately so the download
        // is done (or nearly done) by the time the map component initializes.
        window.__mapsPrewarm = google.maps.importLibrary('maps').catch(() => {});
        // For resources/js/branded-map.js, should it ever need to load the API itself.
        window.__mapsKey = @js(config('services.google.maps_browser_key'));
    </script>
    @endif

    {{-- Comprehensive Schema.org Structured Data --}}
    <x-schema-org />

    {{-- Founder Person schema (E-E-A-T) --}}
    <x-person-schema />
</head>
<body class="min-h-screen bg-white font-sans text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
    {{-- Navbar --}}
    <livewire:navbar />

    {{-- Main content --}}
    <main>
        {{ $slot }}
    </main>

    {{-- Footer --}}
    <x-footer />

    @fluxScripts
    {{-- Analytics event tracking (deferred to reduce TBT) --}}
    <script>
        // Defer analytics setup to after page is interactive
        requestIdleCallback(function() {
        // Session tracking for users who might have GA blocked
        (function() {
            const sessionKey = 'gs_session';
            const eventsKey = 'gs_events';
            
            // Generate or retrieve session ID
            if (!sessionStorage.getItem(sessionKey)) {
                sessionStorage.setItem(sessionKey, 'gs_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9));
            }
            
            // Track event locally (works even if GA is blocked)
            window.trackEventLocal = function(eventName, eventData) {
                const events = JSON.parse(localStorage.getItem(eventsKey) || '[]');
                events.push({
                    event: eventName,
                    data: eventData,
                    timestamp: Date.now(),
                    session: sessionStorage.getItem(sessionKey),
                    page: window.location.pathname,
                    referrer: document.referrer || 'direct'
                });
                // Keep last 50 events
                if (events.length > 50) events.shift();
                localStorage.setItem(eventsKey, JSON.stringify(events));
            };
            
            // Track page view timing
            window.trackPageTiming = function() {
                const timing = performance.timing || {};
                const loadTime = timing.loadEventEnd - timing.navigationStart;
                const domReady = timing.domContentLoadedEventEnd - timing.navigationStart;
                return { loadTime, domReady };
            };
            
            // Track scroll depth
            let maxScroll = 0;
            let scrollMilestones = { 25: false, 50: false, 75: false, 90: false };
            window.addEventListener('scroll', function() {
                const scrollPercent = Math.round((window.scrollY / (document.body.scrollHeight - window.innerHeight)) * 100);
                maxScroll = Math.max(maxScroll, scrollPercent);
                
                [25, 50, 75, 90].forEach(function(milestone) {
                    if (scrollPercent >= milestone && !scrollMilestones[milestone]) {
                        scrollMilestones[milestone] = true;
                        window.trackEventLocal('scroll_depth', { depth: milestone });
                    }
                });
            }, { passive: true });
            
            // Track time on page
            const pageStartTime = Date.now();
            window.addEventListener('beforeunload', function() {
                const timeOnPage = Math.round((Date.now() - pageStartTime) / 1000);
                window.trackEventLocal('page_exit', { 
                    time_on_page: timeOnPage,
                    max_scroll: maxScroll 
                });
                // Send beacon for reliable tracking even on page exit
            });
            
            // Track engagement time (user is actively interacting)
            let engagementTime = 0;
            let lastActivity = Date.now();
            let isEngaged = true;
            
            ['click', 'scroll', 'keypress', 'mousemove', 'touchstart'].forEach(function(event) {
                document.addEventListener(event, function() {
                    if (!isEngaged) {
                        isEngaged = true;
                    }
                    lastActivity = Date.now();
                }, { passive: true });
            });
            
            setInterval(function() {
                if (isEngaged && (Date.now() - lastActivity < 30000)) {
                    engagementTime++;
                } else {
                    isEngaged = false;
                }
            }, 1000);
            
            // Expose engagement time
            window.getEngagementTime = function() { return engagementTime; };
        })();
        
        // First-party analytics: send phone/email/form/CTA events to our own
        // /admin dashboard so we capture conversions even when GA is blocked or
        // the visitor is outside the US (GA only loads for US visitors).
        window.trackServerEvent = function(type, label) {
            try {
                const token = document.querySelector('meta[name="csrf-token"]')?.content;
                if (!token) return;
                let sessionId = null;
                try { sessionId = sessionStorage.getItem('gs_session'); } catch (e) {}
                fetch('{{ route('track-event') }}', {
                    method: 'POST',
                    keepalive: true,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': token,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({
                        type: type,
                        label: (label || '').toString().substring(0, 255),
                        page_path: window.location.pathname,
                        referrer: document.referrer ? document.referrer.substring(0, 255) : null,
                        session_id: sessionId,
                        // Tell the server whether client-side GA already fired this
                        // (non-US / ad-blocked) — avoids double counting.
                    })
                }).catch(function () {});
            } catch (e) {}
        };

        // Track CTA button clicks with GA4 + local fallback
        window.trackCTA = function(buttonText, buttonLocation) {
            const eventData = {
                button_text: buttonText,
                button_location: buttonLocation || 'unknown',
                page_path: window.location.pathname,
                page_title: document.title,
                engagement_time: window.getEngagementTime ? window.getEngagementTime() : 0
            };
            window.trackEventLocal('cta_click', eventData);
            window.trackServerEvent('cta_click', buttonText);
        };

        // Track form interactions with timing
        window.trackFormStart = function(formName) {
            const eventData = {
                form_name: formName,
                page_path: window.location.pathname,
                time_to_form: window.getEngagementTime ? window.getEngagementTime() : 0
            };
            window.trackEventLocal('form_start', eventData);
        };
        
        // Track outbound link clicks
        document.addEventListener('click', function(e) {
            const link = e.target.closest('a[href]');
            if (link && link.hostname !== window.location.hostname) {
                const eventData = {
                    link_url: link.href,
                    link_text: link.textContent?.trim().substring(0, 100),
                    page_path: window.location.pathname
                };
                window.trackEventLocal('outbound_click', eventData);
            }
        });
        
        // Track phone/email clicks
        document.addEventListener('click', function(e) {
            const link = e.target.closest('a[href^="tel:"], a[href^="mailto:"]');
            if (link) {
                const isPhone = link.href.startsWith('tel:');
                const eventData = {
                    contact_type: isPhone ? 'phone' : 'email',
                    contact_value: link.href.replace(/^(tel:|mailto:)/, ''),
                    page_path: window.location.pathname
                };
                window.trackEventLocal('contact_click', eventData);
                window.trackServerEvent(isPhone ? 'phone_click' : 'email_click', eventData.contact_value);
            }
        });
        }, { timeout: 2000 }); // End requestIdleCallback
    </script>

    {{-- Deferred Third-Party Scripts (loaded after main content for better LCP) --}}
    <script>
        // Load analytics after page is interactive
        function loadDeferredScripts() {

            {{-- The project id is stored per tenant from /admin (App\Support\Seo\ClaritySettings),
                 with the env value only as a fallback until every tenant is imported off it — see
                 seo:credentials-import-from-env. Reading config('services.microsoft.clarity_id')
                 here directly, like this used to, would go stale the moment an owner sets the id
                 from /admin or the env key is retired: the tag would just stop rendering, silently,
                 with nothing in any log. ClaritySettings::projectId() is the one resolver every
                 Clarity reader goes through (it's also what the export sync uses), and it caches the
                 lookup per tenant so this costs at most one query per few minutes, not one per page. --}}
            @php($__clarityProjectId = app(\App\Support\Seo\ClaritySettings::class)->projectId())
            @if($__clarityProjectId)
            // Microsoft Clarity
            (function(c,l,a,r,i,t,y){
                c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
                t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;
                y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
            })(window, document, "clarity", "script", "{{ $__clarityProjectId }}");
            @endif

        }
        
        // Use requestIdleCallback for best performance, fallback to setTimeout
        if ('requestIdleCallback' in window) {
            requestIdleCallback(loadDeferredScripts, { timeout: 3000 });
        } else {
            setTimeout(loadDeferredScripts, 1500);
        }
    </script>


    {{-- First-party form-submission tracking (all visitors, even when GA is off) --}}
    <script>
        document.addEventListener('livewire:init', () => {
            Livewire.on('contact-form-submitted', () => {
                if (window.trackServerEvent) window.trackServerEvent('form_submit', 'contact');
            });
            Livewire.on('job-application-submitted', () => {
                if (window.trackServerEvent) window.trackServerEvent('form_submit', 'careers_partnership');
            });
        });
    </script>
</body>
</html>
