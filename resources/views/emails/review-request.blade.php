<x-mail::message>
# Thank you for trusting us with your home!

Hi {{ $name ? \Illuminate\Support\Str::of($name)->before(' ') : 'there' }},

Now that your {{ $projectTitle ? \Illuminate\Support\Str::lower($projectTitle) : 'project' }}{{ $location ? ' in ' . $location : '' }} is wrapped up, we hope you're loving the result as much as we loved building it.

If you have a minute, would you share your experience in a quick Google review? It's the single biggest way you can help our small family business — most of our new neighbors find us through reviews from homeowners like you.

<x-mail::button :url="$reviewUrl">
Leave a Google Review
</x-mail::button>

It only takes a minute, and every word helps.

Thank you again — and if anything ever needs a follow-up visit, just reply to this email or call us anytime.

Greg & Patryk
GS Construction | (224) 735-4200
<a href="{{ config('app.url') }}">{{ parse_url(config('app.url'), PHP_URL_HOST) }}</a>
</x-mail::message>
