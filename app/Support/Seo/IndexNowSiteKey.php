<?php

namespace App\Support\Seo;

use App\Models\PlatformSetting;
use App\Models\Site;
use SsSystems\Platform\Seo\IndexNow\Contracts\IndexNowKey;
use SsSystems\Platform\Seo\IndexNow\Contracts\PeeksIndexNowKey;

/**
 * This site's IndexNow key and key file — bound to the kit's IndexNowKey
 * contract (0.19.4), read by seo:indexnow's scheduled sweep, the ad hoc
 * single/batch pings in App\Support\Seo\IndexNowSubmitter, and the
 * /{key}.txt route (SsSystems\Platform\Seo\IndexNow\Http\IndexNowKeyController).
 *
 * key(): an operator-supplied key (services.indexnow.key / env INDEXNOW_KEY)
 * wins when present, the same admin-override-first pattern BingSettings and
 * PsiSettings already follow. Otherwise a key is generated the first time
 * anything asks for one — 32 lowercase hex characters via random_bytes(),
 * well inside IndexNowKey's documented 8-128 char [a-z0-9-] shape — and
 * persisted through PlatformSetting::put(), which (like every other stored
 * credential in this app) is scoped to Site::current() by PlatformSetting's
 * own BelongsToSite trait. No admin step is required before IndexNow starts
 * working: unlike Bing/DataForSEO/Clarity, this key is not an account
 * credential issued by a third party — it is simply a token the site invents
 * and then proves it controls by serving it back at keyLocation().
 *
 * keyLocation(): the CURRENT tenant's own base URL, never config('app.url')
 * directly — this app is multi-tenant, and IndexNow/Bing fetch this URL to
 * verify the submitting host, so another tenant's key file must resolve on
 * ITS OWN host.
 */
final class IndexNowSiteKey implements IndexNowKey, PeeksIndexNowKey
{
    public const SETTING_KEY = 'seo.indexnow.key';

    public function key(): ?string
    {
        $existing = $this->existingKey();
        if ($existing !== null) {
            return $existing;
        }

        $generated = bin2hex(random_bytes(16));
        PlatformSetting::put(self::SETTING_KEY, $generated);

        return $generated;
    }

    /**
     * The current tenant's key as it stands — the operator's, else the
     * stored one — or null when none has been made yet. Reads only: this is
     * what `GET seo/status` asks (kit PeeksIndexNowKey), so that finding
     * out whether IndexNow is set up for a tenant never sets it up.
     */
    public function existingKey(): ?string
    {
        $configured = trim((string) config('services.indexnow.key', ''));
        if ($configured !== '') {
            return $configured;
        }

        $stored = PlatformSetting::get(self::SETTING_KEY);

        return is_string($stored) && trim($stored) !== '' ? trim($stored) : null;
    }

    public function keyLocation(): string
    {
        return rtrim(Site::current()->url(), '/').'/'.$this->key().'.txt';
    }
}
