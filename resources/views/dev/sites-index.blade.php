{{--
    Local-only tenant register: every site on this platform, what it overrides,
    what it claims, and what a given path does on each.

    A standalone document rather than x-layouts.app, pinning the SHARED Vite
    bundle: on jpeterson.localhost the theme's own bundle would be selected,
    and its @source globs do not cover resources/views/dev/, so this page would
    render unstyled. resources/views/services.blade.php sets the same
    precedent. Never reachable outside local — the route is registered inside
    an environment check.
--}}
<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Sites &mdash; local register</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
</head>
<body class="min-h-screen bg-zinc-950 font-sans text-zinc-300 antialiased">
<div class="mx-auto max-w-6xl px-6 py-10">

    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-white">Sites on this platform</h1>
            <p class="mt-1 text-sm text-zinc-400">
                One Laravel app, {{ $sites->count() }} tenants. The request host picks the tenant &mdash;
                locally that means <code class="text-sky-400">{slug}.localhost:{{ $port }}</code>, exactly as production uses the real host.
            </p>
        </div>
        <form method="GET" class="flex items-end gap-2">
            <div>
                <label for="path" class="block text-xs tracking-wide text-zinc-500 uppercase">Test a path</label>
                <input id="path" name="path" value="{{ $path }}"
                       class="mt-1 w-64 rounded-md border border-zinc-700 bg-zinc-900 px-3 py-1.5 font-mono text-sm text-zinc-100 focus:border-sky-500 focus:outline-none">
            </div>
            <button class="rounded-md bg-sky-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-sky-500">Check</button>
        </form>
    </div>

    <p class="mt-4 text-xs text-zinc-500">
        Current tenant for this request: <span class="font-mono text-zinc-300">{{ $current->slug }}</span> &mdash; {{ $via }}.
        Status codes are <em>predicted</em> from the route table and the tenant guard; a 200 can still 404 if the bound record does not exist for that tenant.
    </p>

    <div class="mt-8 grid gap-5 lg:grid-cols-2">
        @foreach ($sites as $row)
            @php $s = $row['site']; @endphp
            <div class="rounded-xl border border-zinc-800 bg-zinc-900/50 p-5"
                 style="border-left: 4px solid hsl({{ $row['hue'] }} 70% 55%)">

                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-lg font-semibold text-white">{{ $s->name }}</h2>
                    <code class="rounded bg-zinc-800 px-1.5 py-0.5 text-xs text-zinc-300">{{ $s->slug }}</code>
                    @if ($s->is_active)
                        <span class="rounded bg-emerald-950 px-1.5 py-0.5 text-xs text-emerald-300 ring-1 ring-emerald-800">live</span>
                    @else
                        <span class="rounded bg-amber-950 px-1.5 py-0.5 text-xs text-amber-300 ring-1 ring-amber-800">in build</span>
                    @endif
                    @if ($row['is_current'])
                        <span class="rounded bg-sky-950 px-1.5 py-0.5 text-xs text-sky-300 ring-1 ring-sky-800">you are here</span>
                    @endif
                </div>

                <dl class="mt-4 space-y-2 text-sm">
                    <div class="flex gap-3">
                        <dt class="w-28 shrink-0 text-xs tracking-wide text-zinc-500 uppercase">Local</dt>
                        <dd><a href="{{ $row['url'] }}" class="font-mono text-sky-400 hover:underline">{{ $row['url'] }}</a></dd>
                    </div>
                    <div class="flex gap-3">
                        <dt class="w-28 shrink-0 text-xs tracking-wide text-zinc-500 uppercase">Production</dt>
                        <dd class="font-mono text-zinc-400">{{ implode(', ', (array) $s->hosts) }}</dd>
                    </div>
                    <div class="flex gap-3">
                        <dt class="w-28 shrink-0 text-xs tracking-wide text-zinc-500 uppercase">Theme</dt>
                        <dd class="font-mono {{ $row['theme_exists'] ? 'text-zinc-400' : 'text-red-400' }}">
                            themes/{{ $s->theme }}{{ $row['theme_exists'] ? '' : ' — MISSING, falls through to resources/views' }}
                        </dd>
                    </div>
                    <div class="flex gap-3">
                        <dt class="w-28 shrink-0 text-xs tracking-wide text-zinc-500 uppercase">Overrides</dt>
                        <dd class="font-mono text-zinc-400">{{ $row['overlays'] ? implode(', ', $row['overlays']) : 'nothing — inherits all shared config' }}</dd>
                    </div>
                    <div class="flex gap-3">
                        <dt class="w-28 shrink-0 text-xs tracking-wide text-zinc-500 uppercase">Claims</dt>
                        <dd class="font-mono text-xs leading-relaxed text-zinc-400">{{ $row['claims'] ? implode(' · ', $row['claims']) : 'no exclusive paths' }}</dd>
                    </div>
                </dl>

                <div class="mt-4 rounded-lg bg-zinc-950/60 p-3">
                    <div class="flex items-center gap-2 text-xs">
                        <span class="rounded px-1.5 py-0.5 font-mono font-bold
                            {{ $row['status'] === '200' ? 'bg-emerald-950 text-emerald-300' : 'bg-red-950 text-red-300' }}">{{ $row['status'] }}</span>
                        <code class="text-zinc-300">{{ $path }}</code>
                        <span class="text-zinc-500">{{ $row['note'] }}</span>
                    </div>
                </div>

                @if ($row['nav'])
                    <div class="mt-4">
                        <p class="text-xs tracking-wide text-zinc-500 uppercase">Nav</p>
                        <ul class="mt-2 space-y-1 text-sm">
                            @foreach ($row['nav'] as $link)
                                <li class="flex items-center gap-2">
                                    <span class="w-9 shrink-0 rounded px-1 text-center font-mono text-[11px] font-bold
                                        @class([
                                            'bg-emerald-950 text-emerald-300' => $link['status'] === '200',
                                            'bg-red-950 text-red-300' => $link['status'] === '404',
                                            'bg-zinc-800 text-zinc-400' => ! in_array($link['status'], ['200', '404'], true),
                                        ])">{{ $link['status'] }}</span>
                                    <span class="text-zinc-300">{{ $link['label'] }}</span>
                                    <code class="text-xs text-zinc-500">{{ $link['href'] }}</code>
                                    @if ($link['status'] === '404')
                                        <span class="text-xs text-red-400">{{ $link['note'] }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @else
                    <p class="mt-4 text-xs text-zinc-500">No nav defined &mdash; add <code>config/sites/{{ $s->slug }}/nav.php</code>.</p>
                @endif
            </div>
        @endforeach
    </div>

    <div class="mt-10 rounded-xl border border-zinc-800 bg-zinc-900/30 p-5 text-sm text-zinc-400">
        <h3 class="font-semibold text-zinc-200">Notes</h3>
        <ul class="mt-2 list-disc space-y-1 pl-5">
            <li><code class="text-zinc-300">*.localhost</code> is resolved by the browser (RFC 6761), so no hosts file needs editing. WSL&rsquo;s own resolver does not know it &mdash; from bash use <code class="text-zinc-300">curl -H "Host: jpeterson.localhost" http://127.0.0.1:{{ $port }}/</code>.</li>
            <li><code class="text-zinc-300">?site={slug}</code> on 127.0.0.1 still works and now pins for the browser session; on a dev host it redirects to that tenant&rsquo;s own host.</li>
            <li>Sessions are host-scoped, so an admin login on one dev host does not carry to another. That is correct tenant isolation, not a bug.</li>
            <li><code class="text-zinc-300">php artisan sites:check</code> validates every tenant&rsquo;s theme, assets, identity and nav from the command line.</li>
        </ul>
    </div>
</div>
@fluxScripts
</body>
</html>
