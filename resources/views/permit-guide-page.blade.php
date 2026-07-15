{{-- Rendered by the /permits/{slug} route with $slug + $guide from App\Support\PermitGuideInfo.
     NOTE: keep this comment free of Blade directive words; all derived values are
     computed in the PHP block below and rendered flat (no nested conditionals). --}}
@php
    $town = (string) ($guide['town'] ?? \Illuminate\Support\Str::headline($slug));

    // Direct-answer paragraph: the first couple of sentences of the
    // "when required" research, quoted by AI answers / voice search.
    $whenRequired = (string) ($guide['permit_when_required'] ?? '');
    $sentences = preg_split('/(?<=[.!?])\s+/', trim($whenRequired), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $directAnswer = trim(implode(' ', array_slice($sentences, 0, 2)));

    // Notable quirks: array renders as a list; a numbered prose string is
    // split into items when possible, otherwise rendered as one paragraph.
    $quirksRaw = $guide['notable_quirks'] ?? [];
    if (is_array($quirksRaw)) {
        $quirks = array_values(array_filter(array_map('trim', $quirksRaw)));
    } else {
        $quirks = preg_split('/\s*(?:^|(?<=\s))\(?\d{1,2}\)\s+/', (string) $quirksRaw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $quirks = array_values(array_filter(array_map('trim', $quirks)));
    }
    $quirksAsList = count($quirks) > 1;
    $quirksParagraph = $quirksAsList ? '' : trim(is_array($quirksRaw) ? implode(' ', $quirks) : (string) $quirksRaw);

    // Official sources: display "host/trimmed-path" for each outbound link.
    $sources = collect((array) ($guide['source_urls'] ?? []))->map(function (string $url): array {
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: $url);
        $path = rtrim((string) (parse_url($url, PHP_URL_PATH) ?: ''), '/');
        $display = preg_replace('/^www\./', '', $host) . \Illuminate\Support\Str::limit($path, 60);

        return ['url' => $url, 'display' => $display];
    });

    $researchedAt = (string) ($guide['researched_at'] ?? '');
    $areaUrl = url('/areas-served/' . $slug);
@endphp
<x-layouts.app
    :title="'Building Permits in ' . $town . ', IL — Remodeling Permit Guide (' . now()->year . ') | GS Construction'"
    :metaDescription="\Illuminate\Support\Str::limit($whenRequired, 155)"
>
    <x-breadcrumb-schema :items="[
        ['name' => 'Permit Guides', 'url' => route('permits.index')],
        ['name' => $town],
    ]" />

    <div class="mx-auto max-w-3xl px-4 pt-10 sm:px-6 sm:pt-14 lg:px-8">
        <nav class="flex text-sm" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-2">
                <li><a href="/" wire:navigate class="text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Home</a></li>
                <li class="flex items-center">
                    <svg class="h-4 w-4 shrink-0 text-gray-500" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" /></svg>
                    <a href="{{ route('permits.index') }}" wire:navigate class="ml-2 text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Permit Guides</a>
                </li>
            </ol>
        </nav>

        <p class="mt-6 text-sm font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-400">{{ $town }} · {{ now()->year }}</p>
        <h1 class="mt-1 font-heading text-3xl font-bold tracking-tight text-balance text-zinc-900 sm:text-4xl dark:text-white">
            Building permits for remodeling in {{ $town }}, IL
        </h1>
        {{-- Direct answer — the paragraph AI answers and voice search quote. --}}
        <p class="speakable mt-4 text-base leading-7 text-zinc-600 dark:text-zinc-300">
            {{ $directAnswer }}
        </p>

        <h2 class="mt-10 font-heading text-2xl font-bold text-zinc-900 dark:text-white">When you need a permit</h2>
        <p class="mt-4 text-base leading-7 text-zinc-600 dark:text-zinc-300">{{ $whenRequired }}</p>

        <h2 class="mt-10 font-heading text-2xl font-bold text-zinc-900 dark:text-white">How to apply</h2>
        <p class="mt-4 text-base leading-7 text-zinc-600 dark:text-zinc-300">{{ $guide['application_process'] ?? '' }}</p>

        <h2 class="mt-10 font-heading text-2xl font-bold text-zinc-900 dark:text-white">Review times</h2>
        <p class="mt-4 text-base leading-7 text-zinc-600 dark:text-zinc-300">{{ $guide['review_time'] ?? '' }}</p>

        <h2 class="mt-10 font-heading text-2xl font-bold text-zinc-900 dark:text-white">Inspections</h2>
        <p class="mt-4 text-base leading-7 text-zinc-600 dark:text-zinc-300">{{ $guide['inspections'] ?? '' }}</p>

        <h2 class="mt-10 font-heading text-2xl font-bold text-zinc-900 dark:text-white">Fees</h2>
        <p class="mt-4 text-base leading-7 text-zinc-600 dark:text-zinc-300">{{ $guide['fees'] ?? '' }}</p>

        <h2 class="mt-10 font-heading text-2xl font-bold text-zinc-900 dark:text-white">Contractor registration</h2>
        <p class="mt-4 text-base leading-7 text-zinc-600 dark:text-zinc-300">{{ $guide['contractor_registration'] ?? '' }}</p>

        <h2 class="mt-10 font-heading text-2xl font-bold text-zinc-900 dark:text-white">Worth knowing</h2>
        @if($quirksAsList)
            <ul class="mt-4 space-y-3">
                @foreach($quirks as $quirk)
                    <li class="flex gap-3 rounded-2xl border border-zinc-200 bg-white p-4 text-sm leading-6 text-zinc-700 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300">
                        <span class="mt-1 size-1.5 shrink-0 rounded-full bg-sky-500" aria-hidden="true"></span>
                        <span>{{ $quirk }}</span>
                    </li>
                @endforeach
            </ul>
        @else
            <p class="mt-4 text-base leading-7 text-zinc-600 dark:text-zinc-300">{{ $quirksParagraph }}</p>
        @endif

        {{-- We handle it for you --}}
        <div class="mt-10 rounded-2xl border border-sky-200 bg-sky-50 p-6 dark:border-sky-500/20 dark:bg-sky-500/5">
            <h2 class="font-heading text-xl font-bold text-zinc-900 dark:text-white">We handle the permits for you</h2>
            <p class="mt-2 text-sm leading-6 text-zinc-700 dark:text-zinc-300">
                On every GS Construction remodel in {{ $town }}, we prepare the application and
                drawings, register with the village where required, track the plan review, and
                schedule every inspection through to final sign-off — you never stand in line at
                village hall. See <a href="{{ route('process') }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">how the GS process works</a>
                or <a href="{{ route('contact') }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">talk to us about your project</a>.
            </p>
            <p class="mt-3 text-sm leading-6 text-zinc-700 dark:text-zinc-300">
                Planning a project here? See
                <a href="{{ $areaUrl }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">Remodeling in {{ $town }}</a>.
            </p>
        </div>

        {{-- Official sources --}}
        <h2 class="mt-10 font-heading text-2xl font-bold text-zinc-900 dark:text-white">Official sources</h2>
        <ul class="mt-4 space-y-2">
            @foreach($sources as $source)
                <li class="text-sm leading-6">
                    <a href="{{ $source['url'] }}" rel="nofollow noopener" target="_blank"
                       class="break-all font-medium text-sky-700 hover:underline dark:text-sky-400">{{ $source['display'] }}</a>
                </li>
            @endforeach
        </ul>

        @if($researchedAt !== '')
            <p class="mt-8 text-xs leading-5 text-zinc-500 dark:text-zinc-400">
                Last verified against official {{ $town }} sources: {{ $researchedAt }}.
                Requirements and fees change — confirm with the village before you build.
            </p>
        @endif
    </div>

    <x-cta-section
        variant="blue"
        heading="Remodeling in {{ $town }}? We pull the permits."
        description="Free in-home estimate with an itemized scope — permits, drawings, and inspections handled by us on every project."
    />
</x-layouts.app>
