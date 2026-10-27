{{-- Village/city building codes this town's building department has
     adopted, sourced from hive.contractors (see App\Console\Commands\
     AreasSyncBuildingCodes). Renders only once Hive has actually found an
     answer for this town — a fresh town sits in "pending" for a few days
     and shows nothing here rather than a placeholder. Expects $area
     (AreaServed). --}}
@php
    $buildingCodes = $area->buildingCodesList();
@endphp
@if(!empty($buildingCodes))
<section class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
        Building codes in {{ $area->city }}
    </h2>
    <p class="mt-2 max-w-3xl text-sm leading-6 text-zinc-600 dark:text-zinc-300">
        These are the codes the {{ $area->city }} building department has adopted. GS Construction's permit drawings and on-site work follow them on every project here.
    </p>
    <ul class="mt-4 space-y-2">
        @foreach($buildingCodes as $code)
            <li class="flex items-start gap-2 text-sm leading-6 text-zinc-700 dark:text-zinc-300">
                <span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-sky-500" aria-hidden="true"></span>
                <span>{{ $code }}</span>
            </li>
        @endforeach
    </ul>
    <p class="mt-3 text-sm text-zinc-500 dark:text-zinc-400">With local amendments.</p>
    @if(filled($area->building_codes_note))
        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $area->building_codes_note }}</p>
    @endif
    @if(filled($area->building_codes_source_url))
        <p class="mt-4 text-xs text-zinc-500 dark:text-zinc-400">
            Source: <a href="{{ $area->building_codes_source_url }}" rel="nofollow noopener" target="_blank" class="font-medium text-sky-700 hover:underline dark:text-sky-400">{{ parse_url($area->building_codes_source_url, PHP_URL_HOST) ?: $area->building_codes_source_url }}</a>@if($area->building_codes_checked_at), checked {{ $area->building_codes_checked_at->format('M j, Y') }}@endif.
        </p>
    @endif
</section>
@endif
