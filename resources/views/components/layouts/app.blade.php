<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- SEOTools: Meta, OpenGraph, Twitter --}}
    {!! \Artesaos\SEOTools\Facades\SEOMeta::generate() !!}
    {!! \Artesaos\SEOTools\Facades\OpenGraph::generate() !!}
    {!! \Artesaos\SEOTools\Facades\TwitterCard::generate() !!}

    {{-- Additional SEO --}}
    <meta name="author" content="GS Construction">
    <meta name="geo.region" content="US-IL">
    <meta name="geo.placename" content="Chicago">

    {{-- Hreflang for bilingual support --}}
    <x-hreflang />

    {{-- Favicons --}}
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}" media="(prefers-color-scheme: light)">
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon-dark.svg') }}" media="(prefers-color-scheme: dark)">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">

    {{-- Preconnect to third-party origins for faster loading --}}
    {{-- Only preconnect to Google Analytics for US visitors (privacy/GDPR compliance) --}}
    @if(($isUSVisitor ?? false) && config('services.google.analytics_id'))
    <link rel="preconnect" href="https://www.googletagmanager.com" crossorigin>
    @endif
    <link rel="preconnect" href="https://challenges.cloudflare.com" crossorigin>
    @if(config('services.google.places_api_key'))
    <link rel="preconnect" href="https://maps.googleapis.com" crossorigin>
    @endif

    {{-- Fonts (local + preloaded - only Latin subset, ext loaded on demand via CSS) --}}
    <link rel="preload" as="font" type="font/woff2" href="{{ Vite::asset('node_modules/@fontsource-variable/source-sans-3/files/source-sans-3-latin-wght-normal.woff2') }}" crossorigin>
    <link rel="preload" as="font" type="font/woff2" href="{{ Vite::asset('node_modules/@fontsource-variable/roboto-slab/files/roboto-slab-latin-wght-normal.woff2') }}" crossorigin>

    {{-- Styles --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance

    {{-- Initialize image cache for LQIP (must be before any components) --}}
    <script>
        window.imageCache = window.imageCache || new Map();
    </script>

    {{-- Dynamic head content (preload links, etc.) --}}
    @stack('head')

    {{-- Comprehensive Schema.org Structured Data --}}
    <x-schema-org />
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
                if (typeof gtag !== 'undefined') {
                    gtag('event', isPhone ? 'phone_call' : 'email_click', eventData);
                    
                    // Google Ads click-to-call conversion tracking
                    if (isPhone) {
                        gtag('event', 'conversion', {
                            'send_to': 'AW-17856827614/aJ93CLr_heYbEN6h5sJC',
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
            
            // Google Ads conversion tracking
            gtag('config', 'AW-17856827614');
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

            @if(config('services.google.places_api_key'))
            // Google Places API (only loads when needed via importLibrary)
            (g=>{var h,a,k,p="The Google Maps JavaScript API",c="google",l="importLibrary",q="__ib__",m=document,b=window;b=b[c]||(b[c]={});var d=b.maps||(b.maps={}),r=new Set,e=new URLSearchParams,u=()=>h||(h=new Promise(async(f,n)=>{await (a=m.createElement("script"));e.set("libraries",[...r]+"");for(k in g)e.set(k.replace(/[A-Z]/g,t=>"_"+t[0].toLowerCase()),g[k]);e.set("callback",c+".maps."+q);a.src=`https://maps.googleapis.com/maps/api/js?`+e;d[q]=f;a.onerror=()=>h=n(Error(p+" could not load."));a.nonce=m.querySelector("script[nonce]")?.nonce||"";m.head.append(a)}));d[l]?console.warn(p+" only loads once. Ignoring:",g):d[l]=(f,...n)=>r.add(f)&&u().then(()=>d[l](f,...n))})({
                key: "{{ config('services.google.places_api_key') }}",
                v: "weekly"
            });
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
                            'send_to': 'AW-17856827614/RnBJCKCGk-YbEN6h5sJC',
                            'value': 1.0,
                            'currency': 'USD'
                        });
                    }
                });
            });
        </script>
    @endif
</body>
</html>
