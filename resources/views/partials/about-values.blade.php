{{-- Company values.

     Shared by /about and every /areas-served/{area}/about. The section was
     already written to be area-aware — "Our Values Serving {city}", "we take
     pride in making {city} homes beautiful" — but only the main page ever
     rendered it, so the area pages lost content that was built for them.

     Expects an optional $area (AreaServed). Without it the copy falls back to
     the company-wide wording. --}}
        <!-- Values section -->
        <div class="mx-auto mt-10 max-w-7xl px-6 sm:mt-12 lg:px-8">
            <div class="mx-auto max-w-2xl lg:mx-0">
                <h2 class="font-heading text-3xl font-bold tracking-tight text-zinc-900 sm:text-4xl dark:text-white">{{ isset($area) ? 'Our Values Serving ' . $area->city : 'Our Values' }}</h2>
                <p class="mt-6 text-lg/8 text-zinc-600 dark:text-zinc-300">
                    @if(isset($area))
                    These principles guide everything we do for {{ $area->city }} homeowners, from the first phone call to the final nail.
                    @else
                    These principles guide everything we do, from the first phone call to the final nail.
                    @endif
                </p>
            </div>
            <dl class="mx-auto mt-10 grid max-w-2xl grid-cols-1 gap-6 text-base/7 sm:grid-cols-2 lg:mx-0 lg:max-w-none lg:grid-cols-3">
                <div class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 transition hover:shadow-lg hover:border-zinc-300 dark:hover:border-zinc-600">
                    <dt class="font-semibold text-zinc-900 dark:text-white">Quality Craftsmanship</dt>
                    <dd class="mt-1 text-sm/6 text-zinc-600 dark:text-zinc-400">We never cut corners. Every joint, every finish, every detail matters. Our reputation is built on work that stands the test of time.</dd>
                </div>
                <div class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 transition hover:shadow-lg hover:border-zinc-300 dark:hover:border-zinc-600">
                    <dt class="font-semibold text-zinc-900 dark:text-white">Transparent Communication</dt>
                    <dd class="mt-1 text-sm/6 text-zinc-600 dark:text-zinc-400">No surprises, no hidden costs. We keep you informed at every stage, so you always know exactly what's happening with your project.</dd>
                </div>
                <div class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 transition hover:shadow-lg hover:border-zinc-300 dark:hover:border-zinc-600">
                    <dt class="font-semibold text-zinc-900 dark:text-white">Respect for Your Home</dt>
                    <dd class="mt-1 text-sm/6 text-zinc-600 dark:text-zinc-400">We treat your home like our own. That means protecting your belongings, cleaning up daily, and minimizing disruption to your life.</dd>
                </div>
                <div class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 transition hover:shadow-lg hover:border-zinc-300 dark:hover:border-zinc-600">
                    <dt class="font-semibold text-zinc-900 dark:text-white">Personal Involvement</dt>
                    <dd class="mt-1 text-sm/6 text-zinc-600 dark:text-zinc-400">Gregory or Patryk is on-site for every {{ isset($area) ? $area->city : '' }} project. You'll always have a direct line to the owners, not a middleman.</dd>
                </div>
                <div class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 transition hover:shadow-lg hover:border-zinc-300 dark:hover:border-zinc-600">
                    <dt class="font-semibold text-zinc-900 dark:text-white">Honest Pricing</dt>
                    <dd class="mt-1 text-sm/6 text-zinc-600 dark:text-zinc-400">We provide detailed, upfront quotes. If something changes, we discuss it with you first. No surprise invoices, ever.</dd>
                </div>
                <div class="group rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 transition hover:shadow-lg hover:border-zinc-300 dark:hover:border-zinc-600">
                    <dt class="font-semibold text-zinc-900 dark:text-white">Community First</dt>
                    <dd class="mt-1 text-sm/6 text-zinc-600 dark:text-zinc-400">We're your neighbors. We live and work in the communities we serve, and we take pride in making {{ isset($area) ? $area->city : 'Chicagoland' }} homes beautiful.</dd>
                </div>
            </dl>
        </div>
