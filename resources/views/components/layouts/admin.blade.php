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
    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=AW-17856827614"></script>
    <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());

    gtag('config', 'AW-17856827614');
    </script>
    {{-- Styles --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="min-h-screen bg-zinc-50 font-sans antialiased dark:bg-zinc-900">
    <flux:sidebar sticky stashable class="border-r border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
        <flux:sidebar.toggle class="lg:hidden" icon="x-mark" />

        <flux:brand href="{{ route('admin.dashboard') }}" logo="{{ asset('images/logo.svg') }}" name="GS Construction" class="px-2 dark:hidden" />
        <flux:brand href="{{ route('admin.dashboard') }}" logo="{{ asset('images/logo-dark.svg') }}" name="GS Construction" class="hidden px-2 dark:flex" />

        <flux:navlist variant="outline">
            <flux:navlist.item icon="home" href="{{ route('admin.dashboard') }}" :current="request()->routeIs('admin.dashboard')">
                Dashboard
            </flux:navlist.item>
            
            <flux:navlist.item icon="folder" href="{{ route('admin.projects.index') }}" :current="request()->routeIs('admin.projects.*')">
                Projects
            </flux:navlist.item>
            
            <flux:navlist.item icon="tag" href="{{ route('admin.tags.index') }}" :current="request()->routeIs('admin.tags.*')">
                Tags
            </flux:navlist.item>

            <flux:navlist.item icon="star" href="{{ route('admin.testimonials.index') }}" :current="request()->routeIs('admin.testimonials.*')">
                Reviews
            </flux:navlist.item>

            <flux:navlist.item icon="share" href="{{ route('admin.social-media.index') }}" :current="request()->routeIs('admin.social-media.*')">
                Social Media
            </flux:navlist.item>
        </flux:navlist>

        <flux:spacer />

        <flux:navlist variant="outline">
            <flux:navlist.item icon="arrow-left-start-on-rectangle" href="{{ route('home') }}">
                Back to Site
            </flux:navlist.item>
        </flux:navlist>

        {{-- User menu --}}
        <flux:dropdown position="top" align="start" class="max-lg:hidden">
            <flux:profile avatar="https://ui-avatars.com/api/?name={{ urlencode(auth()->user()?->name ?? 'Admin') }}&background=0ea5e9&color=fff" name="{{ auth()->user()?->name ?? 'Admin' }}" />

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
    <flux:main>
        {{ $slot }}
    </flux:main>

    @fluxScripts
</body>
</html>
