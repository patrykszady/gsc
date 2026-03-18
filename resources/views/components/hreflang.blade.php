{{-- 
    Hreflang tags for bilingual support (English primary, Polish alternate)
    Only output if there's an actual alternate-domain Polish version.
    When both languages share the same URL, hreflang tags should be omitted
    to avoid confusing search engines.
--}}
@if(view()->shared('isAlternateDomain', false) || config('services.domains.polish'))
<link rel="alternate" hreflang="en" href="{{ url()->current() }}" />
@if(config('services.domains.polish'))
<link rel="alternate" hreflang="pl" href="{{ str_replace(request()->getHost(), config('services.domains.polish'), url()->current()) }}" />
@endif
<link rel="alternate" hreflang="x-default" href="{{ url()->current() }}" />
@endif
