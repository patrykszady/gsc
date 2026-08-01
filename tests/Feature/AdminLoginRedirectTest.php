<?php

namespace Tests\Feature;

use App\Livewire\Admin\Login;
use App\Models\User;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Login must not generate a route that needs the {site} parameter.
 *
 * /admin/login sits OUTSIDE the admin/{site} group, so ResolveAdminSite never
 * runs and URL::defaults(['site' => ...]) is never set. Any route('admin.*')
 * call here throws UrlGenerationException — which is exactly what a successful
 * login did, turning a correct password into a 500.
 */
class AdminLoginRedirectTest extends TestCase
{
    public function test_successful_login_redirects_to_the_hub(): void
    {
        $user = User::query()->firstOrCreate(
            ['email' => 'login-redirect-test@example.test'],
            ['name' => 'Login Redirect Test', 'password' => 'secret-Password-1'],
        );

        Livewire::test(Login::class)
            ->set('email', $user->email)
            ->set('password', 'secret-Password-1')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect('/admin');

        $this->assertAuthenticatedAs($user->fresh());

        $user->forceDelete();
    }

    public function test_bad_credentials_do_not_authenticate(): void
    {
        Livewire::test(Login::class)
            ->set('email', 'nobody@example.test')
            ->set('password', 'wrong')
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }
}
