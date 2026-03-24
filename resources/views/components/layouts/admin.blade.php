<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'Admin' }} - {{ config('app.name', 'GS Construction') }}</title>

    {{-- Fonts --}}
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet" />
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
    {{-- Styles --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="min-h-screen lg:h-screen lg:overflow-hidden bg-zinc-50 font-sans antialiased dark:bg-zinc-900">
    <flux:sidebar sticky collapsible class="border-r border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

        <flux:sidebar.brand href="{{ route('admin.dashboard') }}" logo="{{ asset('images/logo.svg') }}" logo:dark="{{ asset('images/logo-dark.svg') }}" name="GS Construction" />

        <flux:sidebar.nav>
            <flux:sidebar.item icon="home" href="{{ route('admin.dashboard') }}" :current="request()->routeIs('admin.dashboard')">
                Dashboard
            </flux:sidebar.item>

            <flux:sidebar.item icon="folder" href="{{ route('admin.projects.index') }}" :current="request()->routeIs('admin.projects.*')">
                Projects
            </flux:sidebar.item>

            <flux:sidebar.item icon="tag" href="{{ route('admin.tags.index') }}" :current="request()->routeIs('admin.tags.*')">
                Tags
            </flux:sidebar.item>

            <flux:sidebar.item icon="star" href="{{ route('admin.testimonials.index') }}" :current="request()->routeIs('admin.testimonials.*')">
                Reviews
            </flux:sidebar.item>

            <flux:sidebar.item icon="share" href="{{ route('admin.social-media.index') }}" :current="request()->routeIs('admin.social-media.*')">
                Social Media
            </flux:sidebar.item>

            <flux:sidebar.item icon="building-storefront" href="{{ route('admin.gbp.index') }}" :current="request()->routeIs('admin.gbp.*')">
                Google Business
            </flux:sidebar.item>
        </flux:sidebar.nav>

        <flux:sidebar.spacer />

        <flux:sidebar.nav>
            <flux:sidebar.item icon="arrow-left-start-on-rectangle" href="{{ route('home') }}">
                Back to Site
            </flux:sidebar.item>
        </flux:sidebar.nav>

        {{-- User menu --}}
        <flux:dropdown position="top" align="start" class="max-lg:hidden">
            <flux:sidebar.profile avatar="https://ui-avatars.com/api/?name={{ urlencode(auth()->user()?->name ?? 'Admin') }}&background=0ea5e9&color=fff" name="{{ auth()->user()?->name ?? 'Admin' }}" />

            <flux:menu>
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <flux:menu.item icon="arrow-right-start-on-rectangle" type="submit">Logout</flux:menu.item>
                </form>
            </flux:menu>
        </flux:dropdown>
    </flux:sidebar>

    {{-- Mobile header --}}
    <flux:header sticky class="lg:hidden">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />
        <flux:spacer />
        <flux:profile avatar="https://ui-avatars.com/api/?name={{ urlencode(auth()->user()?->name ?? 'Admin') }}&background=0ea5e9&color=fff" />
    </flux:header>

    {{-- Main content --}}
    <flux:main class="lg:overflow-y-auto lg:min-h-0">
        {{ $slot }}
    </flux:main>
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
    @fluxScripts
</body>
</html>
