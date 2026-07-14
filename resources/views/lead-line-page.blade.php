{{-- Rendered by /areas-served/{area}/lead-pipe-replacement (see routes/web.php).
     $area = AreaServed model; $info = LeadLineInfo::forSlug() array or null. --}}
@php
    $hasInfo = (bool) ($info['found_official_info'] ?? false);
    $published = fn (?string $v) => $v !== null && trim($v) !== '' && ! preg_match('/^not published$/i', trim($v));
    $faqs = [];
    if ($hasInfo && $published($info['cost_coverage'] ?? null)) {
        $faqs[] = ['question' => "Who pays for lead pipe replacement in {$area->city}?", 'answer' => trim($info['cost_coverage']) . ' Program terms change — confirm current details with the municipality before planning around them.'];
    }
    if ($hasInfo && $published($info['how_to_check_line'] ?? null)) {
        $faqs[] = ['question' => "How do I find out if my {$area->city} home has a lead water line?", 'answer' => trim($info['how_to_check_line'])];
    }
    $faqs[] = ['question' => 'Does GS Construction replace lead service lines?', 'answer' => 'Service-line replacement itself is licensed plumbing and excavation work — on GS projects it runs through our Illinois-licensed plumbing and excavation partners, and we handle what comes after: restoring the yard, landscaping edges, and any interior finishes the work touched. If a remodel uncovers a lead line, we flag it and point you at the municipal program before anyone spends your money.'];
    $faqs[] = ['question' => 'Why do lead lines come up during remodels?', 'answer' => 'Kitchen and bathroom remodels expose the plumbing where it enters the home, and permit inspections increasingly check service-line material against the municipal inventory Illinois law requires. Finding out mid-project is normal — knowing your village\'s program before demo day is better.'];
