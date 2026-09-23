<?php

namespace App\Services;

use App\Support\Seo\BingSettings;
use Illuminate\Http\Client\Factory;
use SsSystems\Platform\Seo\Bing\BingWebmasterApi;
use SsSystems\Platform\Seo\Bing\BingWebmasterClient;

/**
 * This site's Bing Webmaster Tools client: the kit's BingWebmasterApi built
 * from this site's key (admin-saved, through BingSettings) and property.
 * The two calls and Bing's date format used to live here; they are the
 * kit's now (0.8.0, 2026-09-23), shared with jpeterson-design. The old
 * method names stay as aliases.
 */
class BingWebmasterService implements BingWebmasterClient
{
    private BingWebmasterApi $api;

    public function __construct(BingSettings $settings, Factory $http)
    {
        $this->api = new BingWebmasterApi($settings->apiKey(), $settings->siteUrl(), $http);
    }

    public function isConfigured(): bool
    {
        return $this->api->isConfigured();
    }

    public function siteUrl(): string
    {
        return $this->api->siteUrl();
    }

    public function queryStats(): ?array
    {
        return $this->api->queryStats();
    }

    public function rankAndTrafficStats(): ?array
    {
        return $this->api->rankAndTrafficStats();
    }

    public function lastError(): ?string
    {
        return $this->api->lastError();
    }

    /** @deprecated the kit's name is queryStats() */
    public function fetchQueryStats(): ?array
    {
        return $this->queryStats();
    }

    /** @deprecated the kit's name is rankAndTrafficStats() */
    public function fetchRankAndTrafficStats(): ?array
    {
        return $this->rankAndTrafficStats();
    }
}
