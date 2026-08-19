<!DOCTYPE html>
<html lang="en">
{{--
    Rendered by AdminProxyController when ss-systems (the central admin
    this /admin/* proxies to) cannot be reached. Deliberately standalone —
    NOT <x-layouts.app> — so it has no dependency on the admin backend it is
    reporting the absence of, and nothing here can itself fail to render.
--}}
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Admin unavailable — {{ config('brand.display_name', 'J. Peterson Design') }}</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #faf8f5;
            color: #44403c;
            font-family: Georgia, 'Times New Roman', serif;
            padding: 1.5rem;
        }
        .card {
            max-width: 28rem;
            text-align: center;
        }
        .eyebrow {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-size: 0.7rem;
            letter-spacing: 0.3em;
            text-transform: uppercase;
            color: #a8a29e;
            margin: 0 0 0.75rem;
        }
        h1 {
            font-weight: normal;
            font-size: 1.75rem;
            line-height: 1.2;
            margin: 0 0 0.75rem;
            color: #292524;
        }
        p {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-size: 0.95rem;
            line-height: 1.6;
            color: #57534e;
            margin: 0 0 1.5rem;
        }
        a {
            display: inline-block;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-size: 0.85rem;
            letter-spacing: 0.02em;
            color: #fff;
            background: #4e9da2;
            text-decoration: none;
            padding: 0.7rem 1.5rem;
            border-radius: 999px;
        }
        a:hover { background: #3f8388; }
    </style>
</head>
<body>
    <div class="card">
        <p class="eyebrow">Studio Admin</p>
        <h1>Admin is temporarily unavailable</h1>
        <p>
            We could not reach the admin service just now. Nothing on the
            public site is affected — please try again in a few minutes.
        </p>
        <a href="/">Return to the site</a>
    </div>
</body>
</html>
