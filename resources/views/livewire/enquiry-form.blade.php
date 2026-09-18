{{--
    The enquiry form's markup is deliberately class-light: the tenant's theme
    sets the look through its own CSS variables, and this form is used by a
    site whose styling is not gs.construction's. See App\Livewire\EnquiryForm.
--}}
<div>
    @if ($sent)
        <div class="rounded-sm border border-brand-200 bg-brand-50 px-5 py-4 text-sm text-stone-700">
            <p class="font-medium text-ink">Thank you — your message is on its way.</p>
            <p class="mt-1">We reply to every enquiry personally, usually within a working day.</p>
        </div>
    @else
        <form wire:submit="submit" class="space-y-5">
            {{-- Honeypot: off-screen, never tabbable, never autofilled. --}}
            <div class="absolute left-[-9999px]" aria-hidden="true">
                <label>
                    Website
                    <input type="text" wire:model="website" tabindex="-1" autocomplete="off" />
                </label>
            </div>

            <label class="block">
                <span class="text-xs uppercase tracking-[0.18em] text-stone-500">Name</span>
                <input type="text" wire:model.blur="name" autocomplete="name" required
                       class="mt-2 w-full rounded-sm border border-stone-300 bg-white/60 px-4 py-3 text-sm text-stone-800 placeholder:text-stone-400" />
                @error('name') <span class="mt-1 block text-xs text-red-700">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-xs uppercase tracking-[0.18em] text-stone-500">Email</span>
                <input type="email" wire:model.blur="email" autocomplete="email" required
                       class="mt-2 w-full rounded-sm border border-stone-300 bg-white/60 px-4 py-3 text-sm text-stone-800 placeholder:text-stone-400" />
                @error('email') <span class="mt-1 block text-xs text-red-700">{{ $message }}</span> @enderror
            </label>

            <label class="block">
                <span class="text-xs uppercase tracking-[0.18em] text-stone-500">Phone <span class="normal-case tracking-normal text-stone-400">(optional)</span></span>
                <input type="tel" wire:model.blur="phone" autocomplete="tel"
                       class="mt-2 w-full rounded-sm border border-stone-300 bg-white/60 px-4 py-3 text-sm text-stone-800 placeholder:text-stone-400" />
                @error('phone') <span class="mt-1 block text-xs text-red-700">{{ $message }}</span> @enderror
            </label>

            @if (! empty($markets))
                <label class="block">
                    <span class="text-xs uppercase tracking-[0.18em] text-stone-500">Where is the project?</span>
                    <select wire:model.blur="market"
                            class="mt-2 w-full rounded-sm border border-stone-300 bg-white/60 px-4 py-3 text-sm text-stone-800">
                        <option value="">Somewhere else</option>
                        @foreach ($markets as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('market') <span class="mt-1 block text-xs text-red-700">{{ $message }}</span> @enderror
                </label>
            @endif

            <label class="block">
                <span class="text-xs uppercase tracking-[0.18em] text-stone-500">About your project</span>
                <textarea rows="5" wire:model.blur="message" required
                          class="mt-2 w-full rounded-sm border border-stone-300 bg-white/60 px-4 py-3 text-sm text-stone-800 placeholder:text-stone-400"></textarea>
                @error('message') <span class="mt-1 block text-xs text-red-700">{{ $message }}</span> @enderror
            </label>

            <button type="submit" wire:loading.attr="disabled"
                    class="rounded-full bg-brand-600 px-8 py-3.5 text-sm tracking-wide text-white transition hover:bg-brand-700 disabled:opacity-60">
                <span wire:loading.remove wire:target="submit">Send</span>
                <span wire:loading wire:target="submit">Sending…</span>
            </button>
        </form>
    @endif
</div>
