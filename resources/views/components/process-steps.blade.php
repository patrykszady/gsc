{{--
    The six-stage process section: heading + Process CTA on one line, a short
    standfirst, then one card per stage.

    Was written twice — once on /services/{service} and once on
    /areas-served/{area}/services/{service} — and the two had already drifted:
    the area version had the Process button beside its heading, while the
    service version still carried the older inline "See the full process with
    typical timelines" link, so the same section looked like two different
    designs depending on which page you landed on.

    Props
      steps       array  — [['step' =>, 'title' =>, 'description' =>, 'link' => ['href','text']]]
      heading     string — "Our Process" / "How your {city} project runs"
      subheading  string|null
      ctaText / ctaHref — the button beside the heading. Pass ctaText="" to drop it.

    Background and vertical rhythm come in via $attributes, since the area page
    sits the section on a tinted band and the service page does not.
--}}

@props([
    'steps' => [],
    'heading' => 'Our Process',
    'subheading' => 'The same six stages on every job — free in-home estimate through warranty.',
    'ctaText' => 'Process',
    'ctaHref' => '/process',
])

@if(!empty($steps))
    <section {{ $attributes->class('py-12 sm:py-16') }}>
        <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
            {{-- Heading and button share one line, both hard left: justify-between
                 pushed the button to the far right edge, which detached it from
                 the heading it belongs to. --}}
            <div class="flex flex-wrap items-center gap-4">
                <h2 class="font-heading text-2xl font-bold tracking-tight text-zinc-900 sm:text-3xl dark:text-white">
                    {{ $heading }}
                </h2>
                @if(filled($ctaText))
                    <x-buttons.cta :href="$ctaHref" size="sm">{{ $ctaText }}</x-buttons.cta>
                @endif
            </div>

            @if(filled($subheading))
                <p class="mt-2 max-w-2xl text-base text-zinc-600 dark:text-zinc-400">
                    {{ $subheading }}
                </p>
            @endif

            <div class="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($steps as $step)
                    <div class="group rounded-2xl bg-white p-6 shadow-md ring-1 ring-zinc-900/5 transition hover:shadow-xl dark:bg-zinc-800/75 dark:ring-white/10">
                        <div class="flex size-10 items-center justify-center rounded-full bg-sky-50 text-lg font-bold text-sky-700 dark:bg-sky-500/10 dark:text-sky-400">
                            {{ $step['step'] }}
                        </div>
                        <h3 class="mt-4 text-lg font-semibold text-zinc-900 dark:text-white">{{ $step['title'] }}</h3>
                        <p class="mt-2 text-sm leading-6 text-zinc-600 dark:text-zinc-400">
                            {{ $step['description'] }}
                            {{-- 'link' is data, not HTML, because this string is escaped. --}}
                            @if(!empty($step['link']))
                                <a href="{{ $step['link']['href'] }}" wire:navigate class="font-medium text-sky-700 hover:underline dark:text-sky-400">{{ $step['link']['text'] }}</a>.
                            @endif
                        </p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
@endif
