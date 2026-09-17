{{--
    Town contact page: the facts a {city} homeowner needs to book us, none of
    which the town page carries — the drive from the office, hours, what the
    village's building department will ask for, the neighbours on the same
    route, and our record nearby. Every figure comes from data (brand office
    coordinates, the town's coordinates, config/permit-guides.php, the
    project and review records), so each town's page reads differently
    because the facts differ, not because the words were shuffled.

    Expects $area (App\Models\AreaServed).
--}}
@php
    $trip = \App\Support\OfficeTrip::to($area);
    $hoursLine = \App\Support\OfficeTrip::hoursLine();
    $office = config('brand.address', []);
    $officeCity = trim((string) ($office['city'] ?? ''));
    $officeStreet = trim((string) ($office['street'] ?? ''));
    $phone = (string) config('brand.phone');
    $phoneHref = (string) config('brand.phone_href');
    $guide = \App\Support\PermitGuideInfo::forSlug($area->slug);
    $sentence = fn ($text) => \App\Support\PermitGuideInfo::sentence($text);
    $nearby = $area->nearestCities(5);
    $proof = $area->completedProjectsNearby();
    $localProjects = $area->localProjects(12)->count();
    $localReviews = $area->localTestimonials(12)->count();
    $zips = $area->postalCodes();
    $city = $area->city;
    // 18 towns have no ZIP on file and a few have no nearby record; the fact
    // cards fill the row either way instead of leaving a hole on desktop.
    $cardCount = count(array_filter([$trip, $hoursLine, $proof && $proof['count'] > 0, $zips !== []]));
    $cardCols = match (true) { $cardCount >= 4 => 'lg:grid-cols-4', $cardCount === 3 => 'lg:grid-cols-3', default => 'lg:grid-cols-2' };

    // Three phrasings by distance band, so the office's own neighbours are
    // not told about a "drive" and the far towns are told the truth.
    $tripLine = null;
    if ($trip) {
        $tripLine = match (true) {
            $trip['miles'] <= 6 => "Our office at {$officeStreet} in {$officeCity} is about {$trip['miles']} ".\Illuminate\Support\Str::plural('mile', $trip['miles'])." from {$city} — {$trip['minutes_low']} to {$trip['minutes_high']} minutes door to door — so we can usually fit a {$city} visit around a job already under way.",
            $trip['miles'] <= 18 => "{$city} is about {$trip['miles']} miles by road from our office at {$officeStreet}, {$officeCity}: a {$trip['minutes_low']}–{$trip['minutes_high']} minute drive outside rush hour, which keeps both morning and afternoon consultation windows realistic in the same week you call.",
            default => "{$city} is about {$trip['miles']} miles by road from our office at {$officeStreet}, {$officeCity} — roughly {$trip['minutes_low']}–{$trip['minutes_high']} minutes outside rush hour — so we group {$city} consultations with our other jobs on that side of the metro and confirm a window when you book.",
        };
    }

    $permitLine = $guide
        ? ($sentence($guide['review_time'] ?? null) ?: "The {$city} building department reviews remodeling permits before work starts.")
        : "The {$city} building department reviews remodeling permits before work starts; we prepare the application, register with the village where it asks us to, and schedule every inspection.";

    $faqs = [
        [
            'question' => "How soon can you come out to {$city}?",
            'answer' => $trip
                ? "Usually within the week. {$city} is about {$trip['minutes_low']}–{$trip['minutes_high']} minutes from our {$officeCity} office, so we can offer windows ".($hoursLine ? $hoursLine : 'six days a week').", and the visit itself takes about an hour."
                : "Usually within the week, ".($hoursLine ? $hoursLine : 'six days a week').". The visit itself takes about an hour.",
        ],
        [
            'question' => "Is the consultation in {$city} free?",
            'answer' => "Yes. Greg or Patryk walks the space with you, talks through what is possible and what it will take, and follows up with a written estimate. No fee and no obligation.",
        ],
        [
            'question' => "Will you handle the {$city} permit?",
            'answer' => $guide
                ? trim("Yes. {$permitLine} ".($sentence($guide['contractor_registration'] ?? null) ?? '')." We file the application, register the crew where {$city} requires it, and book each inspection.")
                : "Yes. {$permitLine} Tell us what you have in mind when you book and we will say which parts of it the village will want to review.",
        ],
    ];
