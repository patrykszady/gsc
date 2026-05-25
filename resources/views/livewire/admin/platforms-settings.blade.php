<div class="space-y-8" wire:init="checkYelpSession">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">Platforms</flux:heading>
            <flux:subheading>Manage third-party platform connections (Google Business Profile, Yelp).</flux:subheading>
        </div>
        <flux:button wire:click="refreshStatus" icon="arrow-path" variant="subtle" size="sm">Refresh</flux:button>
    </div>

    {{-- Flash --}}
    @if (session('platforms-success'))
        <div class="rounded-lg bg-green-50 p-4 text-sm text-green-800 dark:bg-green-900/20 dark:text-green-200">
            {{ session('platforms-success') }}
        </div>
    @endif
    @if (session('platforms-error'))
        <div class="rounded-lg bg-red-50 p-4 text-sm text-red-800 dark:bg-red-900/20 dark:text-red-200">
            {{ session('platforms-error') }}
        </div>
    @endif

    {{-- ============ GBP ============ --}}
    <section class="space-y-3">
        <flux:heading size="lg">Google Business Profile</flux:heading>

        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="flex items-start gap-4">
                <div @class([
                    'flex size-12 shrink-0 items-center justify-center rounded-full',
                    'bg-green-100 dark:bg-green-900/30' => $gbpConnected && $gbpHealthStatus === 'ok',
                    'bg-red-100 dark:bg-red-900/30' => $gbpNeedsReauth || $gbpHealthStatus === 'error',
                    'bg-zinc-100 dark:bg-zinc-700' => !$gbpConnected && !$gbpNeedsReauth,
                ])>
                    @if($gbpConnected && $gbpHealthStatus === 'ok')
                        <flux:icon.check-circle class="size-6 text-green-600 dark:text-green-400" />
                    @elseif($gbpNeedsReauth || $gbpHealthStatus === 'error')
                        <flux:icon.exclamation-triangle class="size-6 text-red-600 dark:text-red-400" />
                    @else
                        <flux:icon.link class="size-6 text-zinc-400 dark:text-zinc-500" />
                    @endif
                </div>

                <div class="flex-1 space-y-1">
                    <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">
                        @if($gbpConnected && $gbpHealthStatus === 'ok') Connected
                        @elseif($gbpNeedsReauth) Re-authorization Required
                        @elseif($gbpHealthStatus === 'error') Connection Error
                        @else Not Connected
                        @endif
                    </h3>

                    @if($gbpEmail)
                        <p class="text-sm text-zinc-600 dark:text-zinc-400">
                            Authorized as <span class="font-medium">{{ $gbpEmail }}</span>
                            @if($gbpConnectedAt) &middot; {{ $gbpConnectedAt }} @endif
                        </p>
                    @endif

                    @if($gbpHealthError)
                        <p class="text-sm text-red-600 dark:text-red-400">{{ $gbpHealthError }}</p>
                    @endif
                </div>

                <div class="flex shrink-0 gap-2">
                    @if($gbpConnected && !$gbpNeedsReauth && $gbpHealthStatus === 'ok')
                        <flux:button wire:click="disconnectGbp" wire:confirm="Disconnect GBP? Automated uploads and posts will stop until you reconnect." variant="danger" size="sm">
                            Disconnect
                        </flux:button>
                    @endif
                    <flux:button wire:click="connectGbp" variant="{{ $gbpNeedsReauth || $gbpHealthStatus === 'error' ? 'primary' : ($gbpConnected ? 'subtle' : 'primary') }}" size="sm" icon="link">
                        {{ $gbpConnected ? 'Reconnect' : 'Connect Google Account' }}
                    </flux:button>
                </div>
            </div>

            {{-- Configuration Status --}}
            <div class="-mx-6 mt-6 border-t border-zinc-200 px-6 pt-4 dark:border-zinc-700">
                <h4 class="text-sm font-semibold text-zinc-900 dark:text-white mb-3">Configuration Status</h4>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 text-sm">
                    @php
                        $gbpCfg = config('services.google.business_profile');
                        $gbpChecks = [
                            'GOOGLE_BUSINESS_PROFILE_ENABLED' => !empty($gbpCfg['enabled']),
                            'CLIENT_ID' => !empty($gbpCfg['client_id']),
                            'CLIENT_SECRET' => !empty($gbpCfg['client_secret']),
                            'ACCOUNT_ID' => !empty($gbpCfg['account_id']),
                            'LOCATION_ID' => !empty($gbpCfg['location_id']),
                            'Refresh Token (DB or .env)' => app(\App\Services\GoogleBusinessProfileService::class)->hasRefreshToken(),
                        ];
                    @endphp
                    @foreach($gbpChecks as $label => $ok)
                        <div class="flex items-center gap-2">
                            <span class="size-2 rounded-full {{ $ok ? 'bg-green-500' : 'bg-red-500' }}"></span>
                            <span class="text-zinc-700 dark:text-zinc-300">{{ $label }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    {{-- ============ Yelp ============ --}}
    <section class="space-y-3">
        <flux:heading size="lg">Yelp for Business</flux:heading>

        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800 space-y-4">
            <div class="rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
                <strong>Heads up:</strong> Yelp does not provide a public photo-upload API for non-partner accounts.
                Photo uploads are performed by automating biz.yelp.com via headless Chromium. This may violate
                Yelp's Terms of Service and risks account suspension. Credentials below are encrypted at rest.
            </div>

            <div class="rounded-lg bg-sky-50 p-3 text-sm text-sky-800 dark:bg-sky-900/20 dark:text-sky-200">
                <strong>How login works:</strong> When you save credentials below (or click <em>Verify Login</em>),
                a remote Chromium session opens <em>right here in this page</em>. Complete any CAPTCHA / 2FA / device
                verification in the embedded viewer. The session is then persisted, and all future uploads run
                silently in the background.
                <br><br>
                <strong>Email verification link?</strong> If Yelp emails you a &ldquo;Confirm Email&rdquo; link,
                <em>right-click &rarr; Copy link</em> in your email client, then paste it into the address bar of
                the embedded Chromium viewer and press Enter. The viewer closes itself once Yelp confirms.
            </div>

            <form wire:submit="saveYelp" class="space-y-4">
                <flux:field>
                    <flux:label>Yelp business email</flux:label>
                    <flux:input wire:model="yelpEmail" type="email" placeholder="owner@example.com" autocomplete="off" />
                    <flux:error name="yelpEmail" />
                </flux:field>

                <flux:field>
                    <flux:label>
                        Password
                        @if($yelpHasPassword)
                            <span class="ml-2 text-xs text-green-600 dark:text-green-400">(saved &mdash; leave blank to keep)</span>
                        @endif
                    </flux:label>
                    <flux:input wire:model="yelpPassword" type="password" placeholder="{{ $yelpHasPassword ? '••••••••' : 'Set password' }}" autocomplete="new-password" />
                    <flux:error name="yelpPassword" />
                </flux:field>

                <div class="flex items-center gap-3">
                    <flux:button type="submit" variant="primary" size="sm" icon="check">Save</flux:button>
                    @if($yelpHasPassword)
                        <flux:button type="button" wire:click="clearYelpPassword" wire:confirm="Clear the saved Yelp password?" variant="danger" size="sm">
                            Clear password
                        </flux:button>
                    @endif
                </div>
            </form>

            {{-- Status --}}
            <div class="border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <div class="flex items-center justify-between mb-2">
                    <h4 class="text-sm font-semibold text-zinc-900 dark:text-white">Status</h4>
                    <div class="flex items-center gap-2">
                        <flux:button type="button" wire:click="checkYelpSession" variant="subtle" size="xs" icon="arrow-path">
                            <span wire:loading.remove wire:target="checkYelpSession">Check session</span>
                            <span wire:loading wire:target="checkYelpSession">Checking…</span>
                        </flux:button>
                        <flux:button type="button" wire:click="verifyYelpLogin" variant="primary" size="xs" icon="window">
                            Verify Login
                        </flux:button>
                    </div>
                </div>
                <div class="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                    @php
                        $cfg = config('services.yelp.business');
                        $sessionLabel = $yelpAuthenticated === true
                            ? 'Browser session: logged in'
                            : ($yelpAuthenticated === false ? 'Browser session: NOT logged in' : 'Browser session: unknown (click Check)');
                        $checks = [
                            'Email saved' => $yelpEmail !== '',
                            'Password saved' => $yelpHasPassword,
                            'Node binary' => !empty($cfg['node_binary']),
                            'User data dir' => !empty($cfg['user_data_dir']),
                            $sessionLabel => $yelpAuthenticated === true,
                        ];
                    @endphp
                    @foreach($checks as $label => $ok)
                        <div class="flex items-center gap-2">
                            <span class="size-2 rounded-full {{ $ok ? 'bg-green-500' : 'bg-red-500' }}"></span>
                            <span class="text-zinc-700 dark:text-zinc-300">{{ $label }}</span>
                        </div>
                    @endforeach
                </div>
                <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">
                    Click <strong>Verify Login</strong> any time Yelp invalidates the session (e.g. after a password change
                    or a long idle period). The embedded browser closes itself once login succeeds.
                </p>
            </div>

            {{-- Remote-login viewer (Xvfb + noVNC) --}}
            @if($yelpRemoteError)
                <div class="rounded-lg bg-red-50 p-3 text-sm text-red-800 dark:bg-red-900/20 dark:text-red-200">
                    <strong>Remote login failed:</strong> {{ $yelpRemoteError }}
                </div>
            @endif

            @if($yelpRemoteOpen && $yelpRemoteUrl)
                <div
                    class="border-t border-zinc-200 pt-4 dark:border-zinc-700"
                    wire:poll.4s="pollYelpRemoteLogin"
                >
                    <div class="flex items-center justify-between mb-2">
                        <h4 class="text-sm font-semibold text-zinc-900 dark:text-white">
                            Remote login viewer
                            <span class="ml-2 text-xs font-normal text-zinc-500">
                                Complete login / captcha / 2FA in the embedded browser below.
                            </span>
                        </h4>
                        <flux:button type="button" wire:click="stopYelpRemoteLogin" variant="danger" size="xs" icon="x-mark">
                            Close viewer
                        </flux:button>
                    </div>
                    <div class="overflow-hidden rounded-lg border border-zinc-300 bg-black dark:border-zinc-600" style="aspect-ratio: 16/10;">
                        <iframe
                            src="{{ $yelpRemoteUrl }}"
                            class="w-full h-full"
                            allow="clipboard-read; clipboard-write"
                            sandbox="allow-scripts allow-same-origin allow-forms allow-popups"
                        ></iframe>
                    </div>
                    <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                        Session auto-expires
                        @if($yelpRemoteExpiresAt)
                            at {{ \Carbon\Carbon::createFromTimestamp($yelpRemoteExpiresAt)->diffForHumans() }}
                        @endif.
                        If the viewer fails to connect, make sure port
                        <code>{{ config('services.yelp.business.remote_login.ws_port') }}</code> is reachable
                        (or set <code>YELP_REMOTE_LOGIN_PUBLIC_URL</code> to a TLS-terminated reverse-proxy URL).
                    </p>
                </div>
            @endif
        </div>
    </section>
</div>
