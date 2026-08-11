<div class="space-y-8">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">Platforms</flux:heading>
            <flux:subheading>Manage third-party platform connections (Google Business Profile, Meta, Yelp).</flux:subheading>
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

    {{-- ============ Meta (Instagram + Facebook) ============ --}}
    <section class="space-y-3">
        <flux:heading size="lg">Meta (Instagram + Facebook)</flux:heading>

        <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="flex items-start gap-4">
                <div @class([
                    'flex size-12 shrink-0 items-center justify-center rounded-full',
                    'bg-green-100 dark:bg-green-900/30' => $metaHealthStatus === 'ok',
                    'bg-amber-100 dark:bg-amber-900/30' => $metaHealthStatus === 'partial',
                    'bg-red-100 dark:bg-red-900/30' => in_array($metaHealthStatus, ['error', 'not_configured'], true),
                    'bg-zinc-100 dark:bg-zinc-700' => $metaHealthStatus === 'disabled',
                ])>
                    @if($metaHealthStatus === 'ok')
                        <flux:icon.check-circle class="size-6 text-green-600 dark:text-green-400" />
                    @elseif($metaHealthStatus === 'partial')
                        <flux:icon.exclamation-triangle class="size-6 text-amber-600 dark:text-amber-400" />
                    @elseif(in_array($metaHealthStatus, ['error', 'not_configured'], true))
                        <flux:icon.exclamation-triangle class="size-6 text-red-600 dark:text-red-400" />
                    @else
                        <flux:icon.link class="size-6 text-zinc-400 dark:text-zinc-500" />
                    @endif
                </div>

                <div class="flex-1 space-y-1">
                    <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">
                        @if($metaHealthStatus === 'ok') Connected
                        @elseif($metaHealthStatus === 'partial') Partially Connected
                        @elseif($metaHealthStatus === 'error') Connection Error
                        @elseif($metaHealthStatus === 'not_configured') Not Configured
                        @else Disabled
                        @endif
                    </h3>

                    @if($metaPageName || $metaPageId)
                        <p class="text-sm text-zinc-600 dark:text-zinc-400">
                            Facebook Page:
                            <span class="font-medium">{{ $metaPageName ?: 'Unknown' }}</span>
                            @if($metaPageId) &middot; ID {{ $metaPageId }} @endif
                        </p>
                    @endif

                    @if($metaInstagramUsername || $metaInstagramId)
                        <p class="text-sm text-zinc-600 dark:text-zinc-400">
                            Instagram:
                            <span class="font-medium">{{ $metaInstagramUsername ? '@' . $metaInstagramUsername : 'Unknown' }}</span>
                            @if($metaInstagramId) &middot; ID {{ $metaInstagramId }} @endif
                        </p>
                    @endif

                    @if($metaHealthError)
                        <p class="text-sm text-red-600 dark:text-red-400">{{ $metaHealthError }}</p>
                    @endif

                    @if($metaHealthWarning)
                        <p class="text-sm text-amber-700 dark:text-amber-300">{{ $metaHealthWarning }}</p>
                    @endif
                </div>

                <div class="flex shrink-0 gap-2">
                    @if($metaConnected)
                        <flux:button wire:click="testMetaConnection" variant="primary" size="sm" icon="paper-airplane">
                            Test Meta Connection
                        </flux:button>
                        <flux:button wire:click="disconnectMeta" wire:confirm="Disconnect Meta? Automated Instagram and Facebook posts will stop until you reconnect." variant="danger" size="sm">
                            Disconnect
                        </flux:button>
                    @endif
                    <flux:button wire:click="connectMeta" variant="{{ $metaConnected ? 'subtle' : 'primary' }}" size="sm" icon="link">
                        {{ $metaConnected ? 'Reconnect Facebook' : 'Connect Facebook' }}
                    </flux:button>
                </div>
            </div>

            <div class="-mx-6 mt-6 border-t border-zinc-200 px-6 pt-4 dark:border-zinc-700">
                <h4 class="mb-3 text-sm font-semibold text-zinc-900 dark:text-white">Configuration Status</h4>
                <div class="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                    @php
                        $metaCfg = config('services.meta');
                        $metaChecks = [
                            'Posting enabled' => !empty($metaCfg['enabled']),
                            'App credentials (META_APP_ID / SECRET)' => !empty($metaCfg['app_id']) && !empty($metaCfg['app_secret']),
                            'Connected via OAuth' => $metaConnected,
                            'Facebook Page discovered' => !empty($metaPageId),
                            'Instagram Business linked' => !empty($metaInstagramId),
                            'Instagram ready for posting' => $metaInstagramConfigured,
                            'Facebook ready for posting' => $metaFacebookConfigured,
                        ];
                    @endphp
                    @foreach($metaChecks as $label => $ok)
                        <div class="flex items-center gap-2">
                            <span class="size-2 rounded-full {{ $ok ? 'bg-green-500' : 'bg-red-500' }}"></span>
                            <span class="text-zinc-700 dark:text-zinc-300">{{ $label }}</span>
                        </div>
                    @endforeach
                </div>
                <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">
                    Use <code>php artisan social:post --platform=instagram --dry-run</code> to preview content,
                    then <code>php artisan social:post --platform=instagram --instagram-container-only</code> to verify Meta API upload without publishing.
                </p>
            </div>

            {{-- ===== Instagram (Puppeteer profile, used for location-tagging) ===== --}}
            <div class="-mx-6 mt-6 border-t border-zinc-200 px-6 pt-4 dark:border-zinc-700 space-y-4">
                <h4 class="text-sm font-semibold text-zinc-900 dark:text-white">Instagram (Puppeteer session)</h4>
                <flux:text class="text-sm">
                Daily IG posts publish via the Graph API, then a headless Chromium opens the post and adds the location tag via the IG web UI.
                That requires a logged-in <code>storage/app/instagram-puppeteer/</code> profile. If IG invalidates the session, posts will publish but
                location-tagging will silently fail — verify here, and re-login from the browser when needed.
            </flux:text>

            {{-- Status badge --}}
            <div class="flex items-center gap-3">
                <div @class([
                    'inline-flex items-center gap-2 px-3 py-1.5 rounded-full text-sm font-medium',
                    'bg-green-100 dark:bg-green-900/30' => $igSessionAuthenticated === true,
                    'bg-red-100 dark:bg-red-900/30' => $igSessionAuthenticated === false,
                    'bg-zinc-100 dark:bg-zinc-700' => $igSessionAuthenticated === null,
                ])>
                    @if($igSessionAuthenticated === true)
                        <flux:icon.check-circle class="size-4 text-green-700 dark:text-green-300" />
                    @elseif($igSessionAuthenticated === false)
                        <flux:icon.x-circle class="size-4 text-red-700 dark:text-red-300" />
                    @else
                        <flux:icon.question-mark-circle class="size-4 text-zinc-500" />
                    @endif
                    <span @class([
                        'text-green-700 dark:text-green-300' => $igSessionAuthenticated === true,
                        'text-red-700 dark:text-red-300' => $igSessionAuthenticated === false,
                        'text-zinc-700 dark:text-zinc-300' => $igSessionAuthenticated === null,
                    ])>
                        @if($igSessionAuthenticated === true)
                            Logged in
                            @if($igSessionUsername) as <span class="font-mono">{{ $igSessionUsername }}</span>@endif
                        @elseif($igSessionAuthenticated === false)
                            Session invalid — re-login required
                        @else
                            Status unknown — click Verify
                        @endif
                    </span>
                </div>
                @if($igSessionCheckedAt)
                    <flux:text class="text-xs opacity-60">
                        Last checked {{ \Carbon\Carbon::parse($igSessionCheckedAt)->diffForHumans() }}
                    </flux:text>
                @endif
            </div>

            <div class="text-xs opacity-70">
                Profile dir: <code>storage/app/instagram-puppeteer/</code>
                @if($igProfileExists === false)
                    <span class="text-amber-700 dark:text-amber-300">(not created yet)</span>
                @endif
            </div>

            <div class="flex flex-wrap gap-2">
                <flux:button type="button" wire:click="verifyInstagramSession" variant="subtle" size="xs" icon="check-circle">
                    Verify Session
                </flux:button>
                <flux:button type="button" wire:click="startInstagramRemoteLogin" variant="primary" size="xs" icon="window">
                    Open Login Window
                </flux:button>
                @if($igProfileExists)
                    <flux:button type="button" wire:click="resetInstagramProfile"
                                 wire:confirm="Wipe the saved Instagram session and start a fresh login? This will delete cookies and force a clean re-auth."
                                 variant="danger" size="xs" icon="trash">
                        Reset Profile
                    </flux:button>
                @endif
                <div class="flex items-center gap-2" wire:loading wire:target="verifyInstagramSession,pollInstagramRemoteLogin">
                    <flux:icon.loading class="size-4 text-zinc-500" />
                    <span class="text-xs opacity-70">Checking…</span>
                </div>
            </div>

            @if($igRemoteError)
                <div class="rounded-lg bg-red-50 p-3 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200">
                    <strong>Remote login failed:</strong> {{ $igRemoteError }}
                </div>
            @endif

            @if($igRemoteOpen && $igRemoteUrl)
                <div class="rounded-lg border border-zinc-300 bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-900"
                     wire:poll.4s.visible="pollInstagramRemoteLogin"
                     wire:ignore.self>
                    <div class="flex items-center justify-between gap-2 border-b border-zinc-200 px-3 py-2 dark:border-zinc-700">
                        <div class="text-sm font-medium">
                            @if($igRemoteFinished)
                                <span class="text-zinc-700 dark:text-zinc-300">Login window closed — review log below.</span>
                            @else
                                <span class="text-zinc-700 dark:text-zinc-300">Complete the Instagram login in the embedded browser.</span>
                                <span class="text-xs opacity-60 ml-2">(2FA &amp; "Save info" prompts handled automatically)</span>
                            @endif
                        </div>
                        <flux:button type="button" wire:click="stopInstagramRemoteLogin" variant="danger" size="xs" icon="x-mark">
                            Close
                        </flux:button>
                    </div>

                    @unless($igRemoteFinished)
                        <div class="relative bg-black"
                             x-data="{
                                reported: false,
                                report(reason) {
                                    if (this.reported) return;
                                    this.reported = true;
                                    $wire.reportInstagramRemoteError(reason);
                                }
                             }"
                             style="aspect-ratio: 1366 / 900; max-height: 75vh;">
                            <iframe
                                wire:key="ig-vnc-iframe-{{ md5((string) $igRemoteUrl) }}"
                                x-on:load="(() => {
                                    try {
                                        const doc = $event.target.contentDocument;
                                        if (doc && /502|gateway|nginx/i.test(doc.title || '')) {
                                            report('iframe shows ' + (doc?.title || 'gateway error'));
                                        }
                                    } catch {}
                                })()"
                                x-on:error="report('iframe failed to load')"
                                src="{{ $igRemoteUrl }}"
                                class="absolute inset-0 h-full w-full border-0"
                                allow="clipboard-read; clipboard-write"></iframe>
                        </div>
                        <div class="px-3 py-2 text-xs opacity-70">
                            @if($igRemoteExpiresAt)
                                Session auto-stops {{ \Carbon\Carbon::createFromTimestamp($igRemoteExpiresAt)->diffForHumans() }}.
                            @endif
                            The script auto-detects login and closes when complete.
                        </div>
                    @endunless

                    <div class="border-t border-zinc-200 px-3 py-2 text-xs dark:border-zinc-700">
                        <div class="font-medium mb-1 opacity-70">Chromium log tail</div>
                        <pre class="max-h-40 overflow-auto whitespace-pre-wrap break-all text-2xs leading-tight opacity-80">{{ $igRemoteLogTail ?: '(no activity yet)' }}</pre>
                    </div>
                </div>
            @elseif($igRemoteOpen && $igRemoteFinished)
                <div class="rounded-lg border border-zinc-300 bg-zinc-50 p-3 text-xs dark:border-zinc-600 dark:bg-zinc-900"
                     wire:poll.10s.visible="pollInstagramRemoteLogin">
                    <div class="font-medium mb-1 opacity-70">Final Chromium log</div>
                    <pre class="max-h-40 overflow-auto whitespace-pre-wrap break-all text-2xs leading-tight opacity-80">{{ $igRemoteLogTail ?: '(empty)' }}</pre>
                </div>
            @endif
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

            {{-- Every claim in the previous version of this box was false by the
                 time it was read: it credited the browser extension for keeping
                 the session fresh (yelp:keep-session does that now, server-side
                 every 6h), said Yelp's bot protection "never sees a scripted
                 login" (it sees one on every keep-alive — hence checkSession()'s
                 dedicated `blocked` branch), and told the operator reviews need
                 no login and come from the public page (www.yelp.com answers 403
                 to this server, so the scrape only works through the same proxy
                 and captcha solver the login uses). --}}
            <div class="rounded-lg bg-sky-50 p-3 text-sm text-sky-800 dark:bg-sky-900/20 dark:text-sky-200">
                <strong>How this works:</strong> the server holds a logged-in Yelp session and revisits the dashboard
                every 6 hours to stop it expiring. That runs entirely here &mdash; no browser to leave open, no
                extension, nothing to paste &mdash; and it is what keeps photo uploads working.
                <br><br>
                If the session ever does expire, the server attempts to sign in again with the email and password
                below and re-uploads any photos that were waiting. <strong>Yelp does not always accept a scripted
                sign-in</strong>, so if that attempt is refused you will get an email and can finish it yourself with
                <strong>Manual sign-in</strong> &mdash; the status below always shows what the last attempt actually did.
            </div>

            @if($yelpSessionDead)
                <div class="mt-4 rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-900 dark:border-red-700/60 dark:bg-red-900/20 dark:text-red-200">
                    <div class="flex items-start gap-3">
                        <flux:icon.exclamation-triangle class="size-5 shrink-0 text-red-600 dark:text-red-400" />
                        <div class="space-y-1">
                            <p class="font-semibold">Yelp session expired &mdash; re-login required</p>
                            <p>
                                The persistent Chromium profile is no longer authenticated, so background photo
                                uploads are paused. The server is retrying by itself; use <strong>Manual sign-in</strong> below only to re-establish
                                the session in the embedded viewer (DataDome blocks unattended scripted logins, so
                                this step must be interactive).
                            </p>
                            <p>
                                Photos that failed to upload while the session was down will
                                <strong>re-upload automatically</strong> once you're logged back in &mdash;
                                nothing else to run.
                            </p>
                            @if($yelpSessionDeadAt)
                                <p class="text-xs opacity-75">Detected at {{ $yelpSessionDeadAt }}.</p>
                            @endif
                            @if($yelpSessionDeadNote)
                                <p class="text-xs opacity-75">Last error: {{ $yelpSessionDeadNote }}</p>
                            @endif
                        </div>
                    </div>
                </div>
            @endif

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
                            <span class="ml-2 text-xs text-green-600 dark:text-green-400">
                                (saved — leave blank to keep, len {{ $yelpPasswordLen }}, fp <code>{{ $yelpPasswordFingerprint }}</code>)
                            </span>
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
                            Manual sign-in
                        </flux:button>
                    </div>
                </div>
                {{-- Two lines, not six. "Email saved" / "Password saved"
                     duplicated the form's own saved badge; "Node binary" and
                     "User data dir" are install-time config that has never been
                     false on a working box — they earn a red banner if they ever
                     break, not permanent green dots. What survives is what an
                     operator can act on: is the session good, and is anything
                     stuck. --}}
                <div class="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                    <div class="flex items-center gap-2" wire:target="checkYelpSession,pollYelpRemoteLogin">
                        <span wire:loading.remove wire:target="checkYelpSession" class="size-2 rounded-full {{ $yelpAuthenticated === true ? 'bg-green-500' : ($yelpAuthenticated === false ? 'bg-red-500' : 'bg-amber-500') }}"></span>
                        <svg wire:loading wire:target="checkYelpSession" class="size-3 animate-spin text-zinc-500" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                        </svg>
                        <span class="text-zinc-700 dark:text-zinc-300">
                            <span wire:loading.remove wire:target="checkYelpSession">
                                {{-- Amber, not red, for the unknown case: a DataDome-blocked
                                     probe tells us nothing and is not a fault to chase. --}}
                                {{ $yelpAuthenticated === true ? 'Connected to Yelp' : ($yelpAuthenticated === false ? 'Not connected — the server is retrying' : 'Connection unknown — last check was blocked') }}
                            </span>
                            <span wire:loading wire:target="checkYelpSession">Checking…</span>
                        </span>
                    </div>
                    @php
                        $yelpPendingCount = \App\Models\ProjectImage::query()
                            ->whereHas('project', fn ($q) => $q->where('is_published', true))
                            ->notUploadedTo('yelp_biz')
                            ->count();
                    @endphp
                    <div class="flex items-center gap-2">
                        <span class="size-2 rounded-full {{ $yelpPendingCount === 0 ? 'bg-green-500' : 'bg-amber-500' }}"></span>
                        <span class="text-zinc-700 dark:text-zinc-300">
                            {{ $yelpPendingCount === 0 ? 'All photos uploaded' : $yelpPendingCount . ' ' . str('photo')->plural($yelpPendingCount) . ' waiting to upload' }}
                        </span>
                    </div>
                </div>

                {{-- Install-time prerequisites: silent when fine, loud when not. --}}
                @php $cfg = config('services.yelp.business'); @endphp
                @if(empty($cfg['node_binary']) || empty($cfg['user_data_dir']))
                    <p class="mt-2 text-xs text-red-600 dark:text-red-400">
                        Server misconfigured: {{ empty($cfg['node_binary']) ? 'node binary' : 'user data dir' }} is not set. Yelp automation cannot run.
                    </p>
                @endif

                <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">
                    Use <strong>Manual sign-in</strong> only if automatic sign-in reports it needs a verification code (e.g. after a password change
                    or a long idle period). The embedded browser closes itself once login succeeds.
                </p>
            </div>

            {{-- Was: the browser-extension bridge — install instructions, pairing
                 dots, token reveal/copy and a "stay logged in to biz.yelp.com as
                 you normally would" note. All of it described a setup step the
                 operator no longer has to perform, and the code never required
                 it (extension_paired_at is a display flag). The ingest endpoint
                 and its token controls still exist, folded into Manual overrides
                 below for the rare case where handing over a desktop session is
                 faster than waiting for automatic sign-in. --}}
            <div class="border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <div class="flex items-center justify-between mb-2">
                    <h4 class="text-sm font-semibold text-zinc-900 dark:text-white">Automatic sign-in</h4>
                    @if($yelpCanAutoLogin)
                        <flux:button type="button" wire:click="yelpLoginNow" variant="subtle" size="xs" wire:loading.attr="disabled">
                            Sign in now
                        </flux:button>
                    @endif
                </div>

                @if($yelpCanAutoLogin)
                    {{-- "Armed", not "works". Verified on production 2026-08-11:
                         the attempt runs correctly end to end — Xvfb display,
                         Chromium, DataDome bypassed, credentials submitted — and
                         Yelp still held it on the login page for the full 600s
                         ("still_on_login"). Promising self-repair here would be
                         the same class of false claim this panel just had removed.
                         The last-attempt line below is the honest signal. --}}
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                        <span class="inline-block size-2 rounded-full bg-green-500 align-middle"></span>
                        <span class="align-middle">
                            Armed &mdash; a dead session triggers an automatic attempt. Yelp may still refuse a
                            scripted sign-in, in which case you will be emailed to finish it manually.
                        </span>
                    </p>
                @else
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                        <span class="inline-block size-2 rounded-full bg-red-500 align-middle"></span>
                        <span class="align-middle">
                            Not armed &mdash; if the session dies, uploads stay paused until you act.
                            Yelp ties a solved captcha to the IP that requested it, so recovery needs
                            <strong>both</strong> a captcha key and a proxy (set them under Manual overrides).
                        </span>
                    </p>
                @endif

                {{-- The single most useful line on the page when something is
                     wrong: what the last unattended attempt actually did. --}}
                @if($yelpAutoLoginResult)
                    <p class="mt-2 text-xs {{ ($yelpAutoLoginResult['ok'] ?? false) ? 'text-green-700 dark:text-green-400' : 'text-amber-700 dark:text-amber-400' }}">
                        Last attempt{{ $yelpAutoLoginAt ? ' ' . \Carbon\Carbon::parse($yelpAutoLoginAt)->diffForHumans() : '' }}:
                        {{ ($yelpAutoLoginResult['ok'] ?? false) ? 'signed in' : ($yelpAutoLoginResult['code'] ?? 'failed') }}.
                        {{ $yelpAutoLoginResult['hint'] ?? '' }}
                    </p>
                @endif
            </div>

            {{-- Reviews: a genuinely separate pipeline. It reads the PUBLIC
                 yelp.com/biz page and never touches the login session or the
                 cookie jar, so a dead Yelp session does not stop reviews —
                 worth stating, because the two were easy to conflate. --}}
            <div class="border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <h4 class="text-sm font-semibold text-zinc-900 dark:text-white mb-2">Reviews</h4>
                @php
                    // Provenance lives on the reviewUrls relation (platform =
                    // 'yelp'), not a column on testimonials.
                    $yelpReviewQuery = \App\Models\Testimonial::query()
                        ->whereHas('reviewUrls', fn ($q) => $q->where('platform', 'yelp'));
                    $yelpReviewCount = (clone $yelpReviewQuery)->count();
                    $yelpLatestReview = (clone $yelpReviewQuery)->max('review_date');
                @endphp
                <div class="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                    <div class="flex items-center gap-2">
                        <span class="size-2 rounded-full {{ $yelpReviewCount > 0 ? 'bg-green-500' : 'bg-zinc-400' }}"></span>
                        <span class="text-zinc-700 dark:text-zinc-300">
                            {{ $yelpReviewCount }} Yelp {{ Str::plural('review', $yelpReviewCount) }} imported
                        </span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="size-2 rounded-full bg-sky-500"></span>
                        <span class="text-zinc-700 dark:text-zinc-300">
                            Syncs weekly
                            @if($yelpLatestReview)
                                <span class="text-zinc-500">(newest {{ \Carbon\Carbon::parse($yelpLatestReview)->diffForHumans() }})</span>
                            @endif
                        </span>
                    </div>
                </div>
                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                    Read from the public Yelp business page &mdash; no login required, so this keeps working even if
                    the session above expires. Run manually with
                    <code>php artisan testimonials:sync-yelp-reviews --only-new</code>.
                </p>
            </div>

            {{-- Fallbacks. Neither is part of the normal flow: the extension keeps
                 the session fresh on its own. Folded away so the page shows one
                 working path instead of three competing ones. --}}
            <details class="border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <summary class="cursor-pointer select-none text-sm font-semibold text-zinc-900 dark:text-white">
                    Manual overrides <span class="font-normal text-zinc-500">(rarely needed &mdash; only if automatic sign-in fails)</span>
                </summary>
                <div class="mt-3 space-y-4">
            {{-- Backend login: credentials only, nothing else to install. --}}
            <div class="border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <div class="flex items-center justify-between mb-2">
                    <h4 class="text-sm font-semibold text-zinc-900 dark:text-white">Captcha solver &amp; proxy</h4>
                    <flux:button type="button" wire:click="yelpLoginNow" variant="primary" size="sm" icon="arrow-right-end-on-rectangle">
                        <span wire:loading.remove wire:target="yelpLoginNow">Log in now</span>
                        <span wire:loading wire:target="yelpLoginNow">Starting…</span>
                    </flux:button>
                </div>

                <p class="text-xs text-zinc-500 dark:text-zinc-400 mb-3">
                    The server logs in to Yelp itself with the email and password above — no cookies, no extension,
                    nothing to install. It re-logs in automatically whenever the session expires.
                </p>

                <div class="grid grid-cols-1 gap-2 text-sm sm:grid-cols-3 mb-3">
                    <div class="flex items-center gap-2">
                        <span class="size-2 rounded-full {{ $yelpHasPassword ? 'bg-green-500' : 'bg-red-500' }}"></span>
                        <span class="text-zinc-700 dark:text-zinc-300">Credentials</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="size-2 rounded-full {{ $yelpHasCaptchaKey ? 'bg-green-500' : 'bg-red-500' }}"></span>
                        <span class="text-zinc-700 dark:text-zinc-300">Captcha solver</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="size-2 rounded-full {{ $yelpHasProxy ? 'bg-green-500' : 'bg-red-500' }}"></span>
                        <span class="text-zinc-700 dark:text-zinc-300">Proxy</span>
                    </div>
                </div>

                @unless($yelpCanAutoLogin)
                    <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 mb-3 text-xs dark:border-amber-700/60 dark:bg-amber-900/20">
                        <p class="font-semibold text-amber-900 dark:text-amber-200 mb-1">Both fields below are required.</p>
                        <p class="text-amber-900/90 dark:text-amber-200/90">
                            Yelp is protected by DataDome, which ties a solved captcha to the IP address that requested it.
                            The solver therefore cannot help unless the browser goes out through the same proxy —
                            a captcha key on its own will always fail.
                        </p>
                    </div>
                @endunless

                @if($yelpAutoLoginResult)
                    <div class="rounded-lg p-3 mb-3 text-xs {{ ($yelpAutoLoginResult['ok'] ?? false) ? 'bg-green-50 text-green-900 dark:bg-green-900/20 dark:text-green-200' : 'bg-red-50 text-red-900 dark:bg-red-900/20 dark:text-red-200' }}">
                        <p class="font-semibold">
                            Last attempt: {{ ($yelpAutoLoginResult['ok'] ?? false) ? 'success' : ($yelpAutoLoginResult['code'] ?? 'failed') }}
                            @if($yelpAutoLoginAt)
                                <span class="opacity-75">({{ \Carbon\Carbon::parse($yelpAutoLoginAt)->diffForHumans() }})</span>
                            @endif
                        </p>
                        @if(!empty($yelpAutoLoginResult['hint']))
                            <p class="mt-0.5">{{ $yelpAutoLoginResult['hint'] }}</p>
                        @endif
                    </div>
                @endif

                <form wire:submit="saveYelpAutoLogin" class="space-y-3">
                    <flux:field>
                        <flux:label>2captcha API key</flux:label>
                        <flux:description>
                            @if($yelpHasCaptchaKey) Saved — leave blank to keep. @endif
                            From <a href="https://2captcha.com/enterpage" target="_blank" rel="noopener" class="underline">2captcha.com</a> (~$3 per 1,000 solves).
                        </flux:description>
                        <flux:input wire:model="yelpCaptchaKey" type="password" placeholder="{{ $yelpHasCaptchaKey ? '••••••••' : 'Paste your 2captcha key' }}" autocomplete="off" />
                        <flux:error name="yelpCaptchaKey" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Proxy URL</flux:label>
                        <flux:description>
                            @if($yelpHasProxy) Saved — leave blank to keep. @endif
                            A residential or ISP proxy, e.g. <code>http://user:pass@host:port</code>.
                        </flux:description>
                        <flux:input wire:model="yelpProxy" type="password" placeholder="{{ $yelpHasProxy ? '••••••••' : 'http://user:pass@host:port' }}" autocomplete="off" />
                        <flux:error name="yelpProxy" />
                    </flux:field>

                    <flux:button type="submit" variant="primary" size="sm">Save</flux:button>
                </form>
            </div>

            {{-- Cookie injection (paste from Cookie-Editor extension) --}}
            <div class="border-t border-zinc-200 pt-4 dark:border-zinc-700">
                <div class="flex items-center justify-between mb-2">
                    <h4 class="text-sm font-semibold text-zinc-900 dark:text-white">Cookie injection (manual fallback)</h4>
                    @if($yelpCookieFileCount !== null)
                        <flux:button type="button" wire:click="clearYelpCookieFile" wire:confirm="Delete the stored Yelp cookie file? Uploads will fail until new cookies are imported." variant="subtle" size="xs">
                            Clear stored cookies
                        </flux:button>
                    @endif
                </div>
                <p class="text-xs text-zinc-500 dark:text-zinc-400 mb-3">
                    Paste a JSON export from <a href="https://chromewebstore.google.com/detail/cookie-editor/hlkenndednhfkekhgcdicdfddnkalmdm" target="_blank" rel="noopener" class="underline">Cookie-Editor</a>
                    after logging into Yelp in your desktop browser. Export both <code>biz.yelp.com</code> and <code>yelp.com</code>
                    domains and paste each in turn (merge mode keeps both). When valid cookies are present, uploads bypass
                    the headless login flow entirely (DataDome-friendly).
                </p>

                @if($yelpCookieFileCount !== null)
                    <div class="rounded-lg bg-zinc-50 p-3 mb-3 text-xs dark:bg-zinc-800/50">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="size-2 rounded-full bg-green-500"></span>
                            <span class="font-semibold text-zinc-900 dark:text-white">
                                {{ $yelpCookieFileCount }} cookies stored
                            </span>
                            @if($yelpCookieFileUpdatedAt)
                                <span class="text-zinc-500">
                                    (updated {{ \Carbon\Carbon::parse($yelpCookieFileUpdatedAt)->diffForHumans() }})
                                </span>
                            @endif
                        </div>
                        @if($yelpCookieExpiredCount > 0)
                            <div class="font-semibold text-red-700 dark:text-red-400">
                                {{ $yelpCookieExpiredCount }} stored {{ Str::plural('cookie', $yelpCookieExpiredCount) }} already expired —
                                send a fresh session from the browser extension.
                            </div>
                        @endif
                        @if($yelpCookieBseExpiresAt)
                            <div class="text-zinc-600 dark:text-zinc-400">
                                <code>bse</code> expires {{ \Carbon\Carbon::parse($yelpCookieBseExpiresAt)->diffForHumans() }}
                            </div>
                        @endif
                        @if($yelpCookieDataDomeExpiresAt)
                            <div class="text-zinc-600 dark:text-zinc-400">
                                <code>datadome</code> expires {{ \Carbon\Carbon::parse($yelpCookieDataDomeExpiresAt)->diffForHumans() }}
                            </div>
                        @endif
                    </div>
                @else
                    <div class="rounded-lg bg-amber-50 p-3 mb-3 text-xs text-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
                        No cookie file stored yet. Uploads will fall back to the headless login flow (often blocked by DataDome).
                    </div>
                @endif

                <flux:textarea
                    wire:model="yelpCookiePaste"
                    placeholder='Paste Cookie-Editor JSON here, e.g. [{"domain":".yelp.com","name":"bse","value":"...","path":"/", ...}, ...]'
                    rows="6"
                    class="font-mono text-xs"
                />
                <div class="mt-2 flex items-center justify-between gap-3">
                    <label class="flex items-center gap-2 text-xs text-zinc-600 dark:text-zinc-400">
                        <input type="checkbox" wire:model="yelpCookieReplace" class="rounded border-zinc-300 dark:border-zinc-700">
                        Replace existing (default merges)
                    </label>
                    <flux:button type="button" wire:click="importYelpCookiesFromPaste" variant="primary" size="sm" icon="key">
                        <span wire:loading.remove wire:target="importYelpCookiesFromPaste">Import cookies</span>
                        <span wire:loading wire:target="importYelpCookiesFromPaste">Importing…</span>
                    </flux:button>
                </div>
            </div>
                </div>
            </details>

            {{-- Remote-login viewer (Xvfb + noVNC) --}}
            @if($yelpRemoteError)
                <div class="rounded-lg bg-red-50 p-3 text-sm text-red-800 dark:bg-red-900/20 dark:text-red-200">
                    <strong>Remote login failed:</strong> {{ $yelpRemoteError }}
                </div>
            @endif

            @if($yelpRemoteOpen && $yelpRemoteUrl)
                <div
                    class="border-t border-zinc-200 pt-4 dark:border-zinc-700"
                    wire:poll.4s.visible="pollYelpRemoteLogin"
                >
                    <div class="flex items-center justify-between mb-2">
                        <h4 class="text-sm font-semibold text-zinc-900 dark:text-white">
                            Remote login viewer
                            @if($yelpRemoteFinished)
                                <span class="ml-2 inline-block rounded bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-800 dark:bg-green-900/30 dark:text-green-200">
                                    Session ended — log preserved
                                </span>
                            @else
                                <span class="ml-2 text-xs font-normal text-zinc-500">
                                    Complete login / captcha / 2FA in the embedded browser below.
                                </span>
                            @endif
                        </h4>
                        <flux:button type="button" wire:click="stopYelpRemoteLogin" variant="danger" size="xs" icon="x-mark">
                            Close viewer
                        </flux:button>
                    </div>
                    {{-- "Reset browser profile" removed. It rm -rf'd
                         services.yelp.business.user_data_dir — the exact profile
                         the 6-hourly keep-alive maintains and repairs for free —
                         so the best case after a wipe was burning a paid captcha
                         solve to rebuild what was already working. It was also
                         the most destructive control on the page, one misread
                         tooltip away from a self-inflicted outage. Still
                         available deliberately: `php artisan yelp:reset-profile`. --}}
                    @unless($yelpRemoteFinished)
                        <div
                            class="overflow-hidden rounded-lg border border-zinc-300 bg-black dark:border-zinc-600"
                            style="aspect-ratio: 16/10;"
                            wire:ignore
                            wire:key="yelp-vnc-iframe-{{ md5((string) $yelpRemoteUrl) }}"
                        >
                            <iframe
                                x-data="{
                                    checked: false,
                                    onLoad(e) {
                                        // Try to detect 502/Cloudflare error pages by checking the document title.
                                        try {
                                            const doc = e.target.contentDocument;
                                            const title = (doc?.title || '').toLowerCase();
                                            if (title.includes('bad gateway') || title.includes('502') || title.includes('cloudflare')) {
                                                $wire.reportYelpRemoteError('iframe shows ' + (doc?.title || 'gateway error'));
                                            }
                                        } catch (err) {
                                            // Cross-origin — ignore.
                                        }
                                    },
                                    onError() {
                                        $wire.reportYelpRemoteError('iframe failed to load');
                                    }
                                }"
                                @load="onLoad($event)"
                                x-on:error="onError()"
                                src="{{ $yelpRemoteUrl }}"
                                class="w-full h-full"
                                allow="clipboard-read; clipboard-write"
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
                    @endunless

                    {{-- Live tail of the embedded Chromium's stderr so the operator can
                         see what the browser is doing (navigations, DataDome detections,
                         magic-link redirects, etc.) without SSH. Stays visible even
                         after the session ends so the operator can review the final
                         outcome line and cookie summary. --}}
                    <details class="mt-3" open>
                        <summary class="cursor-pointer text-xs font-semibold text-zinc-700 dark:text-zinc-300">
                            Browser activity log (live)
                        </summary>
                        <pre class="mt-2 max-h-64 overflow-auto rounded bg-zinc-900 p-2 text-2xs leading-relaxed text-zinc-100 whitespace-pre-wrap">{{ $yelpRemoteLogTail ?: '(no activity yet)' }}</pre>
                    </details>
                </div>
            @elseif($yelpRemoteOpen && $yelpRemoteFinished)
                {{-- Script exited successfully (or with an error). The noVNC iframe is
                     gone but we keep the activity log visible so the operator can
                     review what happened. Polls one more time to refresh tail. --}}
                <div
                    class="border-t border-zinc-200 pt-4 dark:border-zinc-700"
                    wire:poll.10s.visible="pollYelpRemoteLogin"
                >
                    <div class="flex items-center justify-between mb-2">
                        <h4 class="text-sm font-semibold text-zinc-900 dark:text-white">
                            Remote login viewer
                            <span class="ml-2 inline-block rounded bg-green-100 px-2 py-0.5 text-xs font-semibold text-green-800 dark:bg-green-900/30 dark:text-green-200">
                                Session ended — log preserved
                            </span>
                        </h4>
                        <flux:button type="button" wire:click="stopYelpRemoteLogin" variant="ghost" size="xs" icon="x-mark">
                            Close viewer
                        </flux:button>
                    </div>
                    <details class="mt-3" open>
                        <summary class="cursor-pointer text-xs font-semibold text-zinc-700 dark:text-zinc-300">
                            Browser activity log (final)
                        </summary>
                        <pre class="mt-2 max-h-96 overflow-auto rounded bg-zinc-900 p-2 text-2xs leading-relaxed text-zinc-100 whitespace-pre-wrap">{{ $yelpRemoteLogTail ?: '(no activity captured)' }}</pre>
                    </details>
                </div>
            @endif
        </div>
    </section>
</div>
