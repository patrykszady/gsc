<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Support\Seo\CrawlFiles;
use Illuminate\Console\Command;

/**
 * Write the DEFAULT site's robots.txt to public/ as a static file.
 *
 * Forge's shared nginx config serves /robots.txt from disk only
 * (`location = /robots.txt { access_log off; log_not_found off; }`) and
 * turns a missing file into a 404 whose body Laravel fills in — so the
 * per-site /robots.txt route answers 404 on production until that block
 * also carries `try_files $uri /index.php?$query_string;`. Until then this
 * keeps gs.construction's robots.txt a 200. It is ONLY right for the
 * default site: every other host of the deployment gets this same file
 * from nginx. Remove the post-deploy step once nginx is fixed.
 */
class RobotsPublish extends Command
{
    protected $signature = 'robots:publish';

    protected $description = 'Write the default site\'s robots.txt to public/ (interim, until nginx passes /robots.txt to the app).';

    public function handle(): int
    {
        $default = Site::query()->where('slug', (string) config('sites.default', 'gsc'))->first() ?? Site::current();

        $body = "# STATIC COPY for the default site, written at deploy by `robots:publish`.\n"
            ."# Every other site of this deployment is served by the /robots.txt route; see CrawlFiles.\n"
            .CrawlFiles::robots($default);

        file_put_contents(public_path('robots.txt'), $body);
        $this->info('Wrote public/robots.txt for '.$default->slug.' ('.strlen($body).' bytes).');

        return self::SUCCESS;
    }
}
