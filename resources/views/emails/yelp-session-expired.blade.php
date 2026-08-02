<x-mail::message>
# Yelp session expired

The Yelp for Business browser session is no longer authenticated, so photo
uploads to Yelp are **paused**.

@if($note)
Detail: {{ $note }}
@endif

To fix it, open the platforms page, click **Verify Login**, and log in to Yelp
in the embedded browser window:

<x-mail::button :url="$platformsUrl">
Open Platforms Settings
</x-mail::button>

Photos that could not upload while the session was down will re-upload
automatically once you're logged back in — nothing else to run.

{{ config('app.name') }}
</x-mail::message>
