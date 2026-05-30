<div class="space-y-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Social Media</flux:heading>

        @if($isConfigured)
            <flux:button wire:click="postNow" icon="paper-airplane" variant="primary">
                Post Now
            </flux:button>
        @endif
    </div>

    {{-- Flash messages --}}
    @if(session('success'))
        <flux:card class="border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-950">
            <p class="text-sm text-green-700 dark:text-green-300">{{ session('success') }}</p>
        </flux:card>
    @endif
    @if(session('error'))
        <flux:card class="border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-950">
            <p class="text-sm text-red-700 dark:text-red-300">{{ session('error') }}</p>
        </flux:card>
    @endif
    @if(session('info'))
        <flux:card class="border-blue-200 bg-blue-50 dark:border-blue-800 dark:bg-blue-950">
            <p class="text-sm text-blue-700 dark:text-blue-300">{{ session('info') }}</p>
        </flux:card>
    @endif

    {{-- Stats Cards --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-5">
        <flux:card class="text-center">
            <p class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $totalEligible }}</p>
            <p class="text-sm text-zinc-500">Total Images</p>
        </flux:card>
        <flux:card class="text-center">
            <p class="text-2xl font-bold text-pink-600">{{ $remainingInstagram }}</p>
            <p class="text-sm text-zinc-500">IG Remaining</p>
            <p class="mt-1 text-xs text-zinc-400">{{ $postedInstagram }} {{ \Illuminate\Support\Str::plural('post', $postedInstagram) }} posted</p>
        </flux:card>
        <flux:card class="text-center">
            <p class="text-2xl font-bold text-blue-600">{{ $remainingFacebook }}</p>
            <p class="text-sm text-zinc-500">FB Remaining</p>
        </flux:card>
        <flux:card class="text-center">
            <p class="text-2xl font-bold text-amber-600">{{ $remainingGbp }}</p>
            <p class="text-sm text-zinc-500">GBP Remaining</p>
            <p class="mt-1 text-xs text-zinc-400">{{ $postedGbp }} {{ \Illuminate\Support\Str::plural('post', $postedGbp) }} posted</p>
            <p class="mt-1 text-xs text-zinc-400">{{ $uploadedGbp }} {{ \Illuminate\Support\Str::plural('image', $uploadedGbp) }} uploaded</p>
        </flux:card>
        <flux:card class="text-center">
            <p class="text-2xl font-bold text-red-600">{{ $remainingYelp }}</p>
            <p class="text-sm text-zinc-500">Yelp Remaining</p>
            <p class="mt-1 text-xs text-zinc-400">{{ $uploadedYelp }} {{ \Illuminate\Support\Str::plural('image', $uploadedYelp) }} uploaded</p>
        </flux:card>
    </div>

    <flux:card>
        <div class="flex items-center justify-between gap-3">
            <div>
                <flux:heading size="lg">Social Profile URLs</flux:heading>
                <p class="text-sm text-zinc-500">Used in footer/schema and social links across the site.</p>
            </div>
            <flux:button wire:click="saveSocialUrls" variant="primary" size="sm" icon="check">
                Save URLs
            </flux:button>
        </div>

        @php
            $socialUrlFields = [
                'instagram' => ['label' => 'Instagram', 'placeholder' => 'https://www.instagram.com/gs.construction.co/'],
                'google' => ['label' => 'Google', 'placeholder' => 'https://www.google.com/search?q=GS+Construction+chicago'],
                'facebook' => ['label' => 'Facebook', 'placeholder' => 'https://www.facebook.com/gs.construction.chi'],
                'yelp' => ['label' => 'Yelp', 'placeholder' => 'https://www.yelp.com/biz/gs-construction-chicago-2'],
                'houzz' => ['label' => 'Houzz', 'placeholder' => 'https://www.houzz.com/professionals/kitchen-and-bath-remodelers/gs-construction-pfvwus-pf~1225706575'],
                'angi' => ['label' => 'Angi', 'placeholder' => 'https://www.angi.com/companylist/us/il/chicagoland/gs-construction-and-remodeling-reviews-11400361.htm'],
            ];
        @endphp

        <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
            @foreach($socialUrlFields as $key => $field)
                <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                    <div class="mb-2 flex items-center gap-2">
                        <img src="{{ asset(config('socials.' . $key . '.icon')) }}" alt="{{ $field['label'] }} logo" class="size-5 rounded-sm object-contain" />
                        <span class="text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ $field['label'] }}</span>
                    </div>
                    <flux:input
                        wire:model.defer="socialUrls.{{ $key }}"
                        type="url"
                        placeholder="{{ $field['placeholder'] }}"
                    />
                </div>
            @endforeach
        </div>

        @error('socialUrls.instagram') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
        @error('socialUrls.google') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
        @error('socialUrls.facebook') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
        @error('socialUrls.yelp') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
        @error('socialUrls.houzz') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
        @error('socialUrls.angi') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror
    </flux:card>

    @if(!$isConfigured)
        <flux:card class="border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950">
            <div class="space-y-2">
                <flux:heading size="lg">Setup Required</flux:heading>
                <p class="text-sm text-zinc-600 dark:text-zinc-400">
                    Meta isn't connected yet. Set the app credentials in <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">.env</code>:
                </p>
                <pre class="rounded bg-zinc-100 p-3 text-xs dark:bg-zinc-800">META_APP_ID=your_app_id
