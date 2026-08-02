<?php

namespace Tests;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

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
}
