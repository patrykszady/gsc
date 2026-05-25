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
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-7">
        <flux:card class="text-center">
            <p class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $totalEligible }}</p>
            <p class="text-sm text-zinc-500">Total Images</p>
        </flux:card>
        <flux:card class="text-center">
            <p class="text-2xl font-bold text-green-600">{{ $publishedCount }}</p>
            <p class="text-sm text-zinc-500">Published</p>
        </flux:card>
        <flux:card class="text-center">
            <p class="text-2xl font-bold text-pink-600">{{ $remainingInstagram }}</p>
            <p class="text-sm text-zinc-500">IG Remaining</p>
        </flux:card>
        <flux:card class="text-center">
            <p class="text-2xl font-bold text-blue-600">{{ $remainingFacebook }}</p>
            <p class="text-sm text-zinc-500">FB Remaining</p>
        </flux:card>
        <flux:card class="text-center">
            <p class="text-2xl font-bold text-amber-600">{{ $remainingGbp }}</p>
            <p class="text-sm text-zinc-500">GBP Remaining</p>
        </flux:card>
        <flux:card class="text-center">
            <p class="text-2xl font-bold text-red-600">{{ $uploadedYelp }}</p>
            <p class="text-sm text-zinc-500">Yelp Uploaded</p>
        </flux:card>
        <flux:card class="text-center">
            <p class="text-2xl font-bold text-zinc-400">{{ $remainingYelp }}</p>
            <p class="text-sm text-zinc-500">Yelp Remaining</p>
        </flux:card>
    </div>

    @if(!$isConfigured)
        <flux:card class="border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-950">
            <div class="space-y-2">
                <flux:heading size="lg">Setup Required</flux:heading>
                <p class="text-sm text-zinc-600 dark:text-zinc-400">
                    To start posting, add your Meta credentials to <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">.env</code>:
                </p>
                <pre class="rounded bg-zinc-100 p-3 text-xs dark:bg-zinc-800">META_SOCIAL_ENABLED=true
META_APP_ID=your_app_id
META_APP_SECRET=your_app_secret
META_FACEBOOK_PAGE_ID=your_page_id
META_INSTAGRAM_ACCOUNT_ID=your_ig_id</pre>
                <p class="text-sm text-zinc-600 dark:text-zinc-400">
                    Then run: <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">php artisan social:meta-auth</code>
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

    {{-- Posts Table (Instagram / Facebook / GBP) --}}
    @if($platformFilter !== 'yelp')
    <flux:card>
        @if($posts->isEmpty())
            <p class="py-8 text-center text-sm text-zinc-500">
                No posts yet. Posts will appear here once the scheduler starts or you click "Post Now".
            </p>
        @else
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
                    @foreach($posts as $post)
                        <flux:table.row>
                            <flux:table.cell>
                                @if($post->platform === 'instagram')
                                    <flux:badge color="pink" size="sm">Instagram</flux:badge>
                                @elseif($post->platform === 'facebook')
                                    <flux:badge color="blue" size="sm">Facebook</flux:badge>
                                @else
                                    <flux:badge color="amber" size="sm">Google</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell>
                                @if($post->projectImage)
                                    <img
                                        src="{{ $post->projectImage->getThumbnailUrl('thumb') }}"
                                        alt="{{ $post->projectImage->alt_text }}"
                                        class="h-10 w-10 rounded object-cover"
                                    />
                                @endif
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
                                @if($post->published_at)
                                    {{ $post->published_at->format('M j, Y g:i A') }}
                                @else
                                    —
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        @endif
    </flux:card>
    @endif

    {{ $platformFilter !== 'yelp' ? $posts->links() : '' }}

    {{-- Yelp Biz Photos Table --}}
    @if($yelpImages !== null)
    <flux:heading size="lg" class="mt-2">Yelp Business Photos</flux:heading>
    <flux:card>
        @if($yelpImages->isEmpty())
            <p class="py-8 text-center text-sm text-zinc-500">No Yelp biz photos uploaded yet. Run <code>php artisan yelp:sync-business-photos</code>.</p>
        @else
            <flux:table>
                <flux:table.columns>
                    <flux:table.column>Image</flux:table.column>
                    <flux:table.column>Project</flux:table.column>
                    <flux:table.column>Caption</flux:table.column>
                    <flux:table.column>Uploaded</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach($yelpImages as $image)
                        <flux:table.row>
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
        @endif
    </flux:card>
    {{ $yelpImages->links() }}
    @endif
</div>
