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
    <meta name="application-name" content="GS Construction">
    <meta name="apple-mobile-web-app-title" content="GS Construction">
    <meta name="author" content="GS Construction">
    <meta name="publisher" content="GS Construction">
    <meta name="copyright" content="GS Construction">
    <meta name="geo.region" content="US-IL">
    <meta name="geo.placename" content="Chicago">
    <meta name="geo.position" content="42.0884;-87.9806">
    <meta name="ICBM" content="42.0884, -87.9806">

    {{-- AEO / GEO: AI & Answer Engine Optimization --}}
    <link rel="alternate" type="text/plain" href="{{ url('/llms.txt') }}" title="LLM Context">
    <link rel="alternate" type="text/plain" href="{{ url('/llms-full.txt') }}" title="LLM Context (Full)">
    <link rel="alternate" type="application/json" href="{{ url('/ai-feed.json') }}" title="AI Feed">
    <meta name="ai-content-description" content="GS Construction & Remodeling: Kitchen, bathroom, and home remodeling services in Chicago suburbs. Family-owned, 40+ years experience, 53+ five-star reviews. Serving 89+ cities in Chicagoland. (224) 735-4200.">

    {{-- Hreflang for bilingual support --}}
    <x-hreflang />

    @if(config('services.google.ads_id'))
    <!-- Google Ads (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ config('services.google.ads_id') }}"></script>
    <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', '{{ config('services.google.ads_id') }}');
    </script>
    @endif

        {{-- Favicons.
                 Browser-first order:
                     • Modern browsers get the SVG first for the sharpest rendering.
                     • Search engines still have the large PNGs Google prefers around 48px.
                         https://developers.google.com/search/docs/appearance/favicon-in-search
                     • Smaller PNGs and the .ico file remain as fallbacks for older clients.
                     • We do not advertise the dark-mode SVG as a separate rel=icon entry
                         because crawlers may ignore media queries and pick the wrong asset.
                         Dark mode should stay handled inside favicon.svg itself. --}}
        <link rel="icon" type="image/svg+xml" sizes="any" href="{{ asset('favicon.svg?v=20260707') }}">
        {{-- Google Search prefers a favicon whose size is a multiple of 48px;
             48x48 is the base it downscales for the SERP. --}}
        <link rel="icon" type="image/png" sizes="48x48" href="{{ asset('favicon-48x48.png?v=20260707') }}">
        <link rel="icon" type="image/png" sizes="96x96" href="{{ asset('favicon-96x96.png?v=20260707') }}">
        <link rel="icon" type="image/png" sizes="144x144" href="{{ asset('favicon-144x144.png?v=20260707') }}">
        <link rel="icon" type="image/png" sizes="512x512" href="{{ asset('android-chrome-512x512.png?v=20260707') }}">
        <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('android-chrome-192x192.png?v=20260707') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png?v=20260707') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png?v=20260707') }}">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico?v=20260707') }}">
    {{-- Legacy `shortcut icon` is still the strongest signal Bingbot honors;
         without it Bing sometimes fails to attach any favicon to the SERP. --}}
    <link rel="shortcut icon" href="{{ asset('favicon.ico?v=20260707') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png?v=20260707') }}">
    <link rel="manifest" href="{{ asset('site.webmanifest?v=20260707') }}">
    <meta name="theme-color" content="#1a1a1a">

    {{-- Preconnect to third-party origins for faster loading --}}
    {{-- Only preconnect to Google Analytics for US visitors (privacy/GDPR compliance) --}}
    @if(($isUSVisitor ?? false) && config('services.google.analytics_id'))
    <link rel="preconnect" href="https://www.googletagmanager.com" crossorigin>
    @endif
    <link rel="preconnect" href="https://challenges.cloudflare.com" crossorigin>
    @if(config('services.google.places_api_key'))
    <link rel="preconnect" href="https://maps.googleapis.com" crossorigin>
    <link rel="preconnect" href="https://maps.gstatic.com" crossorigin>
    @endif

    {{-- Fonts (local + preloaded - only Latin subset, ext loaded on demand via CSS) --}}
    <link rel="preload" as="font" type="font/woff2" href="{{ Vite::asset('node_modules/@fontsource-variable/source-sans-3/files/source-sans-3-latin-wght-normal.woff2') }}" crossorigin>
    <link rel="preload" as="font" type="font/woff2" href="{{ Vite::asset('node_modules/@fontsource-variable/roboto-slab/files/roboto-slab-latin-wght-normal.woff2') }}" crossorigin>

    {{-- Styles --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])

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
                report('promise', {
                    message: (reason && reason.message) ? reason.message : String(reason),
                    stack: (reason && reason.stack) ? reason.stack : null
                });
            });
        })();
    </script>

    {{-- Google Maps bootstrap: set up importLibrary immediately in <head> so it's ready
         before Alpine initializes, and pre-warm both libraries so download starts at
         parse time (not at scroll-to-map time). No render-blocking cost — the API
         script is loaded async. --}}
    @if(config('services.google.places_api_key'))
    <script>
        (g=>{var h,a,k,p="The Google Maps JavaScript API",c="google",l="importLibrary",q="__ib__",m=document,b=window;b=b[c]||(b[c]={});var d=b.maps||(b.maps={}),r=new Set,e=new URLSearchParams,u=()=>h||(h=new Promise(async(f,n)=>{await (a=m.createElement("script"));e.set("libraries",[...r]+"");for(k in g)e.set(k.replace(/[A-Z]/g,t=>"_"+t[0].toLowerCase()),g[k]);e.set("callback",c+".maps."+q);a.src=`https://maps.googleapis.com/maps/api/js?`+e;d[q]=f;a.onerror=()=>h=n(Error(p+" could not load."));a.nonce=m.querySelector("script[nonce]")?.nonce||"";m.head.append(a)}));d[l]?console.warn(p+" only loads once. Ignoring:",g):d[l]=(f,...n)=>r.add(f)&&u().then(()=>d[l](f,...n))})({key:"{{ config('services.google.places_api_key') }}",v:"weekly"});
        // Pre-warm: start downloading Maps API immediately so the download
        // is done (or nearly done) by the time the map component initializes.
        window.__mapsPrewarm = google.maps.importLibrary('maps').catch(() => {});
    </script>
    @endif

    {{-- Comprehensive Schema.org Structured Data --}}
    <x-schema-org />

    {{-- Founder Person schema (E-E-A-T) --}}
    <x-person-schema />
