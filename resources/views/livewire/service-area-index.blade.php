<div class="bg-white dark:bg-gray-900">
    <x-breadcrumb-schema :items="[
        ['name' => 'Service Area by ZIP'],
    ]" />

    <div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
        <nav class="flex" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-2 text-sm">
                <li>
                    <a href="/" wire:navigate class="text-gray-600 hover:text-gray-900 dark:text-gray-300 dark:hover:text-gray-100">Home</a>
                </li>
                <li class="flex items-center">
                    <svg class="h-4 w-4 shrink-0 text-gray-500" fill="currentColor" aria-hidden="true" viewBox="0 0 20 20">
                        <path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd" />
                    </svg>
                    <span class="ml-2 text-gray-700 dark:text-gray-300">Service Area by ZIP</span>
                </li>
            </ol>
        </nav>

        <p class="text-sm font-semibold uppercase tracking-wide text-sky-600">Service area</p>
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
                    <a href="{{ url('/service-area/' . reset($zips)) }}" wire:navigate class="block transition">
                        <h2 class="text-lg font-semibold text-gray-900 hover:text-sky-600 dark:text-white dark:hover:text-sky-400">{{ $cityName }}, IL</h2>
                    </a>
                    <ul class="mt-3 flex flex-wrap gap-2">
                        @foreach ($zips as $zip)
                            <li>
                                <a href="{{ url('/service-area/' . $zip) }}" wire:navigate
                                    class="inline-flex items-center rounded-md bg-sky-50 px-2.5 py-1 text-sm font-medium text-sky-800 ring-1 ring-inset ring-sky-200 hover:bg-sky-100 dark:bg-sky-900/30 dark:text-sky-200 dark:ring-sky-800">
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
