<div class="space-y-6">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">Google Business Profile</flux:heading>
            <flux:subheading>Manage your GBP OAuth connection. Tokens are stored encrypted in the database and refreshed automatically.</flux:subheading>
        </div>
        <flux:button wire:click="refreshStatus" icon="arrow-path" variant="subtle" size="sm">
            Refresh
        </flux:button>
    </div>

    {{-- Flash messages --}}
    @if (session('gbp-success'))
        <div class="rounded-lg bg-green-50 p-4 text-sm text-green-800 dark:bg-green-900/20 dark:text-green-200">
            {{ session('gbp-success') }}
        </div>
    @endif
    @if (session('gbp-error'))
        <div class="rounded-lg bg-red-50 p-4 text-sm text-red-800 dark:bg-red-900/20 dark:text-red-200">
            {{ session('gbp-error') }}
        </div>
    @endif

    {{-- Connection Status Card --}}
    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
        <div class="flex items-start gap-4">
            {{-- Status icon --}}
            <div @class([
                'flex size-12 shrink-0 items-center justify-center rounded-full',
                'bg-green-100 dark:bg-green-900/30' => $isConnected && $healthStatus === 'ok',
                'bg-red-100 dark:bg-red-900/30' => $needsReauth || $healthStatus === 'error',
                'bg-zinc-100 dark:bg-zinc-700' => !$isConnected && !$needsReauth,
            ])>
                @if($isConnected && $healthStatus === 'ok')
                    <svg class="size-6 text-green-600 dark:text-green-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" />
                    </svg>
                @elseif($needsReauth || $healthStatus === 'error')
                    <svg class="size-6 text-red-600 dark:text-red-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                    </svg>
                @else
                    <svg class="size-6 text-zinc-400 dark:text-zinc-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 0 1 1.242 7.244l-4.5 4.5a4.5 4.5 0 0 1-6.364-6.364l1.757-1.757m13.35-.622 1.757-1.757a4.5 4.5 0 0 0-6.364-6.364l-4.5 4.5a4.5 4.5 0 0 0 1.242 7.244" />
                    </svg>
                @endif
            </div>

            {{-- Details --}}
            <div class="flex-1 space-y-1">
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">
                    @if($isConnected && $healthStatus === 'ok')
                        Connected
                    @elseif($needsReauth)
                        Re-authorization Required
                    @elseif($healthStatus === 'error')
                        Connection Error
                    @else
                        Not Connected
                    @endif
                </h3>

                @if($connectedEmail)
                    <p class="text-sm text-zinc-600 dark:text-zinc-400">
                        Authorized as <span class="font-medium">{{ $connectedEmail }}</span>
                        @if($connectedAt) &middot; {{ $connectedAt }} @endif
                    </p>
                @endif

                @if($healthError)
                    <p class="text-sm text-red-600 dark:text-red-400">{{ $healthError }}</p>
                @endif

                @if($needsReauth)
                    <p class="text-sm text-amber-700 dark:text-amber-400">
                        The refresh token has expired or been revoked. Click "Reconnect" to re-authorize.
                    </p>
                @endif
            </div>

            {{-- Actions --}}
            <div class="flex shrink-0 gap-2">
                @if($isConnected && !$needsReauth && $healthStatus === 'ok')
                    <flux:button wire:click="disconnect" wire:confirm="Disconnect GBP? Automated uploads and posts will stop until you reconnect." variant="danger" size="sm">
                        Disconnect
                    </flux:button>
                @endif

                <flux:button
                    wire:click="connect"
                    variant="{{ $needsReauth || $healthStatus === 'error' ? 'primary' : ($isConnected ? 'subtle' : 'primary') }}"
                    size="sm"
                    icon="link"
                >
                    {{ $isConnected ? 'Reconnect' : 'Connect Google Account' }}
                </flux:button>
            </div>
        </div>
    </div>

    {{-- Info / Tips --}}
    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
        <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">How it works</h3>
        <ul class="mt-3 space-y-2 text-sm text-zinc-600 dark:text-zinc-400">
            <li class="flex gap-2">
                <span class="mt-0.5 text-sky-500">1.</span>
                Click <strong>Connect</strong> to sign in with the Google account that owns the Business Profile.
            </li>
            <li class="flex gap-2">
                <span class="mt-0.5 text-sky-500">2.</span>
                Google will ask you to grant access. Tokens are encrypted and stored in the database.
            </li>
            <li class="flex gap-2">
                <span class="mt-0.5 text-sky-500">3.</span>
                Access tokens refresh automatically. If the refresh token ever expires, come back here and click <strong>Reconnect</strong>.
            </li>
        </ul>

        <div class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-900/20 dark:text-amber-200">
            <strong>Tip:</strong> In the <a href="https://console.cloud.google.com/apis/credentials/consent" target="_blank" class="underline">Google Cloud Console</a>,
            make sure the OAuth consent screen is set to <strong>"In production"</strong> (not "Testing"). In Testing mode, refresh tokens expire after 7 days.
        </div>
    </div>

    {{-- Environment config --}}
    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
        <h3 class="text-sm font-semibold text-zinc-900 dark:text-white mb-3">Configuration Status</h3>
        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 text-sm">
            @php
                $config = config('services.google.business_profile');
                $checks = [
                    'GOOGLE_BUSINESS_PROFILE_ENABLED' => !empty($config['enabled']),
                    'CLIENT_ID' => !empty($config['client_id']),
                    'CLIENT_SECRET' => !empty($config['client_secret']),
                    'ACCOUNT_ID' => !empty($config['account_id']),
                    'LOCATION_ID' => !empty($config['location_id']),
                    'Refresh Token (DB or .env)' => app(\App\Services\GoogleBusinessProfileService::class)->hasRefreshToken(),
                ];
            @endphp
            @foreach($checks as $label => $ok)
                <div class="flex items-center gap-2">
                    @if($ok)
                        <span class="size-2 rounded-full bg-green-500"></span>
                    @else
                        <span class="size-2 rounded-full bg-red-500"></span>
                    @endif
                    <span class="text-zinc-700 dark:text-zinc-300">{{ $label }}</span>
                </div>
            @endforeach
        </div>
    </div>
</div>
