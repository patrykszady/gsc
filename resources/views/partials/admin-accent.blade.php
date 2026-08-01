{{--
    Per-tenant accent for the admin surfaces (admin layout + guest layout,
    i.e. the login page and the site picker).

    Every accent utility in the admin views — bg-sky-100, text-sky-600,
    ring-sky-500 and ~100 others — compiles to var(--color-sky-N) under
    Tailwind v4. resources/css/app.css then derives Flux's accent from the
    same ramp (--color-accent: var(--color-sky-500)), so redefining these
    variables recolours BOTH the hand-written admin UI and every Flux
    component for this tenant, with no view changes and no per-tenant bundle.

    Ramps live in config/sites/{slug}/admin.php and are contrast-checked
    there. No accent configured = Tailwind's default sky, so gs.construction
    is byte-identical to before.

    Included by both layouts rather than pasted into each: they must not drift.
--}}
@if ($accent = config('admin.accent'))
    <style>
        :root {
            @foreach ($accent as $step => $hex)
                --color-sky-{{ $step }}: {{ $hex }};
            @endforeach
        }
    </style>
@endif