META_APP_SECRET=your_app_secret</pre>
                <p class="text-sm text-zinc-600 dark:text-zinc-400">
                    Then connect your Facebook Page at <a href="{{ route('admin.platforms') }}" class="underline">/admin/platforms</a>.
                </p>
            </div>
        </flux:card>
    @endif

    {{-- Filters --}}
    <div class="flex gap-3">
        <flux:select wire:model.live="platformFilter" class="w-40">
            <option value="">All Platforms</option>
            <option value="instagram">Instagram</option>
            <option value="facebook">Facebook</option>
            <option value="google_business">Google Business</option>
            <option value="yelp">Yelp</option>
        </flux:select>

        <flux:select wire:model.live="statusFilter" class="w-40">
            <option value="">All Statuses</option>
            <option value="published">Published</option>
            <option value="pending">Pending</option>
            <option value="failed">Failed</option>
        </flux:select>
    </div>

    {{-- Remaining images --}}
    <div id="remaining-images" x-data="{ open: false }" class="space-y-3">
        <button
            type="button"
            @click="open = !open"
            class="flex w-full items-center justify-between rounded-xl border border-zinc-200 bg-white px-4 py-3 text-left shadow-sm transition hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:bg-zinc-850"
        >
            <div>
                <flux:heading size="lg">Remaining Images</flux:heading>
                <p class="text-sm text-zinc-500">Grouped by platform, as a table like before.</p>
            </div>
            <svg class="size-4 shrink-0 transition-transform duration-200" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
            </svg>
        </button>

        <div x-show="open" x-collapse x-cloak class="space-y-3">
            @if($remainingImages->isEmpty())
                <flux:card>
                    <p class="py-8 text-center text-sm text-zinc-500">No remaining images for the selected filters.</p>
                </flux:card>
            @else
                <flux:card>
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Platform</flux:table.column>
                            <flux:table.column>Image</flux:table.column>
                            <flux:table.column>Project</flux:table.column>
                            <flux:table.column>Caption</flux:table.column>
                            <flux:table.column>Status</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach($remainingImages as $item)
                                @php($image = $item['image'])
                                <flux:table.row>
                                    <flux:table.cell>
                                        @if($item['platform'] === 'instagram')
                                            <flux:badge color="pink" size="sm">Instagram</flux:badge>
                                        @elseif($item['platform'] === 'facebook')
                                            <flux:badge color="blue" size="sm">Facebook</flux:badge>
                                        @else
                                            <flux:badge color="amber" size="sm">Google Business</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <img
                                            src="{{ $image->getThumbnailUrl('thumb') }}"
                                            alt="{{ $image->alt_text ?? $image->caption ?? 'Project image' }}"
                                            class="h-10 w-10 rounded object-cover"
                                        />
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        {{ $image->project?->title ?? '—' }}
                                    </flux:table.cell>
                                    <flux:table.cell class="max-w-xs truncate">
                                        <span title="{{ $image->alt_text ?: $image->caption ?: 'No caption' }}">
                                            {{ Str::limit($image->alt_text ?: $image->caption ?: 'No caption', 60) }}
                                        </span>
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <flux:badge color="zinc" size="sm">Remaining</flux:badge>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </flux:card>
            @endif
        </div>
    </div>

    {{-- Uploaded posts (Instagram / Facebook / GBP) --}}
    @if($platformFilter !== 'yelp')
    <div id="uploaded-posts" x-data="{ open: false }" class="space-y-3">
        <button
            type="button"
            @click="open = !open"
            class="flex w-full items-center justify-between rounded-xl border border-zinc-200 bg-white px-4 py-3 text-left shadow-sm transition hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:bg-zinc-850"
        >
            <div>
                <flux:heading size="lg">Uploaded Posts</flux:heading>
                <p class="text-sm text-zinc-500">Published posts, including GBP images.</p>
            </div>
            <svg class="size-4 shrink-0 transition-transform duration-200" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
            </svg>
        </button>

        <div x-show="open" x-collapse x-cloak class="space-y-3">
            @if($uploadedPosts->isEmpty())
                <flux:card>
                    <p class="py-8 text-center text-sm text-zinc-500">
                        No posts yet. Posts will appear here once the scheduler starts or you click "Post Now".
                    </p>
                </flux:card>
            @else
                <flux:card>
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Platform</flux:table.column>
                            <flux:table.column>Image</flux:table.column>
                            <flux:table.column>Project</flux:table.column>
                            <flux:table.column>Caption</flux:table.column>
                            <flux:table.column>Status</flux:table.column>
                            <flux:table.column>Published</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach($uploadedPosts as $post)
                                <flux:table.row>
                                    <flux:table.cell>
                                        @if($post->platform === 'instagram')
                                            <flux:badge color="pink" size="sm">Instagram</flux:badge>
                                        @elseif($post->platform === 'facebook')
                                            <flux:badge color="blue" size="sm">Facebook</flux:badge>
                                        @else
                                            <flux:badge color="amber" size="sm">Google Business</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <img
                                            src="{{ $post->projectImage->getThumbnailUrl('thumb') }}"
                                            alt="{{ $post->projectImage->alt_text }}"
                                            class="h-10 w-10 rounded object-cover"
                                        />
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        {{ $post->projectImage?->project?->title ?? '—' }}
                                    </flux:table.cell>
                                    <flux:table.cell class="max-w-xs truncate">
                                        <span title="{{ $post->caption }}">
                                            {{ Str::limit($post->caption, 60) }}
                                        </span>
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        @if($post->status === 'published')
                                            <flux:badge color="green" size="sm">Published</flux:badge>
                                        @elseif($post->status === 'pending')
                                            <flux:badge color="amber" size="sm">Pending</flux:badge>
                                        @else
                                            <flux:badge color="red" size="sm" title="{{ $post->error_message }}">Failed</flux:badge>
                                        @endif
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        {{ $post->published_at ? $post->published_at->format('M j, Y g:i A') : 'Unpublished' }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </flux:card>

            @endif
        </div>
    </div>
    @endif

    {{-- GBP Uploaded Images --}}
    <div id="gbp-uploaded-images" x-data="{ open: false }" class="space-y-3">
        <button
            type="button"
            @click="open = !open"
            class="flex w-full items-center justify-between rounded-xl border border-zinc-200 bg-white px-4 py-3 text-left shadow-sm transition hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:bg-zinc-850"
        >
            <div>
                <flux:heading size="lg">GBP Uploaded Images</flux:heading>
                <p class="text-sm text-zinc-500">Real GBP media uploads tracked on project images.</p>
            </div>
            <svg class="size-4 shrink-0 transition-transform duration-200" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
            </svg>
        </button>

        <div x-show="open" x-collapse x-cloak class="space-y-3">
            @if($gbpImages->isEmpty())
                <flux:card>
                    <p class="py-8 text-center text-sm text-zinc-500">No GBP images uploaded yet.</p>
                </flux:card>
            @else
                <flux:card>
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Platform</flux:table.column>
                            <flux:table.column>Image</flux:table.column>
                            <flux:table.column>Project</flux:table.column>
                            <flux:table.column>Caption</flux:table.column>
                            <flux:table.column>Uploaded</flux:table.column>
                            <flux:table.column>Media Name</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach($gbpImages as $image)
                                <flux:table.row>
                                    <flux:table.cell>
                                        <flux:badge color="amber" size="sm">Google Business</flux:badge>
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <img
                                            src="{{ $image->getThumbnailUrl('thumb') }}"
                                            alt="{{ $image->alt_text ?? $image->caption ?? 'GBP image' }}"
                                            class="h-10 w-10 rounded object-cover"
                                        />
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        {{ $image->project?->title ?? '—' }}
                                    </flux:table.cell>
                                    <flux:table.cell class="max-w-xs truncate">
                                        <span title="{{ $image->caption ?? $image->alt_text }}">
                                            {{ Str::limit($image->caption ?? $image->alt_text, 60) }}
                                        </span>
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        {{ $image->google_places_uploaded_at?->format('M j, Y g:i A') ?? '—' }}
                                    </flux:table.cell>
                                    <flux:table.cell class="max-w-xs truncate">
                                        <span title="{{ $image->google_places_media_name }}">
                                            {{ Str::limit($image->google_places_media_name ?? '—', 40) }}
                                        </span>
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </flux:card>

            @endif
        </div>
    </div>

    {{-- Yelp Biz Photos --}}
    @if($yelpImages !== null)
    <div id="yelp-business-photos" x-data="{ open: false }" class="space-y-3">
        <button
            type="button"
            @click="open = !open"
            class="flex w-full items-center justify-between rounded-xl border border-zinc-200 bg-white px-4 py-3 text-left shadow-sm transition hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:bg-zinc-850"
        >
            <div>
                <flux:heading size="lg" class="mt-2">Yelp Business Photos</flux:heading>
                <p class="text-sm text-zinc-500">Uploaded business gallery photos.</p>
            </div>
            <svg class="size-4 shrink-0 transition-transform duration-200" :class="{ 'rotate-180': open }" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
            </svg>
        </button>

        <div x-show="open" x-collapse x-cloak class="space-y-3">
            @if($yelpImages->isEmpty())
                <flux:card>
                    <p class="py-8 text-center text-sm text-zinc-500">No Yelp biz photos uploaded yet. Run <code>php artisan yelp:sync-business-photos</code>.</p>
                </flux:card>
            @else
                <flux:card>
                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>Platform</flux:table.column>
                            <flux:table.column>Image</flux:table.column>
                            <flux:table.column>Project</flux:table.column>
                            <flux:table.column>Caption</flux:table.column>
                            <flux:table.column>Uploaded</flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @foreach($yelpImages as $image)
                                <flux:table.row>
                                    <flux:table.cell>
                                        <flux:badge color="red" size="sm">Yelp</flux:badge>
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        <img
                                            src="{{ $image->getThumbnailUrl('thumb') }}"
                                            alt="{{ $image->alt_text }}"
                                            class="h-10 w-10 rounded object-cover"
                                        />
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        {{ $image->project?->title ?? '—' }}
                                    </flux:table.cell>
                                    <flux:table.cell class="max-w-xs truncate">
                                        <span title="{{ $image->caption ?? $image->alt_text }}">
                                            {{ Str::limit($image->caption ?? $image->alt_text, 60) }}
                                        </span>
                                    </flux:table.cell>
                                    <flux:table.cell>
                                        {{ $image->yelp_biz_uploaded_at?->format('M j, Y g:i A') ?? '—' }}
                                    </flux:table.cell>
                                </flux:table.row>
                            @endforeach
                        </flux:table.rows>
                    </flux:table>
                </flux:card>
            @endif
        </div>
    </div>
    @endif
</div>
