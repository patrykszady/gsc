<x-mail::message>
# Yelp session expired

The Yelp for Business browser session is no longer authenticated, so new Yelp
leads are **not being pulled in** and photo uploads to Yelp are **paused**.

@if($note)
Detail: {{ $note }}
@endif

To fix it, open the platforms page, click **Verify Login**, and log in to Yelp
in the embedded browser window:

<x-mail::button :url="$platformsUrl">
Open Platforms Settings
</x-mail::button>

Leads and photos that were missed while the session was down are picked up
automatically once you're logged back in — nothing else to run. This reminder
repeats once a day until then.

{{ config('app.name') }}
</x-mail::message>
