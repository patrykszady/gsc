<?php

namespace Tests\Feature\Api\Admin\V1\Concerns;

/**
 * Shared setup for /api/admin/v1 tests: a bearer token (never set in
 * phpunit.xml, so config it per-test) plus the header every request needs.
 * PinAdminApiTenant hardcodes the 'gsc' tenant, and that row is seeded by
 * the migrations themselves (see tests/TestCase.php), so no Site factory
 * call is needed here.
 *
 * Moved into the kit (0.11.0) — this file stays at the same
 * namespace/filename so none of this suite's `use Tests\Feature\Api\Admin\
 * V1\Concerns\WithAdminApiAuth;` imports need to change.
 */
trait WithAdminApiAuth
{
    use \SsSystems\Platform\Testing\WithAdminApiAuth;
}