</head>
<body class="min-h-screen bg-white font-sans text-zinc-900 antialiased dark:bg-slate-950 dark:text-zinc-100">
    {{-- Navbar --}}
    <livewire:navbar />

    {{-- Main content --}}
    <main>
        {{ $slot }}
    </main>

    {{-- Footer --}}
    <x-footer />

    @fluxScripts
@if(config('services.google.ads_id'))
<script>
  document.addEventListener('click', function(e) {
    if (e.target.closest('button') && e.target.closest('button').innerText.includes("Send message")) {
      setTimeout(function () {
        var textToTrack = "Thank you for your message! We'll get back to you soon.";
        if (document.body.textContent.includes(textToTrack)) {
            gtag('event', 'conversion', {'send_to': '{{ config('services.google.ads_id') }}/{{ config('services.google.ads_conversions.form') }}'});
        }
      }, 3000);
    }

    if(e.target.closest('a[href^="tel:"]')){
      gtag('event', 'conversion', {'send_to': '{{ config('services.google.ads_id') }}/{{ config('services.google.ads_conversions.phone') }}'});
    }
    if(e.target.closest('a[href^="mailto:"]')){
      gtag('event', 'conversion', {'send_to': '{{ config('services.google.ads_id') }}/{{ config('services.google.ads_conversions.email') }}'});
    }
  });
