<?php

namespace Tests\Feature\Api\Admin\V1\Concerns;

/**
 * Shared setup for /api/admin/v1 tests: a bearer token (never set in
 * phpunit.xml, so config it per-test) plus the header every request needs.
 * PinAdminApiTenant hardcodes the 'gsc' tenant, and that row is seeded by
 * the migrations themselves (see tests/TestCase.php), so no Site factory
 * call is needed here.
 */
trait WithAdminApiAuth
{
    protected string $adminApiToken = 'test-admin-api-token';

    protected function setUpAdminApiAuth(): void
    {
        config(['services.admin_api.token' => $this->adminApiToken]);
    }

    /** @return array<string, string> */
    protected function adminApiHeaders(): array
    {
        return ['Authorization' => 'Bearer '.$this->adminApiToken];
    }
}
