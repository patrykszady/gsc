<div class="bg-white dark:bg-gray-900">
    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <p class="text-sm font-semibold uppercase tracking-wide text-amber-600">Service area</p>
        <h1 class="mt-2 text-3xl font-bold tracking-tight text-gray-900 dark:text-white sm:text-4xl">
            ZIP codes we serve across Chicagoland
        </h1>
        <p class="mt-4 max-w-3xl text-lg text-gray-600 dark:text-gray-300">
            GS Construction has completed remodeling projects in
            <strong>{{ $totalZips }}</strong> ZIP codes across Chicago and the surrounding suburbs.
            Find your ZIP below for kitchen, bathroom, and home remodeling near you.
        </p>

        <div class="mt-10 grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($grouped as $cityName => $zips)
                <div class="rounded-lg border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-800">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $cityName }}, IL</h2>
                    <ul class="mt-3 flex flex-wrap gap-2">
                        @foreach ($zips as $zip)
                            <li>
                                <a href="{{ url('/service-area/' . $zip) }}" wire:navigate
                                    class="inline-flex items-center rounded-md bg-amber-50 px-2.5 py-1 text-sm font-medium text-amber-800 ring-1 ring-inset ring-amber-200 hover:bg-amber-100 dark:bg-amber-900/30 dark:text-amber-200 dark:ring-amber-800">
                                    {{ $zip }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </div>
</div>