@endphp

<section class="bg-white py-10 sm:py-14 dark:bg-zinc-900" aria-label="Booking a consultation in {{ $city }}">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <h2 class="font-heading text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">
            Booking a consultation in {{ $city }}, IL
        </h2>
        <p class="mt-4 max-w-3xl text-base leading-7 text-zinc-700 dark:text-zinc-300">
            Every {{ $city }} consultation happens at your home{{ $hoursLine ? ', '.$hoursLine : '' }}.
            @if($tripLine){{ $tripLine }}@endif
        </p>

        <dl class="mt-8 grid gap-6 sm:grid-cols-2 {{ $cardCols }}">
            @if($trip)
                <div class="rounded-2xl bg-zinc-50 p-5 ring-1 ring-zinc-200 dark:bg-zinc-800 dark:ring-zinc-700">
                    <dt class="text-sm font-semibold text-zinc-500 dark:text-zinc-400">From our office</dt>
                    <dd class="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">About {{ $trip['miles'] }} {{ \Illuminate\Support\Str::plural('mile', $trip['miles']) }}</dd>
                    <dd class="text-sm text-zinc-600 dark:text-zinc-300">{{ $trip['minutes_low'] }}–{{ $trip['minutes_high'] }} min from {{ $officeCity }}</dd>
                </div>
            @endif
            @if($hoursLine)
                <div class="rounded-2xl bg-zinc-50 p-5 ring-1 ring-zinc-200 dark:bg-zinc-800 dark:ring-zinc-700">
                    <dt class="text-sm font-semibold text-zinc-500 dark:text-zinc-400">Consultation hours</dt>
                    <dd class="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">{{ $hoursLine }}</dd>
                    <dd class="text-sm text-zinc-600 dark:text-zinc-300">Call <a href="tel:{{ $phoneHref }}" class="font-medium text-sky-700 hover:underline dark:text-sky-400">{{ $phone }}</a> or pick a time above</dd>
                </div>
            @endif
            @if($proof && $proof['count'] > 0)
                <div class="rounded-2xl bg-zinc-50 p-5 ring-1 ring-zinc-200 dark:bg-zinc-800 dark:ring-zinc-700">
                    <dt class="text-sm font-semibold text-zinc-500 dark:text-zinc-400">Our record near {{ $city }}</dt>
                    <dd class="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">{{ number_format($proof['count']) }} {{ \Illuminate\Support\Str::plural('project', $proof['count']) }} within {{ $proof['radius'] }} miles</dd>
                    <dd class="text-sm text-zinc-600 dark:text-zinc-300">
                        @if($localProjects > 0 || $localReviews > 0)
                            {{ $localProjects }} {{ \Illuminate\Support\Str::plural('project', $localProjects) }} and {{ $localReviews }} {{ \Illuminate\Support\Str::plural('review', $localReviews) }} in {{ $city }} itself
                        @else
                            From our own job records, not a service-area claim
                        @endif
                    </dd>
                </div>
            @endif
            @if($zips !== [])
                <div class="rounded-2xl bg-zinc-50 p-5 ring-1 ring-zinc-200 dark:bg-zinc-800 dark:ring-zinc-700">
                    <dt class="text-sm font-semibold text-zinc-500 dark:text-zinc-400">{{ $city }} ZIP {{ \Illuminate\Support\Str::plural('code', count($zips)) }} we cover</dt>
                    <dd class="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">{{ implode(', ', array_slice($zips, 0, 6)) }}</dd>
                    <dd class="text-sm text-zinc-600 dark:text-zinc-300">Type your address in the form and it fills the rest</dd>
                </div>
            @endif
        </dl>

        <div class="mt-10 grid gap-10 lg:grid-cols-2">
            <div>
                <h3 class="font-heading text-xl font-bold text-zinc-900 dark:text-white">
                    @if($guide) Before we start: what {{ $city }} will ask for @else Permits in {{ $city }} @endif
                </h3>
                @if($guide)
                    <ul class="mt-4 space-y-3 text-base leading-7 text-zinc-700 dark:text-zinc-300">
                        @foreach(['review_time' => 'Review time', 'contractor_registration' => 'Contractor registration', 'inspections' => 'Inspections', 'notable_quirks' => 'Worth knowing'] as $field => $label)
                            @if($line = $sentence($guide[$field] ?? null))
                                <li><span class="font-semibold text-zinc-900 dark:text-white">{{ $label }}:</span> {{ $line }}</li>
                            @endif
                        @endforeach
                    </ul>
                    <p class="mt-4 text-base leading-7 text-zinc-700 dark:text-zinc-300">
                        We prepare the application, register the crew with the village and schedule every inspection.
                        <a href="{{ route('permits.show', ['slug' => $area->slug]) }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">Read our {{ $city }} building-permit guide</a>.
                    </p>
                @else
                    <p class="mt-4 text-base leading-7 text-zinc-700 dark:text-zinc-300">
                        {{ $permitLine }}
                        <a href="{{ route('permits.index') }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">See the permit guides we have written for nearby villages</a>.
                    </p>
                @endif

                <h3 class="mt-8 font-heading text-xl font-bold text-zinc-900 dark:text-white">What to have ready</h3>
                <ul class="mt-4 list-disc space-y-2 pl-5 text-base leading-7 text-zinc-700 dark:text-zinc-300">
                    <li>A few photos of the space as it is, and any plans or drawings you already have.</li>
                    <li>Rough dimensions if you have them — we measure on the visit either way.</li>
                    <li>Pictures of kitchens, baths or basements you like, so we can talk about what makes them work.</li>
                    <li>A budget range you are comfortable with, so the estimate lands where you can act on it.</li>
                </ul>
            </div>

            <div>
                @if($nearby->isNotEmpty())
                    <h3 class="font-heading text-xl font-bold text-zinc-900 dark:text-white">Also on the {{ $city }} route</h3>
                    <p class="mt-4 text-base leading-7 text-zinc-700 dark:text-zinc-300">
                        The towns we visit from the same direction, so a {{ $city }} consultation often shares a day with one of these.
                    </p>
                    <ul class="mt-4 grid gap-2 sm:grid-cols-2">
                        @foreach($nearby as $town)
                            <li>
                                <a href="{{ $town->pageUrl('contact') }}" wire:navigate class="flex items-baseline justify-between rounded-xl bg-zinc-50 px-4 py-3 text-sm ring-1 ring-zinc-200 hover:ring-zinc-400 dark:bg-zinc-800 dark:ring-zinc-700 dark:hover:ring-zinc-500">
                                    <span class="font-medium text-zinc-900 dark:text-white">{{ $town->city }}</span>
                                    @if(isset($town->distance_miles))
                                        <span class="text-zinc-500 dark:text-zinc-400">{{ number_format((float) $town->distance_miles, 1) }} mi</span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <h3 class="mt-8 font-heading text-xl font-bold text-zinc-900 dark:text-white">What happens after you send the form</h3>
                <ol class="mt-4 list-decimal space-y-2 pl-5 text-base leading-7 text-zinc-700 dark:text-zinc-300">
                    <li>You get a text and an email confirming the times you picked; Greg or Patryk confirms one of them the same day.</li>
                    <li>On the visit we measure, look at the mechanicals and talk through options — about an hour.</li>
                    <li>You receive a written, itemized estimate, followed by the {{ $city }} permit application once you say go.</li>
                </ol>
            </div>
        </div>
    </div>
</section>

<x-faq-section :faqs="$faqs" :heading="'Booking questions from ' . $city . ' homeowners'" />
