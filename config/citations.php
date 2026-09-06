<?php

/*
|--------------------------------------------------------------------------
| Citations — business profiles and directory listings we build ourselves
|--------------------------------------------------------------------------
| The citation builder opens each directory in a headed Chromium on the
| server's virtual display, prefills the listing from the canonical payload
| (App\Support\Citations\ListingPayload), uploads our photos where the site
| takes them, and hands the browser to the admin (noVNC) for whatever a
| human must do: CAPTCHA, account verification, the final Submit. Status
| and screenshots land in the `citations` table; the weekly link check
| (backlinks intelligence) confirms each live listing still links to us.
*/

return [

    // Remote browser session. Defaults share the Yelp remote-login slot
    // (display, ports, public noVNC path), so one remote session runs at a time.
    'session' => [
        'display' => env('CITATIONS_REMOTE_DISPLAY', env('YELP_REMOTE_LOGIN_DISPLAY', ':99')),
        'screen' => env('CITATIONS_REMOTE_SCREEN', '1366x900x24'),
        'vnc_port' => (int) env('CITATIONS_REMOTE_VNC_PORT', env('YELP_REMOTE_LOGIN_VNC_PORT', 5999)),
        'ws_host' => env('CITATIONS_REMOTE_WS_HOST', env('YELP_REMOTE_LOGIN_WS_HOST', '0.0.0.0')),
        'ws_port' => (int) env('CITATIONS_REMOTE_WS_PORT', env('YELP_REMOTE_LOGIN_WS_PORT', 6080)),
        'public_url' => env('CITATIONS_REMOTE_PUBLIC_URL', env('YELP_REMOTE_LOGIN_PUBLIC_URL')),
        'novnc_web' => env('CITATIONS_REMOTE_NOVNC_WEB', env('YELP_REMOTE_LOGIN_NOVNC_WEB', '/usr/share/novnc')),
        'xvfb_binary' => env('YELP_REMOTE_LOGIN_XVFB', 'Xvfb'),
        'x11vnc_binary' => env('YELP_REMOTE_LOGIN_X11VNC', 'x11vnc'),
        'websockify_binary' => env('YELP_REMOTE_LOGIN_WEBSOCKIFY', 'websockify'),
        'node_binary' => env('CITATIONS_NODE_BINARY', env('YELP_NODE_BINARY', 'node')),
        'max_ttl_seconds' => (int) env('CITATIONS_REMOTE_MAX_TTL', 2700),
        'auto_ttl_seconds' => (int) env('CITATIONS_AUTO_TTL', 300), // one directory in automatic (batch) mode
        'user_data_dir' => env('CITATIONS_USER_DATA_DIR', storage_path('app/citations/profile')),
    ],

    // Where payloads, runner state and screenshots live (one folder per directory).
    'storage_dir' => env('CITATIONS_STORAGE_DIR', storage_path('app/citations')),

    // Mailbox the directories send their verification emails to. It lives on
    // Microsoft 365, which no longer accepts password IMAP, so the reader uses
    // Microsoft Graph with an Entra app registration (application permission
    // Mail.Read, admin-consented). Until CITATIONS_M365_* are set, email
    // verification is a human step in the admin. The IMAP block below stays
    // for tenants whose mailbox is elsewhere.
    'inbox' => [
        'mailbox' => env('CITATIONS_MAILBOX', env('CITATIONS_IMAP_USER', 'crew@gs.construction')),
        'graph' => [
            'tenant_id' => env('CITATIONS_M365_TENANT_ID'),
            'client_id' => env('CITATIONS_M365_CLIENT_ID'),
            'client_secret' => env('CITATIONS_M365_CLIENT_SECRET'),
        ],
        'host' => env('CITATIONS_IMAP_HOST', 'imap.gmail.com'),
        'port' => (int) env('CITATIONS_IMAP_PORT', 993),
        'user' => env('CITATIONS_IMAP_USER', 'crew@gs.construction'),
        'password' => env('CITATIONS_IMAP_PASSWORD'),
        'folder' => env('CITATIONS_IMAP_FOLDER', 'INBOX'),
        'lookback_days' => 7,
    ],

    'photos' => [
        'max' => 40,          // most a directory will get
        'per_project' => 3,   // spread across projects, featured first
        'min_width' => 800,
    ],

    // The person a directory asks for as the account holder.
    'contact' => [
        'first_name' => env('CITATIONS_CONTACT_FIRST', 'Patryk'),
        'last_name' => env('CITATIONS_CONTACT_LAST', 'Szady'),
        'title' => 'Owner',
    ],

    /*
    | Directory registry. Keys are the citation slugs.
    |   tier       0 profile exists (complete it), 1 established platform,
    |              2 vetted directory from the link gap, 3 requested but no
    |              real listing mechanism (attempted anyway, outcome recorded)
    |   mechanism  form | account | claim | api | partner | none | dead | farm
    |   needs      what a human will likely be asked for
    |   start_url  where the browser opens (homepage when unknown — the
    |              adapter then hunts for the "add your business" link)
    |   hints      per-site selector/flow overrides for the generic adapter
    */
    'directories' => [

        // ---- Tier 0: profiles that exist — add the website link and photos
        'houzz' => ['name' => 'Houzz', 'tier' => 0, 'mechanism' => 'account', 'homepage' => 'https://www.houzz.com/', 'start_url' => 'https://www.houzz.com/pro/', 'needs' => ['account', 'photos'], 'photos' => true, 'note' => 'Profile exists (4.9★). Log in as the pro, add project photos and confirm the website link.'],
        'yelp' => ['name' => 'Yelp', 'tier' => 0, 'mechanism' => 'account', 'homepage' => 'https://www.yelp.com/', 'start_url' => 'https://biz.yelp.com/', 'needs' => ['account', 'photos'], 'photos' => true, 'note' => 'Profile exists. Business login; photos and website link on the business profile.'],
        'bbb' => ['name' => 'Better Business Bureau', 'tier' => 0, 'mechanism' => 'claim', 'homepage' => 'https://www.bbb.org/', 'start_url' => 'https://www.bbb.org/us/il/prospect-heights/profile/general-contractor/gs-construction-remodeling-inc-0654-88701450', 'needs' => ['email'], 'photos' => false, 'note' => 'A+ profile exists, not accredited. Confirm the website field is filled (the profile check could not read it through the bot wall).'],
        'angi' => ['name' => 'Angi', 'tier' => 0, 'mechanism' => 'claim', 'homepage' => 'https://www.angi.com/', 'start_url' => 'https://www.angi.com/companylist/us/il/chicagoland/gs-construction-and-remodeling-reviews-11400361.htm', 'needs' => ['account', 'phone'], 'photos' => true, 'note' => 'Review profile exists; claim it through the business owner link and add website + photos. Decline Angi Ads.'],
        'nextdoor' => ['name' => 'Nextdoor', 'tier' => 0, 'mechanism' => 'claim', 'homepage' => 'https://nextdoor.com/', 'start_url' => 'https://business.nextdoor.com/', 'needs' => ['account', 'phone'], 'photos' => true, 'note' => 'Page exists but has no website link. Claim the business page and add the website.'],
        'buildzoom' => ['name' => 'BuildZoom', 'tier' => 0, 'mechanism' => 'claim', 'homepage' => 'https://www.buildzoom.com/', 'start_url' => 'https://www.buildzoom.com/contractor/gs-construction-remodeling-prospect-heights-il', 'needs' => ['email', 'phone'], 'photos' => true, 'note' => 'Licence-based profile exists without a website. Claim it and add the website and photos.'],
        'facebook' => ['name' => 'Facebook page', 'tier' => 0, 'mechanism' => 'account', 'homepage' => 'https://www.facebook.com/', 'start_url' => 'https://www.facebook.com/gs.construction.chi/about', 'needs' => ['account'], 'photos' => true, 'note' => 'Page exists. Confirm website, hours, address and services in About.'],
        'yellowpages' => ['name' => 'Yellow Pages', 'tier' => 0, 'mechanism' => 'claim', 'homepage' => 'https://www.yellowpages.com/', 'start_url' => 'https://www.yellowpages.com/search?search_terms=GS+Construction+%26+Remodeling&geo_location_terms=Prospect+Heights%2C+IL', 'needs' => ['email', 'phone'], 'photos' => true, 'note' => 'Listing exists (it already links to us). Claim it to add photos and hours.'],
        'mapquest' => ['name' => 'MapQuest', 'tier' => 0, 'mechanism' => 'partner', 'homepage' => 'https://www.mapquest.com/', 'start_url' => 'https://www.mapquest.com/us/illinois/gs-construction-743191322', 'needs' => [], 'photos' => false, 'note' => 'Listing exists; MapQuest is fed by data partners, no self-serve edits.'],

        // ---- Tier 1: established platforms we are not on yet
        'bing_places' => ['name' => 'Bing Places', 'tier' => 1, 'mechanism' => 'account', 'homepage' => 'https://www.bingplaces.com/', 'start_url' => 'https://www.bingplaces.com/', 'needs' => ['account', 'phone'], 'photos' => true, 'note' => 'Choose "Import from Google Business Profile" — it copies the whole GBP listing including photos.'],
        'apple_business_connect' => ['name' => 'Apple Business Connect', 'tier' => 1, 'mechanism' => 'account', 'homepage' => 'https://businessconnect.apple.com/', 'start_url' => 'https://businessconnect.apple.com/', 'needs' => ['account', 'phone'], 'photos' => true, 'note' => 'Apple ID needed. Apple Maps listing with photos and hours.'],
        'thumbtack' => ['name' => 'Thumbtack', 'tier' => 1, 'mechanism' => 'account', 'homepage' => 'https://www.thumbtack.com/', 'start_url' => 'https://www.thumbtack.com/pro/', 'needs' => ['account', 'phone'], 'photos' => true, 'note' => 'Free pro profile; decline paid leads.'],
        'porch' => ['name' => 'Porch', 'tier' => 1, 'mechanism' => 'claim', 'homepage' => 'https://porch.com/', 'start_url' => 'https://pro.porch.com/', 'needs' => ['account', 'phone'], 'photos' => true, 'note' => 'Porch already lists us as "unscreened" on its category pages; claim the pro profile.'],
        'homeadvisor' => ['name' => 'HomeAdvisor', 'tier' => 1, 'mechanism' => 'account', 'homepage' => 'https://www.homeadvisor.com/', 'start_url' => 'https://pro.homeadvisor.com/', 'needs' => ['account', 'phone'], 'photos' => true, 'note' => 'Same company as Angi. Free profile; decline the lead subscription.'],
        'manta' => ['name' => 'Manta', 'tier' => 1, 'mechanism' => 'form', 'homepage' => 'https://www.manta.com/', 'start_url' => 'https://www.manta.com/', 'needs' => ['email'], 'photos' => true],
        'foursquare' => ['name' => 'Foursquare', 'tier' => 1, 'mechanism' => 'claim', 'homepage' => 'https://foursquare.com/', 'start_url' => 'https://business.foursquare.com/', 'needs' => ['account', 'phone'], 'photos' => true],
        'hotfrog' => ['name' => 'Hotfrog', 'tier' => 1, 'mechanism' => 'form', 'homepage' => 'https://www.hotfrog.com/', 'start_url' => 'https://www.hotfrog.com/', 'needs' => ['email'], 'photos' => true],
        'cylex' => ['name' => 'Cylex', 'tier' => 1, 'mechanism' => 'form', 'homepage' => 'https://www.cylex.us.com/', 'start_url' => 'https://www.cylex.us.com/', 'needs' => ['email'], 'photos' => true],
        'brownbook' => ['name' => 'Brownbook', 'tier' => 1, 'mechanism' => 'form', 'homepage' => 'https://www.brownbook.net/', 'start_url' => 'https://www.brownbook.net/', 'needs' => ['email'], 'photos' => true],
        'chamberofcommerce' => ['name' => 'ChamberofCommerce.com', 'tier' => 1, 'mechanism' => 'form', 'homepage' => 'https://www.chamberofcommerce.com/', 'start_url' => 'https://www.chamberofcommerce.com/members/add-business', 'needs' => ['email'], 'photos' => true],
        'superpages' => ['name' => 'Superpages', 'tier' => 1, 'mechanism' => 'claim', 'homepage' => 'https://www.superpages.com/', 'start_url' => 'https://www.superpages.com/', 'needs' => ['email', 'phone'], 'photos' => true],

        // ---- Tier 2: link-gap directories with a real free listing form
        'remodelersup' => ['name' => 'RemodelersUp', 'tier' => 2, 'mechanism' => 'form', 'homepage' => 'https://remodelersup.com/', 'start_url' => 'https://remodelersup.com/signup', 'needs' => ['email', 'payment'], 'photos' => true, 'free' => false, 'note' => 'Charges a one-time $19.95 listing fee (seen on the signup form). Pay only if you want this one.'],
        'excellentcontractor' => ['name' => 'Excellent Contractor', 'tier' => 2, 'mechanism' => 'form', 'homepage' => 'https://excellentcontractor.com/', 'start_url' => 'https://excellentcontractor.com/signup', 'needs' => ['email'], 'photos' => true, 'note' => 'Same template network as Reputable Businesses.'],
        'reputablebusinesses' => ['name' => 'Reputable Businesses', 'tier' => 2, 'mechanism' => 'form', 'homepage' => 'https://reputablebusinesses.com/', 'start_url' => 'https://reputablebusinesses.com/signup', 'needs' => ['email'], 'photos' => true],
        'handyhubb' => ['name' => 'HandyHubb', 'tier' => 2, 'mechanism' => 'account', 'homepage' => 'https://handyhubb.com/', 'start_url' => 'https://handyhubb.com/register', 'needs' => ['email'], 'photos' => true],
        'thebuildermarket' => ['name' => 'The Builder Market', 'tier' => 2, 'mechanism' => 'account', 'homepage' => 'https://thebuildermarket.com/', 'start_url' => 'https://thebuildermarket.com/signup', 'needs' => ['email'], 'photos' => true],
        'bestremodel' => ['name' => 'Best Remodel', 'tier' => 2, 'mechanism' => 'account', 'homepage' => 'https://bestremodel.com/', 'start_url' => 'https://bestremodel.com/pages/join-contractor.php', 'needs' => ['email'], 'photos' => true],
        'prosgrade' => ['name' => 'ProsGrade', 'tier' => 2, 'mechanism' => 'account', 'homepage' => 'https://prosgrade.com/', 'start_url' => 'https://prosgrade.com/access/register?next=add-or-claim', 'needs' => ['email'], 'photos' => true, 'note' => 'Has paid tiers — free listing only.'],
        'contract_city' => ['name' => 'Contract City', 'tier' => 2, 'mechanism' => 'account', 'homepage' => 'https://contract.city/', 'start_url' => 'https://contract.city/add-business', 'needs' => ['email'], 'photos' => true, 'note' => 'Has paid tiers — free listing only.'],

        // ---- Tier 3: on the link-gap list, but no real listing mechanism was found.
        // Attempted on request; the run records what it actually finds.
        'zermit' => ['name' => 'Zermit', 'tier' => 3, 'mechanism' => 'none', 'homepage' => 'https://zermit.ai/', 'start_url' => 'https://zermit.ai/', 'needs' => [], 'photos' => false, 'note' => 'Permit software, not a directory.'],
        'kitchenremodelingranked' => ['name' => 'Kitchen Remodeling Ranked', 'tier' => 3, 'mechanism' => 'none', 'homepage' => 'https://kitchenremodelingranked.com/', 'start_url' => 'https://kitchenremodelingranked.com/', 'needs' => [], 'photos' => false, 'note' => 'Editorial ranking list, no submission form found.'],
        'hqremodelingchicago' => ['name' => 'HQ Remodeling Chicago', 'tier' => 3, 'mechanism' => 'none', 'homepage' => 'https://hqremodelingchicago.com/', 'start_url' => 'https://hqremodelingchicago.com/', 'needs' => [], 'photos' => false, 'note' => 'A competitor\'s own site.'],
        'imagetou' => ['name' => 'Imagetou', 'tier' => 3, 'mechanism' => 'none', 'homepage' => 'https://imagetou.com/', 'start_url' => 'https://imagetou.com/', 'needs' => [], 'photos' => false, 'note' => 'Image site, no business listings.'],
        'lantern' => ['name' => 'lantern.llc', 'tier' => 3, 'mechanism' => 'dead', 'homepage' => 'https://lantern.llc/', 'start_url' => 'https://lantern.llc/', 'needs' => [], 'photos' => false, 'note' => 'Blank page.'],
        'kitchlify' => ['name' => 'kitchlify.com', 'tier' => 3, 'mechanism' => 'dead', 'homepage' => 'https://kitchlify.com/', 'start_url' => 'https://kitchlify.com/', 'needs' => [], 'photos' => false, 'note' => 'Does not resolve.'],
        'homeservicelookup' => ['name' => 'homeservicelookup.com', 'tier' => 3, 'mechanism' => 'dead', 'homepage' => 'https://homeservicelookup.com/', 'start_url' => 'https://homeservicelookup.com/', 'needs' => [], 'photos' => false, 'note' => 'Does not resolve.'],
        'renovation_reviews' => ['name' => 'renovation.reviews', 'tier' => 3, 'mechanism' => 'dead', 'homepage' => 'https://renovation.reviews/', 'start_url' => 'https://renovation.reviews/', 'needs' => [], 'photos' => false, 'note' => 'Does not resolve.'],
        'cacaolandbmt' => ['name' => 'cacaolandbmt.com', 'tier' => 3, 'mechanism' => 'dead', 'homepage' => 'https://cacaolandbmt.com/', 'start_url' => 'https://cacaolandbmt.com/', 'needs' => [], 'photos' => false, 'note' => 'Does not resolve.'],
        'xedulichdaklak' => ['name' => 'xedulichdaklak.com', 'tier' => 3, 'mechanism' => 'farm', 'homepage' => 'https://xedulichdaklak.com/', 'start_url' => 'https://xedulichdaklak.com/', 'needs' => [], 'photos' => false, 'note' => 'Vietnamese travel site; the competitor links here are paid or spam.'],
        'sisgroup' => ['name' => 'sisgroup.lk', 'tier' => 3, 'mechanism' => 'farm', 'homepage' => 'https://sisgroup.lk/', 'start_url' => 'https://sisgroup.lk/', 'needs' => [], 'photos' => false, 'note' => 'Sri Lankan waste-management site; link farm.'],
        'piscatore' => ['name' => 'piscatore.dk', 'tier' => 3, 'mechanism' => 'farm', 'homepage' => 'https://piscatore.dk/', 'start_url' => 'https://piscatore.dk/', 'needs' => [], 'photos' => false, 'note' => 'Danish shop; its "register" is a customer account, not a listing.'],
    ],

];