</script>
@endif
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
                        if (typeof gtag !== 'undefined') {
                            gtag('event', 'scroll', { percent_scrolled: milestone });
                        }
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
                if (navigator.sendBeacon && typeof gtag !== 'undefined') {
                    const data = new FormData();
                    data.append('time_on_page', timeOnPage);
                    data.append('max_scroll', maxScroll);
                    // GA4 doesn't support sendBeacon directly, but we log locally
                }
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
                        // event, so it only mirrors to GA4 when gtag is absent
                        // (non-US / ad-blocked) — avoids double counting.
                        gtag_active: (typeof gtag !== 'undefined')
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
            console.log('[GA Event] cta_click', eventData);
            window.trackEventLocal('cta_click', eventData);
            window.trackServerEvent('cta_click', buttonText);
            if (typeof gtag !== 'undefined') {
                gtag('event', 'cta_click', eventData);
            }
        };

        // Track form interactions with timing
        window.trackFormStart = function(formName) {
            const eventData = {
                form_name: formName,
                page_path: window.location.pathname,
                time_to_form: window.getEngagementTime ? window.getEngagementTime() : 0
            };
            console.log('[GA Event] form_start', eventData);
            window.trackEventLocal('form_start', eventData);
            if (typeof gtag !== 'undefined') {
                gtag('event', 'form_start', eventData);
            }
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
                if (typeof gtag !== 'undefined') {
                    gtag('event', 'click', { 
                        event_category: 'outbound',
                        event_label: link.href
                    });
                }
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
                if (typeof gtag !== 'undefined') {
                    gtag('event', isPhone ? 'phone_call' : 'email_click', eventData);
                    
                    // Google Ads click-to-call conversion tracking
                    if (isPhone) {
                        gtag('event', 'conversion', {
                            'send_to': '{{ config('services.google.ads_id') }}/{{ config('services.google.ads_conversions.call') }}',
                            'value': 1.0,
                            'currency': 'USD'
                        });
                    }
                }
            }
        });
        }, { timeout: 2000 }); // End requestIdleCallback
    </script>

    {{-- Deferred Third-Party Scripts (loaded after main content for better LCP) --}}
    <script>
        // Load analytics after page is interactive
        function loadDeferredScripts() {
            {{-- Google Analytics: Only load for US visitors (privacy/GDPR compliance) --}}
            {{-- Country detection via Cloudflare CF-IPCountry header in DetectCountry middleware --}}
            @if(($isUSVisitor ?? false) && config('services.google.analytics_id'))
            // Google Analytics (US visitors only)
            const gaScript = document.createElement('script');
            gaScript.async = true;
            gaScript.src = 'https://www.googletagmanager.com/gtag/js?id={{ config('services.google.analytics_id') }}';
            document.head.appendChild(gaScript);
            
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            window.gtag = gtag;
            gtag('js', new Date());
            gtag('config', '{{ config('services.google.analytics_id') }}', {
                'custom_map': {
                    'dimension1': 'entry_domain',
                    'dimension2': 'domain_source',
                    'dimension3': 'visitor_country'
                }
            });
            
            @if(config('services.google.ads_id'))
            // Google Ads conversion tracking
            gtag('config', '{{ config('services.google.ads_id') }}');
            @endif
            @if(isset($domainSource) && $domainSource !== 'direct')
            gtag('event', 'domain_entry', {
                'entry_domain': '{{ session('entry_domain', request()->getHost()) }}',
                'domain_source': '{{ $domainSource }}',
                'event_category': 'acquisition',
                'event_label': '{{ $domainSource }}'
            });
            @endif
            @else
            // Analytics disabled for non-US visitors (country: {{ $visitorCountry ?? 'unknown' }})
            // Define gtag as no-op so existing gtag() calls don't throw errors
            window.dataLayer = window.dataLayer || [];
            window.gtag = function() {};
            @endif

            @if(config('services.microsoft.clarity_id'))
            // Microsoft Clarity
            (function(c,l,a,r,i,t,y){
                c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
                t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;
                y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
            })(window, document, "clarity", "script", "{{ config('services.microsoft.clarity_id') }}");
            @endif

        }
        
        // Use requestIdleCallback for best performance, fallback to setTimeout
        if ('requestIdleCallback' in window) {
            requestIdleCallback(loadDeferredScripts, { timeout: 3000 });
        } else {
            setTimeout(loadDeferredScripts, 1500);
        }
    </script>

    {{-- GA conversion tracking for form submissions (US visitors only) --}}
    @if(($isUSVisitor ?? false) && config('services.google.analytics_id'))
        <script>
            document.addEventListener('livewire:init', () => {
                // Track successful form submission (GA4 recommended event)
                Livewire.on('contact-form-submitted', () => {
                    const eventData = {
                        form_name: 'contact',
                        page_path: window.location.pathname,
                        currency: 'USD',
                        value: 100 // Estimated lead value
                    };
                    console.log('[GA Event] generate_lead', eventData);
                    if (typeof gtag !== 'undefined') {
                        gtag('event', 'generate_lead', eventData);
                        
                        // Google Ads form submission conversion tracking
                        gtag('event', 'conversion', {
                            'send_to': '{{ config('services.google.ads_id') }}/{{ config('services.google.ads_conversions.lead') }}',
                            'value': 1.0,
                            'currency': 'USD'
                        });
                    }
                });

                // Track careers / partnership form submissions
                Livewire.on('job-application-submitted', () => {
                    const eventData = {
                        form_name: 'careers_partnership',
                        page_path: window.location.pathname,
                        currency: 'USD',
                        value: 50
                    };
                    console.log('[GA Event] generate_lead (careers)', eventData);
                    if (typeof gtag !== 'undefined') {
                        gtag('event', 'generate_lead', eventData);
                        gtag('event', 'sign_up', { method: 'careers_form' });
                    }
                });
            });
        </script>
    @endif

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
