<x-layouts.app title="Contact — J. Peterson Design">

    {{-- TODO: photography supplied by Jenn — placeholder slides until then.
         No overlay text: this page already owns its single H1. --}}
    <x-hero-carousel
        :slides="\App\Support\HeroSlides::placeholders(['Recent project', 'Interior detail', 'Finished space'])"
        container-classes="mx-auto max-w-6xl px-6 pt-16"
        rounded-classes="rounded-sm border border-stone-200"
        height-classes="aspect-16/7"
    />

    <section class="mx-auto grid max-w-6xl gap-14 px-6 py-16 md:grid-cols-2">
        <div>
            <p class="text-xs uppercase tracking-[0.3em] text-stone-400">Contact</p>
            <h1 class="mt-5 font-heading text-4xl text-ink sm:text-5xl">Let&rsquo;s talk about your space</h1>
            <p class="mt-6 max-w-md leading-relaxed text-stone-600">
                {{-- TODO: Jenn's preferred enquiry note + response expectation --}}
                Placeholder: a short note on how to get in touch and what happens after you do.
            </p>
            <ul class="mt-9 space-y-2 text-sm text-stone-700">
                @if (config('brand.email'))
                    <li><a href="mailto:{{ config('brand.email') }}" class="underline underline-offset-4 transition hover:text-brand-700">{{ config('brand.email') }}</a></li>
                @endif
                @if (config('brand.phone'))
                    <li><a href="tel:{{ config('brand.phone_href') }}" class="transition hover:text-brand-700">{{ config('brand.phone') }}</a></li>
                @endif
                <li class="pt-3 text-stone-500">{{ collect(config('markets.list', []))->pluck('label')->implode(' · ') }}</li>
            </ul>
        </div>

        {{-- TODO: wire to a Livewire form + per-site lead routing (audit item:
             leads currently mail the global MAIL_FROM address). Static until then. --}}
        <form class="space-y-5" onsubmit="return false">
            @foreach ([['Name', 'text'], ['Email', 'email'], ['Phone', 'tel']] as [$label, $type])
                <label class="block">
                    <span class="text-xs uppercase tracking-[0.18em] text-stone-500">{{ $label }}</span>
                    <input type="{{ $type }}" disabled placeholder="Coming soon"
                           class="mt-2 w-full rounded-sm border border-stone-300 bg-white/60 px-4 py-3 text-sm text-stone-800 placeholder:text-stone-400" />
                </label>
            @endforeach
            <label class="block">
                <span class="text-xs uppercase tracking-[0.18em] text-stone-500">About your project</span>
                <textarea rows="5" disabled placeholder="Coming soon"
                          class="mt-2 w-full rounded-sm border border-stone-300 bg-white/60 px-4 py-3 text-sm text-stone-800 placeholder:text-stone-400"></textarea>
            </label>
            <button type="button" disabled class="cursor-not-allowed rounded-full bg-stone-400 px-8 py-3.5 text-sm tracking-wide text-white">
                Form coming soon — email us instead
            </button>
        </form>
    </section>
</x-layouts.app>
