<?php

namespace Tests\Unit\Support\Seo\Inspection;

use App\Support\Seo\Inspection\FileSitemapSource;
use Tests\TestCase;

/**
 * FileSitemapSource rehosts every sitemap URL onto the Search Console
 * property's own scheme+host before UrlInspectionSweep ever sees it. A dev
 * or staging checkout generates its sitemap under that box's own APP_URL
 * (e.g. http://127.0.0.1:8003/...), and Google's URL Inspection API 403s any
 * URL outside the verified property — sweeping anywhere but production used
 * to burn the day's allowance on refusals and store nothing.
 *
 * gsc's default Search Console property (config/seo.php's
 * search_console.site_url, unset in testing) is already
 * 'sc-domain:gs.construction', so these fixtures need no config override to
 * exercise the mismatch a dev box actually hits.
 */
class FileSitemapSourceTest extends TestCase
{
    private function sitemap(string ...$urls): string
    {
        $path = storage_path('app/file-sitemap-source-test.xml');
        $body = '<?xml version="1.0" encoding="UTF-8"?><urlset>';
        foreach ($urls as $url) {
            $body .= '<url><loc>'.$url.'</loc></url>';
        }
        file_put_contents($path, $body.'</urlset>');

        return $path;
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('app/file-sitemap-source-test.xml'));
        parent::tearDown();
    }

    public function test_every_url_is_rehosted_onto_the_search_console_propertys_own_host(): void
    {
        $sitemap = $this->sitemap('http://127.0.0.1:8003/about?x=1', 'https://gs.construction/services');

        $source = new FileSitemapSource;

        $this->assertSame([
            'https://gs.construction/about?x=1',
            'https://gs.construction/services',
        ], $source->urls($sitemap));
        $this->assertSame('https://gs.construction', $source->baseUrl());
    }

    public function test_a_fragment_and_a_bare_path_survive_the_rehost(): void
    {
        $sitemap = $this->sitemap('http://127.0.0.1:8003/projects#top', 'http://127.0.0.1:8003/');

        $source = new FileSitemapSource;

        $this->assertSame([
            'https://gs.construction/projects#top',
            'https://gs.construction/',
        ], $source->urls($sitemap));
    }
}