@endphp
<x-layouts.app>
    <x-breadcrumb-schema :items="[
        ['name' => 'Areas Served', 'url' => url('/areas-served')],
        ['name' => $area->city, 'url' => url('/areas-served/' . $area->slug)],
        ['name' => 'Lead Pipe Replacement'],
    ]" />

    <div class="mx-auto max-w-3xl px-4 pt-10 sm:px-6 sm:pt-14 lg:px-8">
        <nav class="flex text-sm" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-2">
                <li><a href="/" wire:navigate class="text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">Home</a></li>
                <li class="flex items-center">
                    <svg class="h-4 w-4 shrink-0 text-gray-500" fill="currentColor" viewBox="0 0 20 20" aria-hidden="true"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" /></svg>
                    <a href="{{ url('/areas-served/' . $area->slug) }}" wire:navigate class="ml-2 text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">{{ $area->city }}</a>
                </li>
            </ol>
        </nav>

        <p class="mt-6 text-sm font-semibold uppercase tracking-wide text-sky-700 dark:text-sky-400">{{ $area->city }}, IL</p>
        <h1 class="mt-1 font-heading text-3xl font-bold tracking-tight text-balance text-zinc-900 sm:text-4xl dark:text-white">
            Lead water pipe replacement in {{ $area->city }}
        </h1>

        @if($hasInfo && $published($info['cost_coverage'] ?? null))
            {{-- Direct answer — the money fact, verified against official sources. --}}
            <p class="speakable mt-4 text-base leading-7 text-zinc-600 dark:text-zinc-300">
                {{ $info['cost_coverage'] }}
            </p>
        @else
            <p class="speakable mt-4 text-base leading-7 text-zinc-600 dark:text-zinc-300">
                Illinois law requires every community water system to inventory its lead service lines and replace
                them over time — and in many Chicago suburbs, the municipality covers some or all of the replacement
                cost. Below is what applies in {{ $area->city }} and how to check your own line.
            </p>
        @endif

        {{-- Municipality specifics --}}
        @if($hasInfo)
            <h2 class="mt-10 font-heading text-2xl font-bold text-zinc-900 dark:text-white">{{ $area->city }}'s program at a glance</h2>
            <div class="mt-5 space-y-4">
                @if($published($info['water_system'] ?? null))
                    <div class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                        <h3 class="font-semibold text-zinc-900 dark:text-white">Who supplies the water</h3>
                        <p class="mt-1 text-sm leading-6 text-zinc-600 dark:text-zinc-400">{{ $info['water_system'] }}</p>
                    </div>
                @endif
                @if($published($info['program_name'] ?? null) && strtolower(trim($info['program_name'])) !== 'not named')
                    <div class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                        <h3 class="font-semibold text-zinc-900 dark:text-white">Program</h3>
                        <p class="mt-1 text-sm leading-6 text-zinc-600 dark:text-zinc-400">{{ $info['program_name'] }}</p>
                    </div>
                @endif
                @if($published($info['homeowner_cost'] ?? null))
                    <div class="rounded-2xl border border-sky-200 bg-sky-50 p-5 dark:border-sky-500/20 dark:bg-sky-500/5">
                        <h3 class="font-semibold text-zinc-900 dark:text-white">What the homeowner pays</h3>
                        <p class="mt-1 text-sm leading-6 text-zinc-700 dark:text-zinc-300">{{ $info['homeowner_cost'] }}</p>
                    </div>
                @endif
                @if($published($info['how_to_check_line'] ?? null))
                    <div class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                        <h3 class="font-semibold text-zinc-900 dark:text-white">How to check your line</h3>
                        <p class="mt-1 text-sm leading-6 text-zinc-600 dark:text-zinc-400">{{ $info['how_to_check_line'] }}</p>
                    </div>
                @endif
                @if($published($info['how_to_apply'] ?? null))
                    <div class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                        <h3 class="font-semibold text-zinc-900 dark:text-white">How to get into the program</h3>
                        <p class="mt-1 text-sm leading-6 text-zinc-600 dark:text-zinc-400">{{ $info['how_to_apply'] }}</p>
                    </div>
                @endif
                @if($published($info['notes'] ?? null))
                    <div class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                        <h3 class="font-semibold text-zinc-900 dark:text-white">Worth knowing</h3>
                        <p class="mt-1 text-sm leading-6 text-zinc-600 dark:text-zinc-400">{{ $info['notes'] }}</p>
                    </div>
                @endif
            </div>

            @if(!empty($info['source_urls']))
                <p class="mt-4 text-xs leading-5 text-zinc-500 dark:text-zinc-400">
                    Source{{ count($info['source_urls']) > 1 ? 's' : '' }} ({{ $area->city }} official pages
                    @if(!empty($info['researched_at'])), checked {{ \Illuminate\Support\Carbon::parse($info['researched_at'])->format('M Y') }}@endif):
                    @foreach($info['source_urls'] as $src)
                        <a href="{{ $src }}" rel="nofollow noopener" target="_blank" class="underline hover:text-zinc-700 dark:hover:text-zinc-200">{{ parse_url($src, PHP_URL_HOST) }}</a>@if(!$loop->last), @endif
                    @endforeach
                    — program terms change; always confirm current details with the municipality.
                </p>
            @endif
        @else
            <div class="mt-8 rounded-2xl border border-zinc-200 bg-zinc-50 p-6 dark:border-zinc-800 dark:bg-zinc-900/50">
                <h2 class="font-heading text-lg font-bold text-zinc-900 dark:text-white">Check with {{ $area->city }} directly</h2>
                <p class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-400">
                    We haven't yet verified a published replacement program for {{ $area->city }}. Every Illinois
                    community water system is required to maintain a service-line inventory — contact the
                    {{ $area->city }} public works or water department and ask two questions: what material is my
                    service line, and is there a replacement program with cost sharing?
                </p>
            </div>
        @endif

        {{-- Illinois context --}}
        <h2 class="mt-12 font-heading text-2xl font-bold text-zinc-900 dark:text-white">The Illinois picture</h2>
        <p class="mt-3 text-sm leading-6 text-zinc-600 dark:text-zinc-400">
            Illinois' Lead Service Line Replacement and Notification Act requires every community water supply to
            inventory its service lines and replace lead ones on a state-mandated schedule. Two practical
            consequences for homeowners: your municipality can tell you what your line is made of, and full
            replacement — not partial — is the standard when work happens. Many suburbs coordinate replacements
            with water-main projects, which is often when the cost-sharing is most generous.
        </p>

        {{-- GS angle --}}
        <div class="mt-10 rounded-2xl border border-sky-200 bg-sky-50 p-6 dark:border-sky-500/20 dark:bg-sky-500/5">
            <h2 class="font-heading text-xl font-bold text-zinc-900 dark:text-white">Where we come in</h2>
            <p class="mt-2 text-sm leading-6 text-zinc-700 dark:text-zinc-300">
                Remodels are where lead lines get discovered — kitchen and bath projects expose the plumbing, and
                permit inspections check service-line material against the municipal inventory. When that happens on
                a GS project in {{ $area->city }}, we flag it early, point you at the program above before anyone
                spends your money, run the replacement through our
                <a href="{{ route('trades.show', ['slug' => 'licensed-plumbers']) }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">Illinois-licensed plumbing</a>
                and
                <a href="{{ route('trades.show', ['slug' => 'excavation-contractors']) }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">excavation partners</a>,
                and restore everything the dig touched.
            </p>
        </div>

        <p class="mt-8 text-xs leading-5 text-zinc-500 dark:text-zinc-400">
            GS Construction &amp; Remodeling is a licensed general contractor, not a municipal agency. Program details
            above summarize official municipal sources and change over time — confirm current terms with
            {{ $area->city }} before making decisions based on them.
        </p>
    </div>

    <div class="mx-auto mt-12 max-w-3xl px-4 sm:px-6 lg:px-8">
        <x-faq-section
            heading="Lead pipes in {{ $area->city }} — common questions"
            :collapsed="false"
            :faqs="$faqs"
        />
    </div>

    <x-cta-section
        variant="blue"
        heading="Remodeling in {{ $area->city }}?"
        description="We check service-line implications as part of scoping kitchen, bath, and whole-home remodels — free in-home estimate, itemized scope."
    />
</x-layouts.app>
