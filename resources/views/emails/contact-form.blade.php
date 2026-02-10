<x-mail::message>
# New Consultation Request

You've received a new consultation request from your website.

## Contact Information

**Name:** {{ $name }}  
**Email:** {{ $email }}  
**Phone:** {{ $phone }}  
@if($area)
**Area:** {{ $area }}  
@endif
@if($address)
**Project Address:** {{ $address }}
@endif

## Message

{{ $userMessage }}

@if(count($availability) > 0)
## Preferred Consultation Times

<x-mail::table>
| Date | Time |
|:-----|:-----|
@foreach($availability as $slot)
| {{ \Carbon\Carbon::parse($slot['date'])->format('l, F j, Y') }} | {{ $slot['time'] }} |
@endforeach
</x-mail::table>
@endif

<x-mail::button :url="'mailto:' . $email">
Reply to {{ $name }}
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
