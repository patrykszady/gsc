<x-mail::message>
# Thanks for reaching out!

Hi {{ $name }},

Thanks for reaching out to GS Construction. GS Crew will be in touch shortly regarding your project.
In the meantime, feel free to browse our website to view our recent projects and homeowner reviews.

Thank you,  
GS Crew | (224) 735-4200  
<a href="{{ config('app.url') }}">{{ parse_url(config('app.url'), PHP_URL_HOST) }}</a> | <a href="{{ config('socials.instagram.url') }}">Instagram</a>
</x-mail::message>
