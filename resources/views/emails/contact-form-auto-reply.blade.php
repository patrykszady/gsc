<x-mail::message>
# Thanks for reaching out!

Hi {{ $name }},

Thanks for reaching out to {{ config('brand.display_name', config('brand.name')) }}. {{ config('brand.reply_signature', config('brand.name')) }} will be in touch shortly regarding your project.
In the meantime, you are welcome to browse the site to see recent work.

Thank you,  
{{ config('brand.reply_signature', config('brand.name')) }} | {{ config('brand.phone') }}  
<a href="{{ config('app.url') }}">{{ parse_url(config('app.url'), PHP_URL_HOST) }}</a>@if (config('socials.instagram.url')) | <a href="{{ config('socials.instagram.url') }}">Instagram</a>@endif
</x-mail::message>
