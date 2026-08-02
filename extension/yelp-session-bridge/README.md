# Yelp Session Bridge

Keeps gs.construction's Yelp automation (photo uploads, review sync) logged in,
without anyone pasting cookies by hand.

## Why this exists

`biz.yelp.com` sits behind DataDome. A scripted login from the server's
datacentre IP gets hard-blocked — solving that challenge needs a residential
proxy plus a paid captcha solver, and it stays an arms race.

Your own browser has none of those problems: you are a real person on a
residential connection. So the server stops trying to log in and instead
borrows the session you already have.

The previous approach — exporting cookies once with Cookie-Editor — failed for
a concrete reason. A cookie jar is a snapshot, and snapshots rot. The
production jar was captured **2026-06-01**; its `biz_session` cookie expired
**2026-07-25**, and from then on every automation run faithfully injected a
dead cookie. The first "NOT authenticated" appeared 2026-07-17. This extension
fixes that by re-sending the session continuously.

## Install

1. Open `chrome://extensions`
2. Turn on **Developer mode** (top right)
3. **Load unpacked** → select this folder
4. Click the extension → **Settings**
5. Paste the token from **Admin → Platforms → Yelp for Business → Browser
   extension**, then Save

## Use

Log in to [biz.yelp.com](https://biz.yelp.com) as normal. That's it.

The extension sends your session:

- **immediately** when it sees a Yelp session cookie change (i.e. you just
  logged in),
- **every 6 hours** afterwards, so the server's copy is refreshed long before
  it can go stale,
- **on demand**, via the popup's *Send session now* button.

The server verifies each hand-off by launching a headless browser with the new
cookies. If it authenticates, any project photos that failed to upload while
the session was down are re-queued automatically.

## What is actually sent

Every `yelp.com` cookie, including `httpOnly` ones. That is the whole point of
an extension rather than a bookmarklet: Yelp's session cookies are `httpOnly`
and completely invisible to page JavaScript.

Cookies go to `POST {serverUrl}/api/yelp/cookies` with a bearer token. Nothing
else is collected, and no other site is touched — `host_permissions` is limited
to `*.yelp.com` and the server.

The POST runs in the extension's service worker, which bypasses CORS for hosts
in `host_permissions` — so there is no preflight and no server-side CORS
configuration.

## If you change the server URL

`host_permissions` in `manifest.json` must include it, or the request fails.
Edit the manifest and reload the extension.

## Security notes

- The token is a bearer credential. Anyone holding it can write the server's
  Yelp cookie jar. Revoke it from the admin page if it leaks.
- The server accepts cookies only for `yelp.com` and its subdomains, matched by
  suffix — `evil-yelp.com.attacker.net` is rejected.
- Cookies are stored `0600` on the server and are never rendered in the admin
  UI.
