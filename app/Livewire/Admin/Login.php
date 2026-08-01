<?php

namespace App\Livewire\Admin;

use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('components.layouts.guest')]
#[Title('Admin Login')]
class Login extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required')]
    public string $password = '';

    public bool $remember = false;

    public function login(): void
    {
        $this->validate();

        if (Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            session()->regenerate();

            // The hub, NOT route('admin.dashboard'). That route is admin/{site},
            // and the {site} default is filled by ResolveAdminSite, which only
            // runs inside the admin/{site} group — /admin/login sits outside it,
            // so generating it here throws UrlGenerationException for a missing
            // parameter. /admin picks the site: it redirects straight through
            // when there is only one, and shows the picker otherwise.
            $this->redirect(route('admin.hub'), navigate: true);

            return;
        }

        $this->addError('email', 'These credentials do not match our records.');
    }

    public function render()
    {
        return view('livewire.admin.login');
    }
}
