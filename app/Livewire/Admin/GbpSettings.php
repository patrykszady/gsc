<?php

namespace App\Livewire\Admin;

use App\Services\GoogleBusinessProfileService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.admin')]
#[Title('Google Business Profile')]
class GbpSettings extends Component
{
    public bool $isConnected = false;
    public bool $needsReauth = false;
    public ?string $connectedEmail = null;
    public ?string $connectedAt = null;
    public ?string $tokenExpiresAt = null;
    public ?string $healthStatus = null;
    public ?string $healthError = null;

    public function mount(): void
    {
        $this->refreshStatus();
    }

    public function refreshStatus(): void
    {
        $service = app(GoogleBusinessProfileService::class);
        $stored = $service->getStoredToken();

        $this->isConnected = $service->hasRefreshToken();
        $this->connectedEmail = $stored?->granted_by_email;
        $this->connectedAt = $stored?->created_at?->diffForHumans();
        $this->tokenExpiresAt = $stored?->access_token_expires_at?->diffForHumans();
        $this->needsReauth = false;

        // Quick health check if connected
        if ($this->isConnected) {
            $result = $service->listMedia(null, 1);
            if ($result === null) {
                $error = $service->getLastError();
                $this->healthStatus = 'error';
                $this->healthError = $error['error_description'] ?? $error['message'] ?? 'Unknown error';
                $this->needsReauth = (bool) ($error['reauthorization_required'] ?? false);
            } else {
                $this->healthStatus = 'ok';
                $this->healthError = null;
            }
        }
    }

    public function connect(): mixed
    {
        $service = app(GoogleBusinessProfileService::class);
        $redirectUri = route('admin.gbp.callback');
        $url = $service->getOAuthUrl($redirectUri);

        return $this->redirect($url, navigate: false);
    }

    public function disconnect(): void
    {
        $service = app(GoogleBusinessProfileService::class);
        $service->disconnect();

        session()->flash('gbp-success', 'Google Business Profile disconnected.');
        $this->refreshStatus();
    }

    public function render()
    {
        return view('livewire.admin.gbp-settings');
    }
}
