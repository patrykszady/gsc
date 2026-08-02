<x-layouts.app>
    {{-- ==================== backdrop: engineering grid + glow ==================== --}}
    <div aria-hidden="true" class="pointer-events-none fixed inset-0 -z-10">
        <div class="absolute inset-0"
             style="background-image:
                linear-gradient(to right, rgba(56,189,248,.055) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(56,189,248,.055) 1px, transparent 1px);
                background-size: 4rem 4rem;"></div>
        <div class="absolute inset-0"
             style="background:
                radial-gradient(60rem 34rem at 70% -12%, rgba(14,165,233,.16), transparent 60%),
                radial-gradient(44rem 30rem at 8% 110%, rgba(2,132,199,.12), transparent 62%);"></div>
    </div>

    {{-- ==================== nav ==================== --}}
    <header class="mx-auto flex max-w-6xl items-center justify-between px-6 py-6">
        <a href="/" class="flex items-center gap-2.5">
            <span class="flex size-8 items-center justify-center rounded-lg bg-sky-500 font-mono text-sm font-bold text-white">SS</span>
            <span class="whitespace-nowrap font-semibold tracking-tight text-white">{{ config('brand.display_name') }}</span>
        </a>
        <nav class="flex items-center gap-2">
            {{-- anchor links are desktop-only; on phones the page is one scroll anyway --}}
            <div class="hidden items-center gap-2 sm:flex">
                <flux:button href="#platforms" variant="ghost" size="sm">Platforms</flux:button>
                <flux:button href="#capabilities" variant="ghost" size="sm">What we do</flux:button>
            </div>
            <flux:button href="mailto:{{ config('brand.email') }}" variant="primary" size="sm">Work with us</flux:button>
        </nav>
    </header>

    {{-- ==================== hero ==================== --}}
    <main>
        <section class="mx-auto max-w-6xl px-6 pb-20 pt-16 sm:pt-24">
            <div class="max-w-3xl">
                <p class="font-mono text-xs uppercase tracking-[0.25em] text-sky-400">Multi-site platform &middot; Chicago</p>
                <h1 class="mt-5 text-balance font-heading text-4xl font-bold tracking-tight text-white sm:text-6xl">
                    We build the systems that run businesses online.
                </h1>
                <p class="mt-6 max-w-2xl text-lg leading-relaxed text-zinc-400">
                    {{ config('brand.display_name') }} designs, builds, and operates complete web platforms —
                    the public site, the admin behind it, the AI that improves it, and the
                    backend that keeps it all running. One system, many sites.
                </p>
                <div class="mt-8 flex flex-wrap items-center gap-3">
                    <flux:button href="#platforms" variant="primary">See our platforms</flux:button>
                    <flux:button href="mailto:{{ config('brand.email') }}" variant="outline">{{ config('brand.email') }}</flux:button>
                </div>
            </div>

            {{-- stat strip --}}
            <dl class="mt-16 grid grid-cols-2 gap-px overflow-hidden rounded-2xl bg-white/5 ring-1 ring-white/10 sm:grid-cols-4">
                @foreach ([
                    ['Platforms', '3'],
                    ['Sites on one backend', config('app.name') ? \App\Models\Site::query()->count() : '3'],
                    ['SEO decisions automated', 'Daily'],
                    ['Uptime target', '99.9%'],
                ] as [$label, $value])
                    <div class="bg-zinc-950/60 px-6 py-5">
                        <dt class="text-xs text-zinc-500">{{ $label }}</dt>
                        <dd class="mt-1 font-mono text-2xl font-semibold text-white">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>

        {{-- ==================== capabilities ==================== --}}
        <section id="capabilities" class="mx-auto max-w-6xl scroll-mt-20 px-6 py-16">
            <h2 class="font-heading text-2xl font-bold text-white sm:text-3xl">What we do</h2>
            <p class="mt-3 max-w-2xl text-zinc-400">Three disciplines, one team — every platform we ship uses all three.</p>

            <div class="mt-10 grid gap-6 md:grid-cols-3">
                @foreach ([
                    [
                        'title' => 'Website creation',
                        'body' => 'Design-to-deploy builds: fast public sites with structured data, local SEO, and content systems an owner can actually run.',
                        'items' => ['Laravel + Livewire builds', 'Technical & local SEO', 'Content that ranks'],
                    ],
                    [
                        'title' => 'AI & automation',
                        'body' => 'Self-improving systems, not chat toys: automated SEO decisions with measured outcomes, content pipelines, and lead handling.',
                        'items' => ['Self-healing SEO autopilot', 'AI content pipelines', 'Automated lead routing'],
                    ],
                    [
                        'title' => 'Systems & backend',
                        'body' => 'The unglamorous part done right: multi-tenant platforms, admin dashboards, integrations, and the ops behind them.',
                        'items' => ['Multi-tenant architecture', 'Dashboards & reporting', 'API integrations'],
                    ],
                ] as $cap)
                    <div class="group rounded-2xl bg-white/[0.03] p-7 ring-1 ring-white/10 transition hover:bg-white/[0.06] hover:ring-sky-500/40">
                        <h3 class="font-heading text-lg font-semibold text-white">{{ $cap['title'] }}</h3>
                        <p class="mt-3 text-sm leading-relaxed text-zinc-400">{{ $cap['body'] }}</p>
                        <ul class="mt-5 space-y-2">
                            @foreach ($cap['items'] as $item)
                                <li class="flex items-center gap-2.5 text-sm text-zinc-300">
                                    <span class="size-1.5 rounded-full bg-sky-400"></span>{{ $item }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </section>

        {{-- ==================== platforms ==================== --}}
        <section id="platforms" class="mx-auto max-w-6xl scroll-mt-20 px-6 py-16">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 class="font-heading text-2xl font-bold text-white sm:text-3xl">Platforms we build &amp; run</h2>
                    <p class="mt-3 max-w-2xl text-zinc-400">Live products, operated end to end — not handed off.</p>
                </div>
            </div>

            <div class="mt-10 grid gap-6 lg:grid-cols-3">
                @foreach ([
                    [
                        'host' => 'gs.construction',
                        'url' => 'https://gs.construction',
                        'name' => 'GS Construction & Remodeling',
                        'live' => true,
                        'body' => 'Full platform for a Chicago-suburbs remodeler: public site, project portfolio, client portal integration, and an SEO autopilot that measures its own results.',
                        'tags' => ['Public site', 'Admin', 'SEO autopilot'],
                    ],
                    [
                        'host' => 'hive.contractors',
                        'url' => 'https://hive.contractors',
                        'name' => 'Hive Contractors',
                        'live' => true,
                        'body' => 'Operations backend for contracting businesses: lead intake and routing, dashboards, and the reporting that keeps jobs moving.',
                        'tags' => ['Lead routing', 'Dashboards', 'Ops'],
                    ],
                    [
                        'host' => 'jpeterson-design.com',
                        'url' => null,
                        'name' => 'J. Peterson Design',
                        'live' => false,
                        'body' => 'Portfolio and client platform for a North Shore interior design studio — running on the same multi-tenant backend as everything else here.',
                        'tags' => ['Portfolio', 'Multi-tenant'],
                    ],
                ] as $p)
                    <div class="group relative flex flex-col rounded-2xl bg-white/[0.03] p-7 ring-1 ring-white/10 transition hover:bg-white/[0.06] hover:ring-sky-500/40">
                        <div class="flex items-center justify-between gap-3">
                            <span class="font-mono text-xs text-sky-400">{{ $p['host'] }}</span>
                            @if ($p['live'])
                                <flux:badge color="lime" size="sm">Live</flux:badge>
                            @else
                                <flux:badge color="amber" size="sm">In build</flux:badge>
                            @endif
                        </div>
                        <h3 class="mt-3 font-heading text-lg font-semibold text-white">{{ $p['name'] }}</h3>
                        <p class="mt-3 flex-1 text-sm leading-relaxed text-zinc-400">{{ $p['body'] }}</p>
                        <div class="mt-5 flex flex-wrap gap-2">
                            @foreach ($p['tags'] as $tag)
                                <span class="rounded-full bg-white/5 px-2.5 py-1 text-xs text-zinc-400 ring-1 ring-white/10">{{ $tag }}</span>
                            @endforeach
                        </div>
                        @if ($p['url'])
                            {{-- stretched link: whole card clickable, valid markup --}}
                            <a href="{{ $p['url'] }}" target="_blank" rel="noopener"
                               class="absolute inset-0 rounded-2xl focus-visible:ring-2 focus-visible:ring-sky-500"
                               aria-label="Visit {{ $p['name'] }}"></a>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        {{-- ==================== CTA ==================== --}}
        <section class="mx-auto max-w-6xl px-6 py-20">
            <div class="rounded-3xl px-8 py-14 text-center ring-1 ring-sky-400/30 sm:px-14"
                 style="background: linear-gradient(135deg, #0284c7 0%, #075985 100%);">
                <h2 class="text-balance font-heading text-3xl font-bold text-white">Have a business that needs a system?</h2>
                <p class="mx-auto mt-4 max-w-xl text-sky-100">
                    We take on a small number of platform builds — sites we design, automate, and keep improving after launch.
                </p>
                <div class="mt-8">
                    <a href="mailto:{{ config('brand.email') }}"
                       class="inline-flex items-center rounded-lg bg-white px-6 py-3 text-sm font-semibold text-sky-800 shadow-lg transition hover:bg-sky-50">
                        {{ config('brand.email') }}
                    </a>
                </div>
            </div>
        </section>
    </main>

    {{-- ==================== footer ==================== --}}
    <footer class="border-t border-white/10">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-4 px-6 py-8 text-sm text-zinc-500">
            <p>&copy; {{ now()->year }} {{ config('brand.display_name') }} &middot; {{ config('brand.city') }}, {{ config('brand.state') }}</p>
            <div class="flex gap-5">
                <a href="https://gs.construction" target="_blank" rel="noopener" class="hover:text-zinc-300">gs.construction</a>
                <a href="https://hive.contractors" target="_blank" rel="noopener" class="hover:text-zinc-300">hive.contractors</a>
            </div>
        </div>
    </footer>
</x-layouts.app>
