<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class OAuthToken extends Model
{
    protected $table = 'oauth_tokens';

    protected $fillable = [
        'provider',
        'access_token',
        'refresh_token',
        'access_token_expires_at',
        'refresh_token_expires_at',
        'scopes',
        'granted_by_email',
    ];

    protected function casts(): array
    {
        return [
            'access_token_expires_at' => 'datetime',
            'refresh_token_expires_at' => 'datetime',
            'scopes' => 'array',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Encrypted accessors for tokens
    |--------------------------------------------------------------------------
    */

    public function getAccessTokenAttribute(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return $value; // fallback: stored in plain text during migration
        }
    }

    public function setAccessTokenAttribute(?string $value): void
    {
        $this->attributes['access_token'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getRefreshTokenAttribute(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return $value;
        }
    }

    public function setRefreshTokenAttribute(?string $value): void
    {
        $this->attributes['refresh_token'] = $value ? Crypt::encryptString($value) : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Check if the stored access token is still valid (with a 2-minute buffer).
     */
    public function hasValidAccessToken(): bool
    {
        return $this->access_token
            && $this->access_token_expires_at
            && $this->access_token_expires_at->isFuture();
    }

    /**
     * Retrieve the token row for a given provider.
     */
    public static function forProvider(string $provider): ?self
    {
        return static::where('provider', $provider)->first();
    }

    /**
     * Store or update tokens for a provider.
     */
    public static function storeTokens(
        string $provider,
        string $refreshToken,
        ?string $accessToken = null,
        ?int $expiresIn = null,
        ?string $email = null,
        ?array $scopes = null,
    ): self {
        return static::updateOrCreate(
            ['provider' => $provider],
            array_filter([
                'refresh_token' => $refreshToken,
                'access_token' => $accessToken,
                'access_token_expires_at' => $expiresIn ? now()->addSeconds($expiresIn - 120) : null,
                'granted_by_email' => $email,
                'scopes' => $scopes,
            ], fn ($v) => $v !== null),
        );
    }
}
