<?php

namespace Tests;

use App\Models\Site;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\ParallelTesting;

abstract class TestCase extends BaseTestCase
{
    /**
     * Migrate the :memory: SQLite database before any test that touches it.
     *
     * The Feature suite queries real tables (sites, areas_served, projects...),
     * but nothing ever migrated the phpunit database — so 26 tests failed on
     * "no such table: sites" and the DB-dependent ones skipped themselves.
     * The sites rows the tenant tests expect are inserted BY the migrations
     * (create_sites_table seeds gsc; seed_additional_sites seeds ss and
     * jpeterson), so migrating is also seeding for them.
     *
     * Lazily, so pure unit tests keep paying nothing.
     */
    use LazilyRefreshDatabase;

    /**
     * Site::active() memoizes its query in a `protected static` property that
     * lives for the whole PHPUnit process — RefreshDatabase/LazilyRefreshDatabase
     * only roll back the SQL transaction, they never touch PHP statics. A test
     * that flips is_active, calls Site::forgetActive(), and then makes a real
     * request/artisan call that resolves a site (AdminProxyController,
     * PerSiteSearchConsole's robots/sitemap routes, ...) repopulates that
     * static from inside its own still-open transaction. Nothing re-nulls it
     * afterwards, so the cache is left holding a row that only ever existed
     * for the rolled-back transaction — poisoning every later test's
     * Site::active()/forHost() for the rest of the process (e.g.
     * DevHostPreviewTest and AiTrafficTrackingTest failing only when run
     * after AdminProxyDownPageTest, never alone).
     *
     * Resetting it here, once, after every test closes that class of leak at
     * the source instead of patching each polluting test individually.
     */
    /**
     * Give this worker its own crawl-file directory.
     *
     * Sitemaps are written to a real path under storage/, which every paratest
     * worker shares. Two workers generating the same site's sitemap overwrote
     * each other, and the reader saw the other's file — one test adding a town
     * and then failing to find it in "its" sitemap, only when run in parallel.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $token = (string) (ParallelTesting::token() ?: '');

        config(['seo.crawl_files_root' => storage_path('framework/testing/tenants'.($token !== '' ? '_test_'.$token : ''))]);
    }

    protected function tearDown(): void
    {
        Site::forgetActive();
        Site::forgetListAll();

        parent::tearDown();
    }
}
