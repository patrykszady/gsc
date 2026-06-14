<x-mail::message>
# New Careers / Partnership Inquiry

You've received a new inquiry from your website's Careers page.

## Contact Information

**Name:** {{ $name }}  
**Email:** {{ $email }}  
@if($phone)
**Phone:** {{ $phone }}  
@endif
**Interested as:** {{ $applicantTypeLabel }}  
@if($company)
**Company:** {{ $company }}  
@endif
@if($trade)
**Trade / Specialty:** {{ $trade }}  
@endif
@if($languages)
**Languages:** {{ $languages }}  
@endif
@if($website)
**Website / Portfolio:** {{ $website }}
@endif

@if($userMessage)
## Message

{{ $userMessage }}
@endif

<x-mail::button :url="'mailto:' . $email">
Reply to {{ $name }}
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
